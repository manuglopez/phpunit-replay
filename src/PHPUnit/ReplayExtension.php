<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Console\Runner\Warnings;
use Manuglopez\Replay\PHPUnit\Subscribers\CollectResultOnPreparationStarted;
use Manuglopez\Replay\PHPUnit\Subscribers\FlushOnExecutionFinished;
use Manuglopez\Replay\PHPUnit\Subscribers\MarkTruncatedOnExecutionAborted;
use Manuglopez\Replay\PHPUnit\Subscribers\PersistInProcessOnExecutionFinished;
use Manuglopez\Replay\PHPUnit\Subscribers\PrintSummaryOnApplicationFinished;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordAssertionsOnFinished;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordConsideredRisky;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordDeprecationTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordErrored;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordFailed;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordMarkedIncomplete;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordNotCacheableOnPreparationStarted;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordNoticeTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPassed;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPhpDeprecationTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPhpNoticeTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPhpWarningTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordRepeatOrRetryNotCacheableOnPreparationStarted;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordSkipped;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordWarningTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\StartRecordingOnPreparationStarted;
use Manuglopez\Replay\PHPUnit\Subscribers\StopRecordingOnFinished;
use Manuglopez\Replay\Record\CoverageDriver;
use Manuglopez\Replay\Record\CoverageSnapshots;
use Manuglopez\Replay\Record\DriverDetector;
use Manuglopez\Replay\Record\PcovDriver;
use Manuglopez\Replay\Record\PiggybackCoverageDriver;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\SourceScope;
use PHPUnit\Runner\CodeCoverage as PhpUnitCodeCoverage;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\Util\ExcludeList;
use Throwable;

/**
 * PHPUnit extension bootstrap (SPEC.md §6.1). Two ways in:
 *
 *  - The wrapper set `PHPUNIT_REPLAY_MODE`: this process only records what it is told to
 *    and leaves a run partial behind for the wrapper to apply (docs/INTERNALS.md
 *    "Extension behaviour (phase 1)").
 *  - No wrapper: the extension decides the mode itself, replays cached results through
 *    the `Replayable` trait, writes the graph at the end of the run and prints its own
 *    summary line (docs/INTERNALS.md "In-process replay").
 */
