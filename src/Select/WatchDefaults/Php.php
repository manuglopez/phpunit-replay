<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select\WatchDefaults;

use Manuglopez\Replay\Select\WatchDefault;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/WatchDefaults/Php.php
 *
 * Generic PHPUnit defaults, always applicable (SPEC.md §7.2.6): env files, PHPUnit's own
 * configuration, docker-compose files, and per-test-directory fixtures/snapshots.
 */
final class Php implements WatchDefault
{
    public function applicable(string $projectRoot): bool
    {
        return true;
    }

    /** @param list<string> $testDirectories @return array<string, list<string>> */
    public function defaults(string $projectRoot, array $testDirectories): array
    {
        $patterns = [];

        foreach ($testDirectories as $dir) {
            $patterns['.env*'][] = $dir;
            $patterns['phpunit.xml*'][] = $dir;
            $patterns['docker-compose*.y*ml'][] = $dir;
            $patterns[$dir . '/**/Fixtures/**'] = [$dir];
            $patterns[$dir . '/**/__snapshots__/**'] = [$dir];
        }

        return $patterns;
    }
}
