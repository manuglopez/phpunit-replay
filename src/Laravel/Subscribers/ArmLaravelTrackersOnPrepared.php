<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Subscribers;

use Manuglopez\Replay\Console\Runner\Warnings;
use Manuglopez\Replay\Laravel\BladeTracker;
use Manuglopez\Replay\Laravel\MigrationTables;
use Manuglopez\Replay\Laravel\TableExtractor;
use Manuglopez\Replay\Laravel\TableTracker;
use Manuglopez\Replay\Laravel\UsesDatabaseCollector;
use Manuglopez\Replay\PHPUnit\TestMethodFile;
use Manuglopez\Replay\Record\Recorder;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Laravel integration entry point (SPEC.md §10). The trackers (`TableTracker`, `BladeTracker`)
 * are armed once per `Illuminate\Container\Container` instance, guarded by a marker binding
 * (`phpunit-replay.armed`) whose value says when: `early`, while Laravel bootstraps the test's
 * application, before `setUp()` runs a single testing trait
 * ({@see ArmLaravelTrackersOnPreparationStarted}, `Laravel\TrackersServiceProvider`), or
 * `late`, from a fallback. `Test\Prepared` fires after `setUp()`: here the trackers are armed
 * late for an application nothing armed yet, and a Laravel test case whose application was
 * NOT armed early records the table {@see TableExtractor::UNKNOWN}: its `setUp()` (a trait's
 * `setUp<Trait>()`, `RefreshDatabase`'s seeder, a ParallelTesting callback) may have touched
 * tables nobody saw, so the rules treat it as touching any table.
 *
 * On every test the class is checked for a database testing trait
 * ({@see MigrationTables::usesDatabase()}) and its file recorded into the
 * `UsesDatabaseCollector`; {@see ArmLaravelTrackersOnPreparationStarted} does the same before
 * `setUp()`, so a test whose `setUp()` throws (no `Prepared`) is still known to use one.
 */
final class ArmLaravelTrackersOnPrepared implements PreparedSubscriber
{
    private const MARKER = 'phpunit-replay.armed';

    private const CONTAINER_CLASS = 'Illuminate\\Container\\Container';

    /** Set at `PreparationStarted` for a Laravel test case: its application should be armed early. */
    private bool $expectEarly = false;

    private bool $warned = false;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly UsesDatabaseCollector $usesDatabase,
        private readonly string $projectRoot,
    ) {
    }

    public function notify(Prepared $event): void
    {
        $test = $event->test();

        if (! $test instanceof TestMethod) {
            return;
        }

        $app = $this->armTrackers();

        if ($this->expectEarly && ($app === null || $this->markerOf($app) !== 'early')) {
            $this->recorder->linkTable(TableExtractor::UNKNOWN);
            $this->warnDegradedOnce();
        }

        $this->expectEarly = false;
        $this->recordUsesDatabase($test);
    }

    /**
     * At each test's `PreparationStarted`: whether {@see self::notify()} should check that the
     * test's application was armed early (a Laravel test case). Set for every test, so that a
     * test whose `setUp()` threw (no `Prepared`) leaves nothing to the next one.
     */
    public function expectEarlyArming(bool $expect = true): void
    {
        $this->expectEarly = $expect;
    }

    /**
     * Once per process: a test could not be armed before its `setUp()`, so its tables are
     * recorded as unknown, and the most common cause is a cached configuration (Laravel then
     * skips the providers this package merges).
     */
    private function warnDegradedOnce(): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;
        $cached = is_file(rtrim($this->projectRoot, '/') . '/bootstrap/cache/config.php');

        Warnings::warn($cached
            ? 'Laravel\'s configuration is cached (bootstrap/cache/config.php): tables touched before a test\'s body are not tracked, every test is recorded as touching unknown tables; run `php artisan config:clear` before recording'
            : 'tables touched before a test\'s body could not be tracked (Laravel 10, or an application this package could not arm early): every such test is recorded as touching unknown tables');
    }

    public function recordUsesDatabase(Test $test): void
    {
        if ($test instanceof TestMethod && MigrationTables::usesDatabase($test->className())) {
            // The running class's file (TestMethodFile::of()), the same string Record\Recorder
            // keys the test's tables by (StartRecordingOnPreparationStarted).
            $this->usesDatabase->add(TestMethodFile::of($test));
        }
    }

    /**
     * Arms `$app` (the current container when null) once; `$early` says it happens while the
     * application bootstraps. Returns the container, or null when there is none.
     */
    public function armTrackers(?object $app = null, bool $early = false): ?object
    {
        $containerClass = self::CONTAINER_CLASS;

        if ($app === null) {
            if (! class_exists($containerClass)) {
                return null;
            }

            /** @var object $app */
            $app = $containerClass::getInstance();
        }

        if (! method_exists($app, 'bound') || ! method_exists($app, 'instance')) {
            return null;
        }

        if ($app->bound(self::MARKER)) {
            return $app;
        }

        $app->instance(self::MARKER, $early ? 'early' : 'late');

        TableTracker::arm($app, $this->recorder);
        BladeTracker::arm($app, $this->recorder, $this->projectRoot);

        return $app;
    }

    private function markerOf(object $app): ?string
    {
        if (! method_exists($app, 'bound') || ! method_exists($app, 'make') || ! $app->bound(self::MARKER)) {
            return null;
        }

        $marker = $app->make(self::MARKER);

        return is_string($marker) ? $marker : null;
    }
}
