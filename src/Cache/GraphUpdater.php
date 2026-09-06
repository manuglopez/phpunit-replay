<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Record\RunPartial;

/**
 * Merges a `RunPartial` (written by the extension) into a `Graph`. Shared by the
 * wrapper (filtered mode, results-only mode) and, in phase 1, the extension has no
 * direct use of it — it only writes the partial the wrapper later applies. SPEC.md
 * §7.3, docs/INTERNALS.md "Wave 2 additions".
 *
 * @phpstan-import-type TestResultArray from Graph
 */
final class GraphUpdater
{
    public function __construct(
        private readonly Graph $graph,
        private readonly string $projectRoot,
        private readonly ContentKey $contentKey,
    ) {
    }

    /** The project root `$graph` and `$contentKey` are both scoped to. */
    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Applies a run partial. `$complete` means the run covered everything it was
     * asked to and was not truncated.
     *
     * @return array{touched: list<string>, results: int, edges: int}
     */
    public function apply(RunPartial $partial, string $branch, bool $recordsEdges, bool $complete): array
    {
        $executed = $this->executedTestFiles($partial);
        $edgesCount = 0;

        if ($recordsEdges) {
            $this->graph->replaceEdges($partial->edges);
            $this->graph->markKnownTestFiles($executed);

            if ($partial->tables !== []) {
                $this->graph->replaceTestTables($partial->tables);
            }

            foreach ($partial->edges as $sources) {
                $edgesCount += count($sources);
            }
        }

        [$touched, $keepIds, $resultCount] = $this->mergeResults($partial, $branch, $recordsEdges);

        if ($complete && $recordsEdges) {
            $this->graph->pruneStaleResults($branch, $touched, $keepIds);
            $this->graph->pruneMissingTestFiles();
            $this->graph->pruneResultsForMissingFiles($branch);
        }

        return [
            'touched' => $touched,
            'results' => $resultCount,
            'edges' => $edgesCount,
        ];
    }

    /**
     * After a complete pass: records the baseline sha, marks it complete, and prunes
     * branches no longer known to git — but never the branch just recorded, nor the
     * graph's default branch, even if the caller's `$keepBranches` forgot them.
     *
     * @param  list<string>  $keepBranches
     */
    public function finalizeBaseline(string $branch, ?string $sha, array $keepBranches): void
    {
        $this->graph->setRecordedSha($branch, $sha);
        $this->graph->markBaselineComplete($branch);

        $keep = array_values(array_unique([...$keepBranches, $branch, $this->graph->defaultBranch()]));

        $this->graph->pruneMissingBranches($keep);
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: int} touched files, kept test ids, result count
     */
    private function mergeResults(RunPartial $partial, string $branch, bool $recordsEdges): array
    {
        $touched = [];
        $keepIds = [];
        $keyByFile = [];

        foreach ($partial->results as $testId => $result) {
            $file = $result['file'] ?? null;

            if (! is_string($file) || $file === '') {
                continue;
            }

            if (! $recordsEdges && ! $this->graph->knowsTest($file)) {
                continue;
            }

            if (! array_key_exists($file, $keyByFile)) {
                // Computed once per file, after edges have already been replaced above.
                $keyByFile[$file] = $this->contentKey->forTestFile($this->graph, $file);
                $touched[$file] = true;
            }

            $key = $keyByFile[$file];

            if ($key !== null) {
                $result['key'] = $key;
            }

            $this->graph->setResult($branch, $testId, $result);
            $keepIds[] = $testId;
        }

        return [array_keys($touched), $keepIds, count($keepIds)];
    }

    /** @return list<string> */
    private function executedTestFiles(RunPartial $partial): array
    {
        $files = array_keys($partial->edges);

        foreach ($partial->results as $result) {
            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '') {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }
}
