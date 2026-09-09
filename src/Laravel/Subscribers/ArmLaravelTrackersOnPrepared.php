<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Subscribers;

use Manuglopez\Replay\Laravel\BladeTracker;
use Manuglopez\Replay\Laravel\MigrationTables;
use Manuglopez\Replay\Laravel\TableTracker;
use Manuglopez\Replay\Laravel\UsesDatabaseCollector;
use Manuglopez\Replay\Record\Recorder;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Laravel integration entry point (SPEC.md §10). `Test\Prepared` fires after `setUp()`, so
 * the application is already booted by the time this runs. `TableTracker`/`BladeTracker` are
 * armed once per `Illuminate\Container\Container` instance, guarded by a marker binding
 * (`phpunit-replay.armed`) — re-booting the application between tests (a fresh
 * `RefreshDatabase`-less app, or Laravel's own test isolation) naturally re-arms them. On
 * every test, independently of arming, the test's class is checked for a database-refreshing
 * trait ({@see MigrationTables::usesDatabase()}) and, when present, its file is recorded into
 * the `UsesDatabaseCollector` for `LaravelIntegration::augment()`.
 */
final readonly class ArmLaravelTrackersOnPrepared implements PreparedSubscriber
{
    private const MARKER = 'phpunit-replay.armed';

    private const CONTAINER_CLASS = 'Illuminate\\Container\\Container';

    public function __construct(
        private Recorder $recorder,
        private UsesDatabaseCollector $usesDatabase,
        private string $projectRoot,
    ) {
    }

    public function notify(Prepared $event): void
    {
        $test = $event->test();

        if (! $test instanceof TestMethod) {
            return;
        }

        $this->armTrackers();

        if (MigrationTables::usesDatabase($test->className())) {
            $this->usesDatabase->add($test->file());
        }
    }

    private function armTrackers(): void
    {
        $containerClass = self::CONTAINER_CLASS;

        if (! class_exists($containerClass)) {
            return;
        }

        /** @var object $app */
        $app = $containerClass::getInstance();

        if (! method_exists($app, 'bound') || ! method_exists($app, 'instance')) {
            return;
        }

        if ($app->bound(self::MARKER)) {
            return;
        }

        $app->instance(self::MARKER, true);

        TableTracker::arm($app, $this->recorder);
        BladeTracker::arm($app, $this->recorder, $this->projectRoot);
    }
}
