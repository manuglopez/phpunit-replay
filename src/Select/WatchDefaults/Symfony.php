<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select\WatchDefaults;

use Manuglopez\Replay\Select\WatchDefault;
use Manuglopez\Replay\Support\Paths;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/WatchDefaults/Symfony.php
 *
 * Applicable when the project has a `config/bundles.php` file (detection by file, not by
 * Composer InstalledVersions — SPEC.md §7.2.6). Every pattern maps to every test directory.
 */
final class Symfony implements WatchDefault
{
    public function applicable(string $projectRoot): bool
    {
        return is_file(Paths::join($projectRoot, 'config/bundles.php'));
    }

    /** @param list<string> $testDirectories @return array<string, list<string>> */
    public function defaults(string $projectRoot, array $testDirectories): array
    {
        $patterns = [
            'config/**',
            'migrations/**',
            'templates/**',
            'translations/**',
        ];

        $result = [];

        foreach ($patterns as $pattern) {
            $result[$pattern] = $testDirectories;
        }

        return $result;
    }
}