final class ReplayExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (self::env('PHPUNIT_REPLAY') === '0') {
            return;
        }

        self::hideOwnFramesFromStackTraces();

        $rawMode = self::env('PHPUNIT_REPLAY_MODE');

        if ($rawMode === null) {
            $this->bootstrapInProcess($configuration, $facade, $parameters);

            return;
        }

        $mode = Mode::tryFromEnv($rawMode);

        if ($mode === null) {
            self::warn(sprintf('unknown PHPUNIT_REPLAY_MODE value "%s"', $rawMode));

            return;
        }

        if ($mode === Mode::Off || $mode === Mode::Replay) {
            // `replay` is the wrapper telling us it drives replay itself (it filters the
            // suite down to the run list); there is nothing for this process to do.
            return;
        }

        $root = self::resolveRoot($configuration);
        $stateDir = StateDirectory::resolve(self::env('PHPUNIT_REPLAY_STATE_DIR'), $root);
        $runId = self::env('PHPUNIT_REPLAY_RUN_ID') ?? (date('Ymd-His') . '-' . bin2hex(random_bytes(3)));
        $debug = self::env('PHPUNIT_REPLAY_DEBUG') === '1';

        $driver = null;

        if ($mode->recordsEdges()) {
            $scope = SourceScope::fromProjectRoot($root, $configuration);

            if ($configuration->hasCoverageReport()) {
                // PHPUnit's own coverage collection is about to start: the raw pcov/xdebug
                // driver would clash with it (SPEC.md §2.4 — both want to drive the same
                // extension's start/stop cycle, which is exactly why `--no-coverage` exists).
                // This process instead reads the coverage PHPUnit already collects per test
                // (Record\PiggybackCoverageDriver).
                $driver = new PiggybackCoverageDriver($scope);
            } else {
                $driver = DriverDetector::detect($scope);

                if ($driver === null) {
                    self::warn('no coverage driver available inside PHPUnit (pcov.enabled=1 or xdebug.mode=coverage); recording results only');
                    $mode = Mode::ResultsOnly;
                }
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

        $this->registerSubscribers($facade, $mode, $driver, $root, $configuration, $stateDir);
    }

    /**
     * No wrapper: `Config::load()` for the project file and environment, then the
     * extension `<parameter>` block on top of it, then the environment again (it always
     * wins, docs/INTERNALS.md "Mode decision without wrapper env").
     */
    private function bootstrapInProcess(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $root = self::resolveRoot($configuration);
        $config = self::withExtensionParameters(Config::load($root), $parameters)->mergeEnv($_SERVER);

        if ($config->mode === 'off') {
            return;
        }

        // No wrapper in front of this process (the extension is registered directly in the
        // user's own phpunit.xml), so this is the only place that ever sees --repeat/--retry
        // for this entry point: RunPipeline's own check (ConfigurationReader::
        // repeatOrRetryRequested()) never runs. Same hazard, same fix: skip registering any
        // subscriber, which leaves this run exactly as if the extension were not there at
        // all (no replay, no recording), same as PHPUNIT_REPLAY=0.
        if ((new ConfigurationReader($configuration))->repeatOrRetryRequested()) {
            Warnings::warn('--repeat/--retry requested: the test id it changes results on is not stable across runs, running without replay or recording');

            return;
        }

        $mode = ReplayState::bootInProcess($config, $configuration);

        if ($mode === Mode::Off) {
            return;
        }

        $this->registerInProcessSubscribers($facade, $mode, ReplayState::root());
    }

    private function registerInProcessSubscribers(Facade $facade, Mode $mode, string $root): void
    {
        $this->registerResultSubscribers($facade);

        $recorder = ReplayState::recorder();

        if ($recorder !== null) {
            $facade->registerSubscribers(
                new StartRecordingOnPreparationStarted($recorder),
                new StopRecordingOnFinished($recorder),
            );
        }

        $facade->registerSubscribers(
            new MarkTruncatedOnExecutionAborted(ReplayState::runWriter()),
            new PersistInProcessOnExecutionFinished(),
            new PrintSummaryOnApplicationFinished(),
        );

        Warnings::debug(sprintf('extension: in-process mode=%s root=%s', $mode->value, $root));
    }

    private function registerResultSubscribers(Facade $facade): void
    {
        $collector = ReplayState::collector();

        $facade->registerSubscribers(
            new CollectResultOnPreparationStarted($collector),
            new RecordNotCacheableOnPreparationStarted(ReplayState::notCacheableCollector(), ReplayState::root()),
            new RecordRepeatOrRetryNotCacheableOnPreparationStarted(ReplayState::notCacheableCollector()),
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
    }

    private function registerSubscribers(Facade $facade, Mode $mode, ?CoverageDriver $driver, string $root, Configuration $configuration, string $stateDir): void
    {
        $this->registerResultSubscribers($facade);

        $collector = ReplayState::collector();
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

        // Coverage snapshots (SPEC.md §3.2 last paragraph): only when this run actually
        // records edges and the user asked PHPUnit for `--coverage-php` specifically (the
        // only report format Report\CoverageMerger folds snapshots back into).
        $coverageSnapshots = ($mode->recordsEdges() && $configuration->hasCoveragePhp())
            ? static function () use ($collector, $stateDir, $root): array {
                if (! PiggybackCoverageDriver::available()) {
                    return [];
                }

                return (new CoverageSnapshots($stateDir))->capture(
                    $collector->all(),
                    PhpUnitCodeCoverage::instance()->codeCoverage(),
                    $root,
                );
            }
        : null;

        $facade->registerSubscribers(
            new FlushOnExecutionFinished(ReplayState::runWriter(), $recorderForFlush, $collector, $meta, ReplayState::notCacheableCollector(), $coverageSnapshots),
            new MarkTruncatedOnExecutionAborted(ReplayState::runWriter()),
        );

        // Optional Laravel integration (SPEC.md §10): entry points live in Laravel\LaravelIntegration.
        if (class_exists(\Manuglopez\Replay\Laravel\LaravelIntegration::class) && \Manuglopez\Replay\Laravel\LaravelIntegration::shouldArm($root)) {
            $facade->registerSubscribers(...\Manuglopez\Replay\Laravel\LaravelIntegration::subscribers($recorderForFlush));
        }
    }

    /**
     * Applies the `<parameter>` block on top of `$config`, but only the entries the user
     * actually declared with a non-empty value: an absent parameter must not overwrite
     * what `phpunit-replay.php` said.
     */
    private static function withExtensionParameters(Config $config, ParameterCollection $parameters): Config
    {
        $overrides = [];

        foreach (['stateDir', 'remote', 'remoteToken', 'defaultBranch'] as $name) {
            $value = self::parameter($parameters, $name);

            if ($value !== null) {
                $overrides[$name] = $value;
            }
        }

        $mode = self::parameter($parameters, 'mode');

        if ($mode !== null && Config::isKnownMode($mode)) {
            $overrides['mode'] = $mode;
        }

        /** @var array{stateDir?: string, remote?: string, remoteToken?: string, defaultBranch?: string, mode?: string} $overrides */
        return $overrides === [] ? $config : $config->with($overrides);
    }

    private static function parameter(ParameterCollection $parameters, string $name): ?string
    {
        if (! $parameters->has($name)) {
            return null;
        }

        $value = $parameters->get($name);

        return $value === '' ? null : $value;
    }

    /**
     * The `Replayable` trait sits between `runTest()` and the test method, so without
     * this every failure inside a suite that uses the trait would carry a
     * `Replayable.php` frame in its stack trace.
     */
    private static function hideOwnFramesFromStackTraces(): void
    {
        try {
            ExcludeList::addDirectory(dirname(__DIR__));
        } catch (Throwable) {
            // Cosmetic only: a PHPUnit build without the exclude list still works.
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
