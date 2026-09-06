<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Manuglopez\Replay\Config;

/**
 * Whether the Laravel integration (SPEC.md §10, docs/INTERNALS.md "Laravel") should be
 * wired up for a project: never when `config.laravel === 'off'`; otherwise autodetected
 * from a file that is always readable, an `artisan` script at the project root.
 *
 * Deliberately file/config-based only, never `class_exists`: this method is called from
 * two different processes, and only one of them is guaranteed to have Illuminate loaded.
 *
 *  - `Console\Runner\RunPipeline` (the wrapper) and `Console\Commands\ExplainCommand` run
 *    as a separate PHP process, invoked directly (`bin/phpunit-replay`) or through the
 *    project's `vendor/bin/phpunit-replay` shim, before PHPUnit — and therefore the
 *    project's own PHPUnit bootstrap — ever runs. Depending on how that process was
 *    started, its autoloader may or may not resolve `Illuminate\Container\Container`, so
 *    relying on `class_exists()` there would be unreliable at best.
 *  - `PHPUnit\ReplayExtension`/`PHPUnit\ReplayState::bootInProcess()` run inside the
 *    PHPUnit process itself, where the project's autoloader (and therefore Illuminate, on
 *    a Laravel project) is always loaded — but only need to know "is this a Laravel
 *    project", which the file check already answers correctly.
 *
 * `LaravelIntegration::shouldArm()` is the one place that additionally requires
 * `class_exists(\Illuminate\Container\Container::class)`: it gates the runtime trackers
 * (`TableTracker`/`BladeTracker`), which can only attach to an actual, already-booted
 * `Illuminate\Container\Container` instance and therefore only ever run inside the
 * PHPUnit process.
 */
final class LaravelDetector
{
    public static function enabled(string $projectRoot, Config $config): bool
    {
        if ($config->laravel === 'off') {
            return false;
        }

        return is_file(rtrim($projectRoot, '/') . '/artisan');
    }
}
