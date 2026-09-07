<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Json;
use Manuglopez\Replay\Support\Paths;

/**
 * Writes a run partial (`<stateDir>/runs/<run-id>/*.json`) from the in-memory Recorder and
 * ResultCollector state. Paths inside the partial are project-relative; anything that does
 * not resolve under the project root (Support\Paths::relative returning null) is dropped.
 */
final class RunWriter
{
    private bool $truncated = false;

    private readonly string $workerPrefix;

    public function __construct(
        private readonly string $runDir,
        private readonly string $projectRoot,
    ) {
        $this->workerPrefix = self::resolveWorkerPrefix();
    }

    /** ExecutionAborted / Bail: the run did not complete a full pass. */
    public function markTruncated(): void
    {
        $this->truncated = true;
    }

    /** In-process replay has no run partial to read `meta.truncated` back from. */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * `<runDir>/<basename>`, prefixed `worker-<TEST_TOKEN>-` when Paratest set that env var
     * (SPEC.md §13): every file this writer produces goes through here so a Paratest worker
     * never collides with its siblings, and {@see \Manuglopez\Replay\Record\RunPartial::load()}
     * can discover and merge them back together.
     */
    private function pathFor(string $basename): string
    {
        return $this->runDir . '/' . $this->workerPrefix . $basename;
    }

    private static function resolveWorkerPrefix(): string
    {
        $token = getenv('TEST_TOKEN');

        return (is_string($token) && $token !== '') ? 'worker-' . $token . '-' : '';
    }

    /**
     * Writes edges.json, results.json, tables.json, not_cacheable.json, coverage.json and
     * meta.json atomically. `$notCacheable` and `$coverageSnapshots` are optional so every
     * existing caller keeps working unchanged; omitting either simply writes an empty
     * list/map.
     *
     * @param array<string, mixed> $meta
     * @param array<string, string>|null $coverageSnapshots project-relative test file =>
     *        coverage snapshot key (Record\CoverageSnapshots, SPEC.md §3.2 last paragraph)
     */
    public function flush(Recorder $recorder, ResultCollector $collector, array $meta, ?NotCacheableCollector $notCacheable = null, ?array $coverageSnapshots = null): bool
    {
        $edges = $this->relativiseEdges($recorder->perTestFiles());
        $tables = $this->relativiseTables($recorder->perTestTables());
        $results = $this->relativiseResults($collector->all());
        $notCacheableList = $notCacheable?->all() ?? [];
        $coverageMap = $coverageSnapshots ?? [];

        $meta['truncated'] = $this->truncated;

        $edgesJson = Json::encode($edges);
        $resultsJson = Json::encode($results);
        $tablesJson = Json::encode($tables);
        $notCacheableJson = Json::encode($notCacheableList);
        $coverageJson = Json::encode($coverageMap);
        $metaJson = Json::encode($meta);

        if ($edgesJson === null || $resultsJson === null || $tablesJson === null || $notCacheableJson === null || $coverageJson === null || $metaJson === null) {
            return false;
        }

        $ok = AtomicFile::write($this->pathFor('edges.json'), $edgesJson);
        $ok = AtomicFile::write($this->pathFor('results.json'), $resultsJson) && $ok;
        $ok = AtomicFile::write($this->pathFor('tables.json'), $tablesJson) && $ok;
        $ok = AtomicFile::write($this->pathFor('not_cacheable.json'), $notCacheableJson) && $ok;
        $ok = AtomicFile::write($this->pathFor('coverage.json'), $coverageJson) && $ok;
        $ok = AtomicFile::write($this->pathFor('meta.json'), $metaJson) && $ok;

        return $ok;
    }

    /**
     * Writes `uses_database.json` (Laravel integration, SPEC.md §10): the project-relative
     * test files whose class uses a database-refreshing testing trait. Deliberately separate
     * from {@see self::flush()} — the Laravel integration is optional and must not change the
     * core recording path's signature.
     *
     * @param  list<string>  $testFilesAbsolute
     */
    public function writeUsesDatabase(array $testFilesAbsolute): bool
    {
        $relative = [];

        foreach ($testFilesAbsolute as $testFileAbsolute) {
            $rel = Paths::relative($this->projectRoot, $testFileAbsolute);

            if ($rel !== null) {
                $relative[$rel] = true;
            }
        }

        $names = array_keys($relative);
        sort($names);

        $json = Json::encode($names);

        if ($json === null) {
            return false;
        }

        return AtomicFile::write($this->pathFor('uses_database.json'), $json);
    }

    /**
     * @param array<string, list<string>> $perTestFiles absolute test file => absolute source files
     * @return array<string, list<string>> relative test file => relative source files
     */
    private function relativiseEdges(array $perTestFiles): array
    {
        $out = [];

        foreach ($perTestFiles as $testFileAbsolute => $sourceFilesAbsolute) {
            $testFileRelative = Paths::relative($this->projectRoot, $testFileAbsolute);

            if ($testFileRelative === null) {
                continue;
            }

            $sources = [];

            foreach ($sourceFilesAbsolute as $sourceFileAbsolute) {
                $sourceFileRelative = Paths::relative($this->projectRoot, $sourceFileAbsolute);

                if ($sourceFileRelative === null) {
                    continue;
                }

                $sources[] = $sourceFileRelative;
            }

            $out[$testFileRelative] = $sources;
        }

        return $out;
    }

    /**
     * @param array<string, list<string>> $perTestTables absolute test file => table names
     * @return array<string, list<string>> relative test file => table names
     */
    private function relativiseTables(array $perTestTables): array
    {
        $out = [];

        foreach ($perTestTables as $testFileAbsolute => $tables) {
            $testFileRelative = Paths::relative($this->projectRoot, $testFileAbsolute);

            if ($testFileRelative === null) {
                continue;
            }

            $out[$testFileRelative] = $tables;
        }

        return $out;
    }

    /**
     * @param array<string, array{status: int, message: string, time: float, assertions: int, file?: string}> $results
     * @return array<string, array{status: int, message: string, time: float, assertions: int, file?: string}>
     */
    private function relativiseResults(array $results): array
    {
        $out = [];

        foreach ($results as $testId => $result) {
            if (isset($result['file'])) {
                $relative = Paths::relative($this->projectRoot, $result['file']);

                if ($relative === null) {
                    unset($result['file']);
                } else {
                    $result['file'] = $relative;
                }
            }

            $out[$testId] = $result;
        }

        return $out;
    }
}
