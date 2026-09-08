<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use FilesystemIterator;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Hermeticity\Policy;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
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
     *        turns on the {@see ResiduePatterns} fallback, and nothing else here
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
    ) {
    }

    /** @param list<string> $changed project-relative changed files */
    public function build(array $changed, string $branch): RunList
    {
        if ($this->staticDeclarationEdges) {
            // SPEC.md §4.3.1: whatever neither technique could attribute is covered
            // conservatively rather than dropped. Added before the rule chain runs so
            // Rules\WatchRule sees it.
            $this->watch->add((new ResiduePatterns($this->graph, $this->testPaths))->for($changed));
        }

        $selection = Selector::default($this->graph, $this->testPaths, $this->watch, $this->projectRoot, $this->extraRules)
            ->affected($changed);

        $results = $this->graph->results($branch);

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
        );
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
