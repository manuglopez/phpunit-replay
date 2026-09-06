<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Manuglopez\Replay\Record\Recorder;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/Edges/BladeEdges.php
 *
 * Deviation from Pest: `arm()` takes the already-resolved Illuminate application container
 * (`$app`) instead of resolving `Container::getInstance()` itself, for the same reason as
 * `TableTracker` (SPEC.md §10).
 */
final class BladeTracker
{
    /** Registers a global view composer on $app that links every rendered Blade view's path. */
    public static function arm(object $app, Recorder $recorder): void
    {
        if (! method_exists($app, 'bound') || ! method_exists($app, 'make') || ! $app->bound('view')) {
            return;
        }

        /** @var object $factory */
        $factory = $app->make('view');

        if (! method_exists($factory, 'composer')) {
            return;
        }

        $factory->composer('*', static function (object $view) use ($recorder): void {
            if (! method_exists($view, 'getPath')) {
                return;
            }

            /** @var mixed $path */
            $path = $view->getPath();

            if (is_string($path) && $path !== '') {
                $recorder->linkSource($path);
            }
        });
    }
}
