<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Support;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\ProjectKey;

/**
 * Shared helpers for tests/Integration/*: resolving the state directory a
 * `FixtureProject::replay()` call lands under (same resolution the wrapper itself
 * performs, docs/INTERNALS.md `Cache\StateDirectory`), loading the graph it writes
 * there, and picking apart the wrapper's own summary line (SPEC.md §11).
 */
final class ReplayAssert
{
    public static function stateDir(FixtureProject $fixture): string
    {
        return rtrim($fixture->homeDir(), '/') . '/.phpunit-replay/' . ProjectKey::for($fixture->root());
    }

    public static function graphPath(FixtureProject $fixture): string
    {
        return self::stateDir($fixture) . '/graph.json';
    }

    public static function loadGraph(FixtureProject $fixture): ?Graph
    {
        $path = self::graphPath($fixture);

        if (! is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);

        if ($json === false) {
            return null;
        }

        return Graph::decode($json, $fixture->root());
    }

    /** The wrapper's own summary line: the last non-empty line of stdout. */
    public static function lastLine(string $stdout): string
    {
        $lines = array_values(array_filter(explode("\n", rtrim($stdout)), static fn (string $l): bool => $l !== ''));

        return $lines === [] ? '' : (string) end($lines);
    }

    /** -1 when the summary line does not contain an "N executed" segment. */
    public static function executedCount(string $stdout): int
    {
        return self::firstMatch('/(\d+) executed/', $stdout);
    }

    public static function replayedCount(string $stdout): int
    {
        return self::firstMatch('/(\d+) replayed/', $stdout);
    }

    public static function affectedCount(string $stdout): int
    {
        return self::firstMatch('/\((\d+) affected/', $stdout);
    }

    public static function uncachedCount(string $stdout): int
    {
        return self::firstMatch('/(\d+) uncached\)/', $stdout);
    }

    private static function firstMatch(string $pattern, string $subject): int
    {
        if (preg_match($pattern, $subject, $matches) === 1) {
            return (int) $matches[1];
        }

        return -1;
    }
}
