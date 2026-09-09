<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\SourceScope;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/Edges/BladeEdges.php
 *
 * Deviation from Pest: `arm()` takes the already-resolved Illuminate application container
 * (`$app`) instead of resolving `Container::getInstance()` itself, for the same reason as
 * `TableTracker` (SPEC.md §10).
 *
 * Second deviation, not in Pest: `arm()` also refuses to link a view whose path is the
 * application's own COMPILED artifact rather than its source template. For an ordinary
 * `resources/views/*.blade.php` render, `$view->getPath()` **is** the source file and this
 * stays a correct, load-bearing edge (`tests/Integration/LaravelLiteScenariosTest.php`). But
 * `Blade::render($string)` and inline/anonymous components give the same view object a path
 * under `view.compiled` instead — Laravel itself treats that path as disposable
 * (`BladeCompiler::render()` ends with `@unlink($view->getPath())` when `$deleteCachedView`
 * is set, verified in Laravel v13.25.0,
 * vendor/laravel/framework/src/Illuminate/View/Compilers/BladeCompiler.php:340-366), and under
 * Laravel Parallel Testing that path additionally carries the per-worker token
 * (`view.compiled` is rewritten per worker process). Measured on a real 9056-test project:
 * two identical `record --parallel=8` passes disagreed on 189 of 726 tests' dependency sets
 * — 421 distinct files moving between passes, every one of them `git check-ignore`d and none
 * of them tracked — entirely from this one path.
 */
final class BladeTracker
{
    /** Registers a global view composer on $app that links every rendered Blade view's path. */
    public static function arm(object $app, Recorder $recorder, string $projectRoot): void
    {
        if (! method_exists($app, 'bound') || ! method_exists($app, 'make') || ! $app->bound('view')) {
            return;
        }

        /** @var object $factory */
        $factory = $app->make('view');

        if (! method_exists($factory, 'composer')) {
            return;
        }

        $factory->composer('*', static function (object $view) use ($app, $recorder, $projectRoot): void {
            if (! method_exists($view, 'getPath')) {
                return;
            }

            /** @var mixed $path */
            $path = $view->getPath();

            if (! is_string($path) || $path === '' || self::isCompiledViewPath($app, $projectRoot, $path)) {
                return;
            }

            $recorder->linkSource($path);
        });
    }

    /**
     * True when $path is the application's compiled-view cache rather than a source
     * template. `config('view.compiled')` is read fresh on every call — never cached at
     * `arm()` time — because Laravel Parallel Testing rewrites that config value per worker
     * process during its own setup, which runs after `arm()`'s once-per-Container
     * bootstrapping ({@see \Manuglopez\Replay\Laravel\Subscribers\ArmLaravelTrackersOnPrepared}):
     * a value captured once could be stale for the worker that actually renders a given view,
     * or simply absent before the first worker callback has run at all.
     */
    private static function isCompiledViewPath(object $app, string $projectRoot, string $path): bool
    {
        $compiled = self::compiledViewDirectory($app);

        if ($compiled !== null && self::isWithin($path, $compiled)) {
            return true;
        }

        // Belt-and-braces for when config('view.compiled') itself cannot be read (no
        // `config` binding, a container that only partially resembles Laravel's): refuse the
        // same fixed directories Record\SourceScope already excludes coverage-derived edges
        // from (bootstrap/cache, storage/framework, storage/logs), reusing its own helper
        // rather than duplicating that literal list here.
        return SourceScope::isNestedNoisePath($projectRoot, $path);
    }

    private static function compiledViewDirectory(object $app): ?string
    {
        if (! method_exists($app, 'bound') || ! method_exists($app, 'make') || ! $app->bound('config')) {
            return null;
        }

        /** @var object $config */
        $config = $app->make('config');

        if (! method_exists($config, 'get')) {
            return null;
        }

        /** @var mixed $compiled */
        $compiled = $config->get('view.compiled');

        return (is_string($compiled) && $compiled !== '') ? $compiled : null;
    }

    private static function isWithin(string $path, string $directory): bool
    {
        $normalisedPath = rtrim(str_replace('\\', '/', $path), '/');
        $normalisedDir = rtrim(str_replace('\\', '/', $directory), '/');

        if ($normalisedDir === '') {
            return false;
        }

        return $normalisedPath === $normalisedDir || str_starts_with($normalisedPath, $normalisedDir . '/');
    }
}
