<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Json;

/**
 * Read side of a run partial written by RunWriter::flush(). Defensive: any missing or
 * undecodable required file makes load() return null; edges/tables default to [] when
 * their file is missing or undecodable (a partial can legitimately have no edges yet).
 */
final readonly class RunPartial
{
    /**
     * @param array<string, list<string>> $edges rel => rel
     * @param array<string, array{status: int, message: string, time: float, assertions: int, file?: string}> $results file rel
     * @param array<string, list<string>> $tables
     * @param array<string, mixed> $meta
     * @param list<string> $usesDatabase project-relative test files using a database-refreshing trait (Laravel, SPEC.md §10)
     */
    public function __construct(
        public array $edges,
        public array $results,
        public array $tables,
        public array $meta,
        public array $usesDatabase = [],
    ) {
    }

    public static function load(string $runDir): ?self
    {
        $resultsJson = self::readMerged($runDir, 'results.json');
        $metaJson = self::readMerged($runDir, 'meta.json');

        if ($resultsJson === null || $metaJson === null) {
            return null;
        }

        $rawResults = Json::decodeArray($resultsJson);
        $rawMeta = Json::decodeArray($metaJson);

        if ($rawResults === null || $rawMeta === null) {
            return null;
        }

        $edgesJson = self::readMerged($runDir, 'edges.json');
        $rawEdges = $edgesJson !== null ? Json::decodeArray($edgesJson) : null;

        $tablesJson = self::readMerged($runDir, 'tables.json');
        $rawTables = $tablesJson !== null ? Json::decodeArray($tablesJson) : null;

        $usesDatabaseJson = self::readMerged($runDir, 'uses_database.json');
        $rawUsesDatabase = $usesDatabaseJson !== null ? Json::decodeArray($usesDatabaseJson) : null;

        return new self(
            self::normalizeStringListMap($rawEdges ?? []),
            self::normalizeResults($rawResults),
            self::normalizeStringListMap($rawTables ?? []),
            self::normalizeMeta($rawMeta),
            self::normalizeStringList($rawUsesDatabase ?? []),
        );
    }

    /**
     * Paratest support (SPEC.md §13): each worker flushes its own
     * `worker-<TEST_TOKEN>-<basename>` file instead of the plain `<basename>` (see
     * {@see \Manuglopez\Replay\Record\RunWriter::pathFor()}); this transparently merges them
     * back into a single logical file before the caller's normal decode/normalise pipeline
     * runs, so a single non-parallel `<basename>` and a merged Paratest run look identical
     * from here on. `meta.json` and `results.json` have their own merge rule; every other
     * basename (`edges.json`, `tables.json`, `uses_database.json`, and any future one written
     * the same way) is a generic union — no change needed here when one is added.
     */
    private static function readMerged(string $runDir, string $basename): ?string
    {
        $paths = self::workerPaths($runDir, $basename);

        if ($paths === []) {
            return AtomicFile::read($runDir . '/' . $basename);
        }

        $decoded = [];
        $any = false;

        foreach ($paths as $path) {
            $content = AtomicFile::read($path);
            $partial = $content !== null ? Json::decodeArray($content) : null;
            $decoded[] = $partial;
            $any = $any || $partial !== null;
        }

        if (! $any) {
            return null;
        }

        $merged = match ($basename) {
            'meta.json' => self::mergeMetaPartials($decoded),
            'results.json' => self::mergeLastWriteWins($decoded),
            default => self::mergeUnion($decoded),
        };

        return Json::encode($merged);
    }

    /** @return list<string> absolute paths to `worker-*-<basename>`, sorted by filename */
    private static function workerPaths(string $runDir, string $basename): array
    {
        $matches = glob($runDir . '/worker-*-' . $basename) ?: [];
        sort($matches);

        return $matches;
    }

    /**
     * meta: content of the first worker that decoded, except `truncated` which is true when
     * any worker set it.
     *
     * @param list<array<mixed>|null> $partials
     * @return array<string, mixed>
     */
    private static function mergeMetaPartials(array $partials): array
    {
        $first = null;
        $truncated = false;

        foreach ($partials as $partial) {
            if (! is_array($partial)) {
                continue;
            }

            $first ??= self::normalizeMeta($partial);
            $truncated = $truncated || (bool) ($partial['truncated'] ?? false);
        }

        $first ??= [];
        $first['truncated'] = $truncated;

        return $first;
    }

    /**
     * results: last write wins per testId, in worker-file sort order.
     *
     * @param list<array<mixed>|null> $partials
     * @return array<string, mixed>
     */
    private static function mergeLastWriteWins(array $partials): array
    {
        $out = [];

        foreach ($partials as $partial) {
            if (! is_array($partial)) {
                continue;
            }

            foreach ($partial as $key => $value) {
                if (is_string($key)) {
                    $out[$key] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Everything else: a plain list is merged as a deduplicated list (`uses_database.json`);
     * a map is merged key by key, concatenating and deduplicating list values (`edges.json`,
     * `tables.json`, and any future basename of the same shape).
     *
     * @param list<array<mixed>|null> $partials
     * @return array<mixed>
     */
    private static function mergeUnion(array $partials): array
    {
        $isList = null;
        $listOut = [];
        $mapOut = [];

        foreach ($partials as $partial) {
            if (! is_array($partial)) {
                continue;
            }

            if (array_is_list($partial)) {
                $isList ??= true;

                foreach ($partial as $item) {
                    $listOut[] = $item;
                }

                continue;
            }

            $isList ??= false;

            foreach ($partial as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }

                if (is_array($value)) {
                    $existing = $mapOut[$key] ?? [];
                    $mapOut[$key] = [...(is_array($existing) ? $existing : []), ...$value];
                } else {
                    $mapOut[$key] = $value;
                }
            }
        }

        if ($isList === false) {
            foreach ($mapOut as $key => $value) {
                if (is_array($value)) {
                    $mapOut[$key] = self::dedupe($value);
                }
            }

            return $mapOut;
        }

        return self::dedupe($listOut);
    }

    /**
     * @param array<mixed> $list
     * @return list<mixed>
     */
    private static function dedupe(array $list): array
    {
        $seen = [];
        $out = [];

        foreach ($list as $item) {
            $key = is_scalar($item) ? (is_string($item) ? $item : var_export($item, true)) : serialize($item);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, list<string>>
     */
    private static function normalizeStringListMap(array $raw): array
    {
        $out = [];

        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! is_array($value)) {
                continue;
            }

            $list = [];

            foreach ($value as $item) {
                if (is_string($item)) {
                    $list[] = $item;
                }
            }

            $out[$key] = $list;
        }

        return $out;
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, array{status: int, message: string, time: float, assertions: int, file?: string}>
     */
    private static function normalizeResults(array $raw): array
    {
        $out = [];

        foreach ($raw as $testId => $result) {
            if (! is_string($testId) || ! is_array($result)) {
                continue;
            }

            if (! isset($result['status'], $result['message'], $result['time'], $result['assertions'])) {
                continue;
            }

            if (! is_int($result['status'])
                || ! is_string($result['message'])
                || ! is_numeric($result['time'])
                || ! is_int($result['assertions'])
            ) {
                continue;
            }

            $entry = [
                'status' => $result['status'],
                'message' => $result['message'],
                'time' => (float) $result['time'],
                'assertions' => $result['assertions'],
            ];

            if (isset($result['file']) && is_string($result['file'])) {
                $entry['file'] = $result['file'];
            }

            $out[$testId] = $entry;
        }

        return $out;
    }

    /**
     * @param array<mixed> $raw
     * @return list<string>
     */
    private static function normalizeStringList(array $raw): array
    {
        $out = [];

        foreach ($raw as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, mixed>
     */
    private static function normalizeMeta(array $raw): array
    {
        $out = [];

        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
