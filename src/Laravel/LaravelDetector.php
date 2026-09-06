<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Manuglopez\Replay\Config;

/**
 * Whether the Laravel integration (SPEC.md §10) should be active for a project: never when
 * `config.laravel === 'off'`; otherwise autodetected — the Illuminate container class is
 * loaded and the project has an `artisan` file at its root.
 */
final class LaravelDetector
{
    public static function enabled(string $projectRoot, Config $config): bool
    {
        if ($config->laravel === 'off') {
            return false;
        }

        return class_exists(\Illuminate\Container\Container::class)
            && is_file(rtrim($projectRoot, '/') . '/artisan');
    }
}
