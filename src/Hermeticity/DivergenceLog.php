<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Hermeticity;

use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Json;

/**
 * `phpunit-replay verify` (SPEC.md §12.2): persists `<stateDir>/divergence.json`
 * `{"runs": n, "entries": [{testId, k, cached, actual, sha, at}, ...]}`, appending on
 * every verify run and keeping only the most recent 500 entries.
 *
 * @phpstan-type DivergenceEntry array{testId: string, k: string, cached: int, actual: int, sha: ?string, at: int}
 */
final class DivergenceLog
{
    private const MAX_ENTRIES = 500;

    /**
     * @param  list<DivergenceEntry>  $entries  this run's new divergences
     * @return array{runs: int, divergences: int} lifetime totals after this append
     */
    public static function append(string $stateDir, array $entries): array
    {
        $data = self::read($stateDir);
        $runs = $data['runs'] + 1;
        $all = [...$data['entries'], ...$entries];

        if (count($all) > self::MAX_ENTRIES) {
            $all = array_slice($all, -self::MAX_ENTRIES);
        }

        $json = Json::encodePretty([
            'runs' => $runs,
            'entries' => $all,
        ]);

        if ($json !== null) {
            AtomicFile::write(self::path($stateDir), $json);
        }

        return ['runs' => $runs, 'divergences' => count($all)];
    }

    /** @return array{runs: int, entries: list<DivergenceEntry>} */
    public static function read(string $stateDir): array
    {
        $raw = AtomicFile::read(self::path($stateDir));

        if ($raw === null) {
            return ['runs' => 0, 'entries' => []];
        }

        $decoded = Json::decodeArray($raw);

        if ($decoded === null) {
            return ['runs' => 0, 'entries' => []];
        }

        $runs = is_int($decoded['runs'] ?? null) ? $decoded['runs'] : 0;
        $entries = [];

        foreach (is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [] as $entry) {
            $normalised = self::normalizeEntry($entry);

            if ($normalised !== null) {
                $entries[] = $normalised;
            }
        }

        return ['runs' => $runs, 'entries' => $entries];
    }

    /** @return DivergenceEntry|null */
    private static function normalizeEntry(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $testId = $entry['testId'] ?? null;
        $key = $entry['k'] ?? null;
        $cached = $entry['cached'] ?? null;
        $actual = $entry['actual'] ?? null;

        if (! is_string($testId) || $testId === '' || ! is_string($key) || ! is_int($cached) || ! is_int($actual)) {
            return null;
        }

        $sha = $entry['sha'] ?? null;
        $at = $entry['at'] ?? null;

        return [
            'testId' => $testId,
            'k' => $key,
            'cached' => $cached,
            'actual' => $actual,
            'sha' => is_string($sha) ? $sha : null,
            'at' => is_int($at) ? $at : 0,
        ];
    }

    private static function path(string $stateDir): string
    {
        return rtrim($stateDir, '/') . '/divergence.json';
    }
}
