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
     * @param list<string> $notCacheable project-relative test files and `Class::method` ids
     */
    public function __construct(
        public array $edges,
        public array $results,
        public array $tables,
        public array $meta,
        public array $notCacheable = [],
    ) {
    }

    public static function load(string $runDir): ?self
    {
        $resultsJson = AtomicFile::read($runDir . '/results.json');
        $metaJson = AtomicFile::read($runDir . '/meta.json');

        if ($resultsJson === null || $metaJson === null) {
            return null;
        }

        $rawResults = Json::decodeArray($resultsJson);
        $rawMeta = Json::decodeArray($metaJson);

        if ($rawResults === null || $rawMeta === null) {
            return null;
        }

        $edgesJson = AtomicFile::read($runDir . '/edges.json');
        $rawEdges = $edgesJson !== null ? Json::decodeArray($edgesJson) : null;

        $tablesJson = AtomicFile::read($runDir . '/tables.json');
        $rawTables = $tablesJson !== null ? Json::decodeArray($tablesJson) : null;

        $notCacheableJson = AtomicFile::read($runDir . '/not_cacheable.json');
        $rawNotCacheable = $notCacheableJson !== null ? Json::decodeArray($notCacheableJson) : null;

        return new self(
            self::normalizeStringListMap($rawEdges ?? []),
            self::normalizeResults($rawResults),
            self::normalizeStringListMap($rawTables ?? []),
            self::normalizeMeta($rawMeta),
            self::normalizeStringList($rawNotCacheable ?? []),
        );
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

        foreach ($raw as $value) {
            if (is_string($value)) {
                $out[] = $value;
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
