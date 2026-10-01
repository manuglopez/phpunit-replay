<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use FilesystemIterator;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Hermeticity\Policy;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Support\Paths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Turns a set of changed files into the list of test files a pass must execute
 * (docs/INTERNALS.md step 9): the rule chain's selection, plus test files the graph
 * has never seen, plus files whose cached result must be re-run, plus files holding a
 * test the hermeticity policy refuses to replay. Extracted from
 * `Console\Runner\RunPipeline` so the wrapper and the in-process extension agree.
 */
final class RunListBuilder
{
    /** @var list<string>|null memoised directory walk (project-relative candidates) */
    private ?array $candidates = null;

    /**
     * @param array{migration?: Rule, sibling?: Rule, blade?: Rule} $extraRules Laravel-only rules, docs/INTERNALS.md "Laravel"
     * @param bool $staticDeclarationEdges the `static_declaration_edges` opt-in (SPEC.md §4.3.1);
     *        turns on the unattributed half of the {@see ResiduePatterns} fallback, and nothing
     *        else here
     * @param SourceScope|null $scope the coverage scope: a `.php` file its configured excludes
     *        keep out of coverage is residue whatever the flag
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly TestPaths $testPaths,
        private readonly WatchPatterns $watch,
        private readonly ConfigurationReader $reader,
        private readonly Policy $policy,
        private readonly string $projectRoot,
        private readonly array $extraRules = [],
        private readonly bool $staticDeclarationEdges = false,
        private readonly ?SourceScope $scope = null,
    ) {
    }

    private function residue(): ResiduePatterns
    {
        return new ResiduePatterns($this->graph, $this->testPaths, $this->staticDeclarationEdges, $this->scope);
    }

    /**
     * @param list<string> $changed project-relative changed files
     * @param array<string, array{reason: Reason, ids: list<string>}> $stale what
     *        {@see LayerAudit::apply()} stopped serving: a file one of whose audited test
     *        ids has no result left anywhere in the merged view executes, as `StaleLayer`
     */
    public function build(array $changed, string $branch, array $stale = []): RunList
    {
        // SPEC.md §4.3.1: whatever nothing could attribute is covered conservatively rather
        // than dropped — with the flag, any `.php` file without an edge; always, a `.php`
        // file `<source><exclude>` keeps out of coverage. Added before the rule chain runs so
        // Rules\WatchRule sees it: the first as a fallback, for what no rule claimed; the second
        // on every changed file, since coverage cannot say who executes it.
        $residue = $this->residue();
        $this->watch->addFallback($residue->for($changed));
        $this->watch->addUnattributable($residue->unattributableFor($changed));

        $selection = Selector::default($this->graph, $this->testPaths, $this->watch, $this->projectRoot, $this->extraRules)
            ->affected($changed);

        $results = $this->graph->results($branch);
        [$staleFiles, $staleReasons] = $this->staleBucket($stale, $results);

        $unknown = [];
        $allTestFiles = [];

        foreach ($this->candidateTestFiles() as $rel) {
            if (! $this->testPaths->isTestFile($rel)) {
                continue;
            }

            $allTestFiles[] = $rel;

            if (! $this->graph->knowsTest($rel)) {
                $unknown[] = $rel;
            }
        }

        sort($unknown);
        sort($allTestFiles);

        $rerun = [];
        $idsByFile = [];

        foreach ($results as $testId => $result) {
            $file = $result['file'] ?? null;

            if (! is_string($file) || $file === '' || ! is_file(Paths::join($this->projectRoot, $file))) {
                continue;
            }

            $idsByFile[$file][] = $testId;

            if (! isset($rerun[$file]) && $this->reader->shouldRerun($result['status'])) {
                $rerun[$file] = $result['status'];
            }
        }

        // A test file the graph knows must never be neither executed nor replayed. Replay
        // serves a test from `$results`, so a known file with no result at all in the layers
        // this pass serves from (cleared by an environmental drift, an interrupted or
        // truncated record, a layer that never held it) has nothing to replay and no rule
        // selecting it: it would silently run nothing. It joins the uncached bucket and
        // executes, which also records the results the next pass replays.
        //
        // `$results` is read after Select\LayerAudit has already forgotten/withheld what the
        // current tree invalidated (the callers audit before building), so a file whose every
        // result was audited away has no ids here either. It already executes as `stale`,
        // with the reason that explains it (StaleLayer), and is kept out of this bucket so
        // `--explain` gives it one reason rather than a StaleLayer and an Uncached.
        $noResult = [];
        $isStale = array_fill_keys($staleFiles, true);

        foreach ($allTestFiles as $rel) {
            if ($this->graph->knowsTest($rel) && ! isset($idsByFile[$rel]) && ! isset($isStale[$rel])) {
                $noResult[] = $rel;
            }
        }

        $unknown = array_values(array_unique([...$unknown, ...$noResult]));
        sort($unknown);

        // Split every non-cacheable file (docs/INTERNALS.md "Hermeticity") into two run-list
        // buckets by its most relevant Reason: automatic quarantine (a flip,
        // Hermeticity\Quarantine) versus an explicit `#[NotCacheable]`/`never_cache` — the
        // wrapper counts these separately (Report\Summary's `notCacheable` segment).
        $quarantined = [];
        $quarantineReasons = [];
        $notCacheable = [];
        $notCacheableReasons = [];

        foreach ($this->policy->nonCacheableFiles($allTestFiles, $idsByFile) as $file) {
            $reason = $this->reasonFor($file, $idsByFile[$file] ?? []);

            if ($reason->rule === 'Quarantine') {
                $quarantined[] = $file;
                $quarantineReasons[$file] = $reason;
            } else {
                $notCacheable[] = $file;
                $notCacheableReasons[$file] = $reason;
            }
        }

        return new RunList(
            $selection,
            $unknown,
            array_keys($rerun),
            $quarantined,
            $rerun,
            $quarantineReasons,
            $notCacheable,
            $notCacheableReasons,
            $noResult,
            $staleFiles,
            $staleReasons,
        );
    }

