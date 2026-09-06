<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\PHPUnit\Subscribers\CollectResultOnPreparationStarted;
use Manuglopez\Replay\PHPUnit\Subscribers\FlushOnExecutionFinished;
use Manuglopez\Replay\PHPUnit\Subscribers\MarkTruncatedOnExecutionAborted;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordAssertionsOnFinished;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordConsideredRisky;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordDeprecationTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordErrored;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordFailed;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordMarkedIncomplete;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordNoticeTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPassed;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPhpDeprecationTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPhpNoticeTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPhpWarningTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordSkipped;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordWarningTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\StartRecordingOnPreparationStarted;
use Manuglopez\Replay\PHPUnit\Subscribers\StopRecordingOnFinished;
use Manuglopez\Replay\Record\CoverageDriver;
use Manuglopez\Replay\Record\DriverDetector;
use Manuglopez\Replay\Record\PcovDriver;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\SourceScope;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit extension bootstrap. Phase 1 behaviour only (docs/INTERNALS.md "Extension
 * behaviour (phase 1)"): registers the result-collecting subscribers always, the
 * edge-recording ones when the mode calls for it and a coverage driver is available,
 * and always the run-partial flush/truncation subscribers. In-process replay
 * (`Mode::Replay`, the `Replayable` trait) is phase 2.
 *
 * Reads its configuration straight from environment variables set by the wrapper
 * (`PHPUNIT_REPLAY_*`) rather than through `Config`, which is being built separately.
 */
final class ReplayExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (self::env('PHPUNIT_REPLAY') === '0') {
            return;
        }

        // TODO(config): Config::fromExtensionParameters($parameters)->mergeEnv($_SERVER)
        $rawMode = self::env('PHPUNIT_REPLAY_MODE');

        if ($rawMode === null) {
            return;
        }

        $mode = Mode::tryFromEnv($rawMode);

        if ($mode === null) {
            self::warn(sprintf('unknown PHPUNIT_REPLAY_MODE value "%s"', $rawMode));

            return;
        }

        if ($mode === Mode::Off) {
            return;
        }

        if ($mode === Mode::Replay) {
            self::warn('in-process replay (PHPUNIT_REPLAY_MODE=replay) is not implemented yet; extension disabled');

            return;
        }

        $root = self::resolveRoot($configuration);
        $stateDir = StateDirectory::resolve(self::env('PHPUNIT_REPLAY_STATE_DIR'), $root);
        $runId = self::env('PHPUNIT_REPLAY_RUN_ID') ?? (date('Ymd-His') . '-' . bin2hex(random_bytes(3)));
        $debug = self::env('PHPUNIT_REPLAY_DEBUG') === '1';

        $driver = null;

        if ($mode->recordsEdges()) {
            $scope = SourceScope::fromProjectRoot($root, $configuration);
            $driver = DriverDetector::detect($scope);

            if ($driver === null) {
                self::warn('no coverage driver available inside PHPUnit (pcov.enabled=1 or xdebug.mode=coverage); recording results only');
                $mode = Mode::ResultsOnly;
            }
        }

        ReplayState::boot($mode, $root, $stateDir, $runId, $driver);

        if ($debug) {
            fwrite(STDERR, sprintf(
                "[replay] extension: mode=%s driver=%s root=%s runDir=%s\n",
                $mode->value,
                $driver?->name() ?? 'none',
                $root,
                $stateDir . '/runs/' . $runId,
            ));
        }

        $this->registerSubscribers($facade, $mode, $driver, $root);
    }

    private function registerSubscribers(Facade $facade, Mode $mode, ?CoverageDriver $driver, string $root): void
    {
        $collector = ReplayState::collector();

        $facade->registerSubscribers(
            new CollectResultOnPreparationStarted($collector),
            new RecordPassed($collector),
            new RecordFailed($collector),
            new RecordErrored($collector),
            new RecordSkipped($collector),
            new RecordMarkedIncomplete($collector),
            new RecordConsideredRisky($collector),
            new RecordWarningTriggered($collector),
            new RecordPhpWarningTriggered($collector),
            new RecordNoticeTriggered($collector),
            new RecordPhpNoticeTriggered($collector),
            new RecordDeprecationTriggered($collector),
            new RecordPhpDeprecationTriggered($collector),
            new RecordAssertionsOnFinished($collector),
        );

        $recorder = ReplayState::recorder();

        if ($recorder !== null) {
            $facade->registerSubscribers(
                new StartRecordingOnPreparationStarted($recorder),
                new StopRecordingOnFinished($recorder),
            );
        }

        // RunWriter::flush()/FlushOnExecutionFinished both require a concrete Recorder
        // even in results-only mode (where nothing is ever recorded into it): a Recorder
        // that never has beginTest()/endTest() called on it simply yields empty edges.
        $recorderForFlush = $recorder ?? new Recorder(self::placeholderDriver());
        $driverName = $driver?->name() ?? 'none';

        $meta = static fn (): array => [
            'driver' => $driverName,
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'os' => PHP_OS_FAMILY,
            'mode' => $mode->value,
            'startedAt' => ReplayState::startedAt(),
            'finishedAt' => microtime(true),
            'fingerprint' => Fingerprint::compute($root, $driverName),
        ];

        $facade->registerSubscribers(
            new FlushOnExecutionFinished(ReplayState::runWriter(), $recorderForFlush, $collector, $meta),
            new MarkTruncatedOnExecutionAborted(ReplayState::runWriter()),
        );

        // Optional Laravel integration (SPEC.md §10): entry points live in Laravel\LaravelIntegration.
        if (class_exists(\Manuglopez\Replay\Laravel\LaravelIntegration::class) && \Manuglopez\Replay\Laravel\LaravelIntegration::shouldArm($root)) {
            $facade->registerSubscribers(...\Manuglopez\Replay\Laravel\LaravelIntegration::subscribers($recorderForFlush));
        }
    }

    private static function resolveRoot(Configuration $configuration): string
    {
        $fromEnv = self::env('PHPUNIT_REPLAY_ROOT');

        if ($fromEnv !== null) {
            return $fromEnv;
        }

        if ($configuration->hasConfigurationFile()) {
            return dirname($configuration->configurationFile());
        }

        return getcwd() ?: '.';
    }

    /** Never actually started/stopped: a type-safe no-op stand-in. */
    private static function placeholderDriver(): CoverageDriver
    {
        return new PcovDriver(new SourceScope([], []));
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }

    private static function warn(string $message): void
    {
        fwrite(STDERR, 'phpunit-replay: ' . $message . PHP_EOL);
    }
}
