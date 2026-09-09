<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Analysis\StaticEdges;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Console\Runner\Warnings;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Laravel\OncePerProcessPaths;
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

    /** @see self::ignoredDependencies() */
    private readonly Git $git;

    /**
     * `$staticEdges` is the `static_declaration_edges` opt-in (SPEC.md §4.3.1): null — the
     * default, and every existing caller — leaves {@see self::apply()} recording exactly
     * the edges the coverage driver reported, byte for byte.
     *
     * `$onceProcessPaths` is the once-per-process residue fix (docs/reproducibility.md
     * "Once-per-process residue"): non-null only when the caller ALSO built `$staticEdges`
     * (`Console\Runner\RunPipeline`, `PHPUnit\ReplayState` construct both together, gated on
     * `static_declaration_edges` AND a detected Laravel project). {@see self::apply()}
     * re-checks `$staticEdges !== null` itself before ever consulting it — see the comment
     * there for why that second check is not redundant.
     *
     * `$git` defaults to a fresh `Change\Git` scoped to `$projectRoot`, matching the same
     * "inject or construct" convention as `Change\ChangedFiles`. A caller that already has
     * one (`Console\Runner\RunPipeline`, `PHPUnit\ReplayState`) passes it through instead of
     * paying for a second one.
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly string $projectRoot,
        private readonly ContentKey $contentKey,
        private readonly ?Quarantine $quarantine = null,
        private readonly ?StaticEdges $staticEdges = null,
        ?Git $git = null,
        private readonly ?OncePerProcessPaths $onceProcessPaths = null,
    ) {
        $this->git = $git ?? new Git($projectRoot);
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
     * @return array{touched: list<string>, results: int, edges: int, excludedEdges: int}
     */
    public function apply(RunPartial $partial, string $branch, bool $recordsEdges, bool $complete): array
    {
        $executed = $this->executedTestFiles($partial);
        $edgesCount = 0;
        $excludedCount = 0;

        if ($recordsEdges) {
            // No edge to a file git ignores (SPEC.md §7.3): batched ONCE per apply() call,
            // covering every candidate BOTH edge writers below could possibly touch, never
            // once per file or once per writer (ARG_MAX; see Change\Git::ignored()). Ignored
            // is a stable property of a path PATTERN — an ignored file can never appear in
            // Change\ChangedFiles::since(), so an edge to one is pure content-key pollution
            // that could never trigger a rerun. Untracked-but-not-ignored is the opposite: a
            // brand-new source file DOES appear in that diff, so its edge must survive this
            // filter untouched (ChangedFilesTest::test_untracked_new_file_is_listed) — only
            // an actual `.gitignore` match is dropped here.
            $ignored = $this->ignoredDependencies($partial);
            $excludedCount = self::countIgnored($partial->edges, $ignored);
            $filteredEdges = self::withoutIgnored($partial->edges, $ignored);

            if ($excludedCount > 0) {
                self::debugExcluded($partial->edges, $ignored);
            }

            // Once-per-process Laravel sources (docs/reproducibility.md "Once-per-process
            // residue"): a migration, seeder or console command whose body a coverage driver
            // saw executed is credited to whichever test happened to trigger it first in
            // this worker process — every OTHER test that also depends on it never gets the
            // edge, no matter how many times the suite is re-recorded. `$edgesToRecord` is
            // therefore a SEPARATE variable from `$filteredEdges`, not a reassignment of it:
            // `$filteredEdges` still feeds `behaviouralEdges()` below unfiltered, because the
            // static hop's "the test's own source names it" case must still be free to link
            // one of these files (a name reference is order-independent evidence, the good
            // half — docs/reproducibility.md). Only the COVERAGE-derived edge computed here
            // is refused.
            //
            // Gated on `$this->staticEdges !== null` — re-checked here even though
            // `$onceProcessPaths` is itself only ever constructed alongside it
            // (`Console\Runner\RunPipeline`, `PHPUnit\ReplayState`, both gated on
            // `static_declaration_edges` — deliberately, so this can never engage with the
            // flag off even if a future caller gets that construction site wrong. With the
            // flag off, `Select\RunListBuilder::build()` never installs the
            // `Select\ResiduePatterns` fallback, so a refused edge would leave NOTHING
            // selecting the file at all — a new, strictly worse false green than the one
            // this exists to close. Do not remove this check as a "simplification": it is
            // the one invariant that keeps this fix from becoming the bug it removes.
            $edgesToRecord = $filteredEdges;

            if ($this->staticEdges !== null && $this->onceProcessPaths !== null) {
                $edgesToRecord = self::withoutOnceProcessSources($edgesToRecord, $this->onceProcessPaths);
            }

            // Union, not replace (Cache\Graph::unionEdges() docblock): this partial only
            // reflects what THIS run's coverage attributed, which can under-report a test
            // file's true dependencies (first-loader-wins, docs/SPEC.md §4.3) relative to
            // a previous, complete recording. Table edges are unaffected by that
            // artifact — a Laravel query listener re-attributes every table a test
            // queries on every single run (Laravel\TableTracker), never just the first —
            // so `replaceTestTables` below stays exact.
            $this->graph->unionEdges($edgesToRecord);
            $this->graph->markKnownTestFiles($executed);

            if ($partial->tables !== []) {
                $this->graph->replaceTestTables($partial->tables);
            }

            foreach ($edgesToRecord as $sources) {
                $edgesCount += count($sources);
            }

            // One hop of name-resolution edges for what this run's tests can never get from
            // coverage (Analysis\StaticEdges). Must happen here, before mergeResults() below,
            // because that is where each touched file's content key is computed from its (by
            // then final) dependency list. $ignored is passed through so this writer refuses
            // the same paths the one above does — it is the OTHER of the two edge writers the
            // "no edge to an ignored file" rule has to reach (docs/SPEC.md, "edge recording").
            // Hop sources come from $filteredEdges (NOT $edgesToRecord): see the once-process
            // comment above for why a once-per-process file must still be usable as a hop
            // source, and reachable through the test's own name reference.
            if ($this->staticEdges !== null) {
                $edgesCount += $this->staticEdges->expand($this->graph, self::behaviouralEdges($filteredEdges, $executed), $ignored);
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
            'excludedEdges' => $excludedCount,
        ];
    }

    /**
     * Every distinct source file this `apply()` call could possibly turn into an edge,
     * batch-checked against `git check-ignore` exactly once: every source in `$partial->edges`
     * (the coverage-derived and explicit `Recorder::linkSource()` edges `unionEdges()` is
     * about to receive) plus, when the `static_declaration_edges` opt-in is on, every
     * declaring file in `Analysis\StaticEdges::index()` (the universe `expand()` could link
     * to). `index()` is memoized on `$this->staticEdges` itself, so calling it here does not
     * duplicate the parse `expand()` would otherwise trigger a few lines below — it just
     * moves the one, cached computation earlier.
     *
     * Fails open: `Change\Git::ignored()` returns null when git itself failed (no git, not a
     * repository, a subprocess error or a timeout), and null here becomes an empty set —
     * nothing is treated as ignored, so every edge is still recorded. Same precedent as
     * `Fingerprint::isTrackedByGit()`: no `.git` at all ⇒ trust it.
     *
     * @return array<string, true>
     */
    private function ignoredDependencies(RunPartial $partial): array
    {
        $candidates = [];

        foreach ($partial->edges as $sources) {
            foreach ($sources as $source) {
                $candidates[$source] = true;
            }
        }

        if ($this->staticEdges !== null) {
            foreach ($this->staticEdges->index() as $declaringFiles) {
                foreach ($declaringFiles as $declaringFile) {
                    $candidates[$declaringFile] = true;
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        return $this->git->ignored(array_keys($candidates)) ?? [];
    }

    /**
     * @param array<string, list<string>> $edges
     * @param array<string, true> $ignored
     * @return array<string, list<string>>
     */
    private static function withoutIgnored(array $edges, array $ignored): array
    {
        if ($ignored === []) {
            return $edges;
        }

        $out = [];

        foreach ($edges as $testFile => $sources) {
            $out[$testFile] = array_values(array_filter(
                $sources,
                static fn (string $source): bool => ! isset($ignored[$source]),
            ));
        }

        return $out;
    }

    /**
     * The other filter over the coverage-derived edges, in the same shape as
     * {@see self::withoutIgnored()}: drops any source matching {@see OncePerProcessPaths}
     * from every test's edge list, never the test's own key (a test that only ever
     * depended on such a file still needs `markKnownTestFiles()` to know about it).
     *
     * @param array<string, list<string>> $edges
     * @return array<string, list<string>>
     */
    private static function withoutOnceProcessSources(array $edges, OncePerProcessPaths $onceProcessPaths): array
    {
        $out = [];

        foreach ($edges as $testFile => $sources) {
            $out[$testFile] = array_values(array_filter(
                $sources,
                static fn (string $source): bool => ! $onceProcessPaths->matches($source),
            ));
        }

        return $out;
    }

    /**
     * @param array<string, list<string>> $edges
     * @param array<string, true> $ignored
     */
    private static function countIgnored(array $edges, array $ignored): int
    {
        if ($ignored === []) {
            return 0;
        }

        $count = 0;

        foreach ($edges as $sources) {
            foreach ($sources as $source) {
                if (isset($ignored[$source])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * The actual excluded paths, one per line, behind `PHPUNIT_REPLAY_DEBUG=1`
     * (`Warnings::debug()` — never printed otherwise, unlike the count on
     * `Report\RecordSummary`'s summary line, which is unconditional whenever positive).
     * Deduplicated: the same ignored file can be a dependency of several tests in one run,
     * and the count already says how many edge instances that was.
     *
     * @param array<string, list<string>> $edges
     * @param array<string, true> $ignored
     */
    private static function debugExcluded(array $edges, array $ignored): void
    {
        $distinct = [];

        foreach ($edges as $sources) {
            foreach ($sources as $source) {
                if (isset($ignored[$source])) {
                    $distinct[$source] = true;
                }
            }
        }

        $paths = array_keys($distinct);
        sort($paths);

        Warnings::debug(sprintf(
            'excluded %d gitignored dependenc%s: %s',
            count($paths),
            count($paths) === 1 ? 'y' : 'ies',
            implode(', ', $paths),
        ));
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

    /**
     * This run's coverage-derived edges, with an entry for every test that executed —
     * `[]` for one whose coverage reported no source file at all.
     *
     * `Analysis\StaticEdges::expand()` needs both halves of that. It must not read the hop
     * sources off the graph (it would follow static edges from earlier passes and grow the
     * graph pass after pass), and it must still be handed the tests with no behavioural edge:
     * `Record\Recorder::endTest()` only creates `perTestFiles[$test]` inside its loop over the
     * files coverage reported, so a test whose coverage saw nothing has no key in `edges.json`
     * — which is precisely the case static edges exist for (a `<source><exclude>` over the
     * test directory, or any `--coverage-*` report, produces it). Passing
     * `array_keys($partial->edges)` skipped exactly those tests.
     *
     * @param array<string, list<string>> $edges this run's (already ignore-filtered) edges
     * @param list<string> $executed project-relative test files
     * @return array<string, list<string>>
     */
    private static function behaviouralEdges(array $edges, array $executed): array
    {
        $out = array_fill_keys($executed, []);

        foreach ($edges as $testFile => $sources) {
            $out[$testFile] = $sources;
        }

        return $out;
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
