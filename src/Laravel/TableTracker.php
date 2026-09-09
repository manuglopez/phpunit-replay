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

        /** @var callable $listen */
        $listen = [$db, 'listen'];

        $listen(static function (object $query) use ($recorder): void {
            if (! property_exists($query, 'sql')) {
                return;
            }

            /** @var mixed $sql */
            $sql = $query->sql;

            if (! is_string($sql) || $sql === '') {
                return;
            }

            foreach (TableExtractor::fromSql($sql) as $table) {
                $recorder->linkTable($table);
            }
        });
    }
}
