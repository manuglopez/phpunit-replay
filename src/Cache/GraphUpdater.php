<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Hermeticity\Quarantine;
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
    /** @var array<int, string> classes of `TestStatus::asInt()` a flip is detected across (SPEC.md §8.3, "Hermeticity"). */
    private const STATUS_CLASSES = [
        0 => 'pass', 3 => 'pass', 4 => 'pass', 5 => 'pass', 6 => 'pass',
        1 => 'skipped',
        2 => 'incomplete',
        7 => 'fail', 8 => 'fail',
    ];

    public function __construct(
        private readonly Graph $graph,
        private readonly string $projectRoot,
        private readonly ContentKey $contentKey,
        private readonly ?Quarantine $quarantine = null,
    ) {
    }

    /** The project root `$graph` and `$contentKey` are both scoped to. */
    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /** `'pass'` (0,3,4,5,6) | `'skipped'` (1) | `'incomplete'` (2) | `'fail'` (7,8) | `'unknown'`. */
    public static function statusClass(int $status): string
    {
        return self::STATUS_CLASSES[$status] ?? 'unknown';
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

        $this->applyNotCacheable($partial, $executed);

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

            $this->detectFlip($branch, $testId, $key, $result);

            $this->graph->setResult($branch, $testId, $result);
            $keepIds[] = $testId;
        }

        return [array_keys($touched), $keepIds, count($keepIds)];
    }

    /**
     * SPEC.md §8.3: a cached result whose content key is unchanged but whose status
     * *class* changed (pass ↔ fail, pass ↔ error, ...) since the last time we recorded
     * it is a flaky test — quarantine it. The same content key producing the same class
     * again counts toward the automatic release streak.
     *
     * A cached failure/error (class "fail") always forces a rerun unconditionally
     * (`ConfigurationReader::shouldRerun()`, SPEC §6.2), regardless of key or config, so
     * a test recovering from one (SPEC §15 scenario 5) is that rule working as designed,
     * not a surprise worth quarantining — GraphUpdater has no `ConfigurationReader` of
     * its own, but "old class fail" is exactly the one transition guaranteed reproducible
     * under any configuration.
     *
     * @param TestResultArray $result
     */
    private function detectFlip(string $branch, string $testId, ?string $key, array $result): void
    {
        if ($this->quarantine === null || $key === null) {
            return;
        }

        $old = $this->graph->result($branch, $testId);

        if ($old === null || ($old['key'] ?? null) !== $key) {
            return;
        }

        $oldClass = self::statusClass($old['status']);

        if ($oldClass === 'fail') {
            return;
        }

        if ($oldClass !== self::statusClass($result['status'])) {
            $this->quarantine->recordFlip($testId, $key);

            return;
        }

        $this->quarantine->recordStable($testId);
    }

    /**
     * SPEC.md §8: `Graph::setNotCacheable(union(existing entries whose file/class is NOT
     * among executed files, partial entries))`. An existing entry (a test file, for a
     * class-level attribute, or a `Class::method` id, for a method-level one) is dropped
     * only when this run actually touched it — otherwise the partial simply had nothing
     * to say about it, and dropping it would silently un-quarantine an untouched test.
     *
     * @param list<string> $executedFiles project-relative
     */
    private function applyNotCacheable(RunPartial $partial, array $executedFiles): void
    {
        $existing = $this->graph->notCacheable();

        if ($existing === [] && $partial->notCacheable === []) {
            return;
        }

        $touched = [];

        foreach ([...$executedFiles, ...array_keys($partial->results)] as $raw) {
            $touched[$raw] = true;
            $rel = $this->graph->relative($raw);

            if ($rel !== null) {
                $touched[$rel] = true;
            }
        }

        $kept = [];

        foreach ($existing as $entry) {
            if (! isset($touched[$entry])) {
                $kept[] = $entry;
            }
        }

        $this->graph->setNotCacheable(array_values(array_unique([...$kept, ...$partial->notCacheable])));
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
