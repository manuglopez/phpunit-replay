<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Manuglopez\Replay\Record\Recorder;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/TableTracker.php
 *
 * Deviation from Pest: `arm()` takes the already-resolved Illuminate application container
 * (`$app`) instead of resolving `Container::getInstance()` itself — the "once per Container
 * instance" bookkeeping lives in `Laravel\Subscribers\ArmLaravelTrackersOnPrepared` (SPEC.md
 * §10). `$app` is untyped `object` on purpose: the package must not depend on `illuminate/*`,
 * so every call into it is dynamic and reflection-guarded.
 */
final class TableTracker
{
    /** Registers a `db` query listener on $app that links every table a query touches. */
    public static function arm(object $app, Recorder $recorder): void
    {
        if (! method_exists($app, 'bound') || ! method_exists($app, 'make')) {
            return;
        }

        if (! $app->bound('db')) {
            return;
        }

        /** @var object $db */
        $db = $app->make('db');

        if (! is_callable([$db, 'listen'])) {
            return;
        }

        $state = self::trackBuilding($app);

        /** @var callable $listen */
        $listen = [$db, 'listen'];
        $listen(static function (object $query) use ($recorder, $state): void {
            if (! property_exists($query, 'sql')) {
                return;
            }

            /** @var mixed $sql */
            $sql = $query->sql;

            if (! is_string($sql) || $sql === '') {
                return;
            }

            // Inside a migration or a seeder: the database every later test of the process
            // runs on (`RefreshDatabase` migrates and seeds once per process, in whichever
            // test comes first), not only this test's own reads and writes.
            $building = $state->migrating > 0 || ($state->seeding && self::inSeeder());
            $prefix = $building ? TableExtractor::BOOTSTRAP : '';

            foreach (TableExtractor::fromSql($sql) as $table) {
                $recorder->linkTable($table === TableExtractor::UNKNOWN ? $table : $prefix . $table);
            }
        });
    }

    /**
     * Whether `$app` is building its database right now, from what Laravel says: the
     * migrator's `MigrationStarted`/`MigrationEnded` events (a data migration's writes), and
     * whether a seeder was ever resolved from the container (`db:seed`, `$seed`/`$seeder`,
     * `$this->seed()`, `Seeder::call()` all resolve one). The testing traits call `migrate`
     * and `db:seed` through Artisan without any console event (those are dispatched only
     * with `WithConsoleEvents`), and a seeder fires none of its own: once one was resolved, a
     * query looks for a seeder frame on its stack. Until then (and in a process whose first
     * test seeded, for every later application) no query pays for a stack walk.
     */
    private static function trackBuilding(object $app): DatabaseBuildState
    {
        $state = new DatabaseBuildState();

        if (method_exists($app, 'bound') && method_exists($app, 'make') && $app->bound('events')) {
            /** @var object $events */
            $events = $app->make('events');

            if (method_exists($events, 'listen')) {
                $events->listen('Illuminate\\Database\\Events\\MigrationStarted', static function () use ($state): void {
                    $state->migrating++;
                });
                $events->listen('Illuminate\\Database\\Events\\MigrationEnded', static function () use ($state): void {
                    $state->migrating = max(0, $state->migrating - 1);
                });
            }
        }

        if (method_exists($app, 'resolving')) {
            $app->resolving('Illuminate\\Database\\Seeder', static function () use ($state): void {
                $state->seeding = true;
            });
        }

        return $state;
    }

    /** A seeder runs on the call stack (the whole stack: no depth cut-off). */
    private static function inSeeder(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? null;

            if ($class !== null && (str_starts_with($class, 'Illuminate\\Database\\Seeder') || str_starts_with($class, 'Illuminate\\Database\\Console\\Seeds\\'))) {
                return true;
            }
        }

        return false;
    }
}