    /**
     * The rule chain's selection for a change set, with no side effect on this builder:
     * what {@see LayerAudit} asks of a layer's own diff. The residue fallback goes into a
     * copy of the watch patterns, so it cannot leak into the pass's own {@see self::build()}.
     *
     * @param list<string> $changed project-relative changed files
     */
    public function select(array $changed): Selection
    {
        $watch = clone $this->watch;
        $residue = $this->residue();
        $watch->addFallback($residue->for($changed));
        $watch->addUnattributable($residue->unattributableFor($changed));

        return Selector::default($this->graph, $this->testPaths, $watch, $this->projectRoot, $this->extraRules)
            ->affected($changed);
    }

    /**
     * A stale file is served from whatever valid layer still holds a result for each of its
     * test ids; one id with nothing left to serve it and the whole file executes — the
     * wrapper runs files, not ids, and an id nobody holds would otherwise be neither
     * executed nor replayed. Its reason is kept either way, so a file in the run list for
     * another reason (the layer underneath holds a failure) still says why its own cached
     * result was not used.
     *
     * @param array<string, array{reason: Reason, ids: list<string>}> $stale
     * @param array<string, array{file?: string}> $results
     * @return array{0: list<string>, 1: array<string, Reason>}
     */
    private function staleBucket(array $stale, array $results): array
    {
        $files = [];
        $reasons = [];

        foreach ($stale as $file => $entry) {
            if (! is_file(Paths::join($this->projectRoot, $file))) {
                continue;
            }

            $reasons[$file] = $entry['reason'];

            foreach ($entry['ids'] as $testId) {
                if (($results[$testId]['file'] ?? null) !== $file) {
                    $files[] = $file;

                    break;
                }
            }
        }

        return [$files, $reasons];
    }

    /**
     * The single most relevant {@see Reason} a non-cacheable test file is in the run
     * list for: a class/method `#[NotCacheable]` attribute or a `never_cache` glob match
     * (rule reported as `NotCacheable`, detail `attribute`/`never_cache`), else automatic
     * quarantine (rule `Quarantine`, trigger the flipping test id, detail its flip count).
     *
     * @param list<string> $testIds test ids the graph has results for in this file
     */
    private function reasonFor(string $testFile, array $testIds): Reason
    {
        $fileReason = $this->policy->reason($testFile, $testFile);

        if ($fileReason === 'attribute' || $fileReason === 'never_cache') {
            return new Reason('NotCacheable', $fileReason);
        }

        foreach ($testIds as $testId) {
            $reason = $this->policy->reason($testFile, $testId);

            if ($reason === 'quarantine') {
                $flips = $this->policy->quarantine()->all()[$testId]['flips'] ?? 0;

                return new Reason('Quarantine', $testId, sprintf('flips: %d', $flips));
            }

            if ($reason === 'attribute' || $reason === 'never_cache') {
                return new Reason('NotCacheable', $reason);
            }
        }

        return new Reason('NotCacheable', 'not cacheable');
    }

    /**
     * Every test file currently on disk — the fallback run list for a source change the
     * pass cannot map to edges (no coverage driver, docs/INTERNALS.md step 9).
     *
     * @return list<string> project-relative, sorted
     */
    public function allTestFilesOnDisk(): array
    {
        $all = [];

        foreach ($this->candidateTestFiles() as $rel) {
            if ($this->testPaths->isTestFile($rel)) {
                $all[] = $rel;
            }
        }

        sort($all);

        return $all;
    }

    /** @return list<string> everything under the configured test directories, plus explicit files */
    private function candidateTestFiles(): array
    {
        if ($this->candidates !== null) {
            return $this->candidates;
        }

        $candidates = [];

        foreach ($this->testPaths->directories() as $dir) {
            $absoluteDir = Paths::join($this->projectRoot, $dir);

            if (! is_dir($absoluteDir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $fileInfo) {
                if (! $fileInfo instanceof SplFileInfo || ! $fileInfo->isFile()) {
                    continue;
                }

                $rel = Paths::relative($this->projectRoot, $fileInfo->getPathname());

                if ($rel !== null) {
                    $candidates[$rel] = true;
                }
            }
        }

        foreach ($this->testPaths->files() as $rel) {
            $candidates[$rel] = true;
        }

        return $this->candidates = array_keys($candidates);
    }
}
