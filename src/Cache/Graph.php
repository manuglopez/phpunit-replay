<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Support\Json;
use Manuglopez\Replay\Support\Paths;
use Manuglopez\Replay\Version;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/Graph.php
 *
 * In-memory dependency graph (test file → source files) plus per-branch baselines, with a
 * defensive JSON codec. SPEC.md §4.2.
 *
 * @phpstan-type TestResultArray array{status:int, message:string, time:float, assertions:int, file?:string, key?:string}
 * @phpstan-type Baseline array{sha:?string, complete?:bool, results:array<string, TestResultArray>}
 */
final class Graph
{
    public const SCHEMA = 1;

    /** @var array<int, string> */
    private array $files = [];

    /** @var array<string, int> */
    private array $fileIds = [];

    /** @var array<string, list<int>> */
    private array $edges = [];

    /** @var array<string, list<string>> */
    private array $testTables = [];

    /** @var list<string> */
    private array $notCacheable = [];

    /** @var array<string, mixed> */
    private array $fingerprint = [];

    /** @var array<string, Baseline> */
    private array $baselines = [];

    private string $defaultBranch = 'main';

    private ?string $nearestBranch = null;

    private readonly string $projectRoot;

    /** @var array<string, list<string>>|null */
    private ?array $reverseIndex = null;

    public function __construct(string $projectRoot)
    {
        $real = @realpath($projectRoot);

        $this->projectRoot = $real !== false ? $real : $projectRoot;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function relative(string $path): ?string
    {
        return Paths::relative($this->projectRoot, $path);
    }

    public function link(string $testFile, string $sourceFile): void
    {
        $testRel = $this->relative($testFile);
        $sourceRel = $this->relative($sourceFile);

        if ($testRel === null || $sourceRel === null) {
            return;
        }

        if (! isset($this->fileIds[$sourceRel])) {
            $id = count($this->files);
            $this->files[$id] = $sourceRel;
            $this->fileIds[$sourceRel] = $id;
        }

        $this->edges[$testRel][] = $this->fileIds[$sourceRel];
        $this->reverseIndex = null;
    }

    /** @param array<string, list<string>> $testToFiles */
    public function replaceEdges(array $testToFiles): void
    {
        foreach ($testToFiles as $testFile => $sources) {
            $testRel = $this->relative($testFile);

            if ($testRel === null) {
                continue;
            }

            $this->edges[$testRel] = [];

            foreach ($sources as $source) {
                $this->link($testFile, $source);
            }

            $this->edges[$testRel] = array_values(array_unique($this->edges[$testRel]));
        }

        $this->reverseIndex = null;
    }

    /**
     * Merges recorded edges into what the graph already has for each test file, instead
     * of replacing them outright. The correctness-critical counterpart to
     * {@see self::replaceEdges()}: PHP executes a file's top level exactly once per
     * process, so a coverage driver only ever credits its declaration footprint
     * (class/enum/const, or any other top-level statement — Record\Recorder's own
     * docblock) to whichever test in that worker happened to load it first. A *partial*
     * re-record (some subset of tests re-executed, e.g. because something else they
     * depend on changed, or a different paratest worker distribution) reflects only
     * *this* run's attribution, which can differ from a previous, complete one — so it
     * must never be allowed to shrink a test's edges, only grow them. A dependency that
     * genuinely disappears is dropped by the next full, fresh `record` instead (which
     * always starts from an empty graph, so there is nothing to unify with — SPEC.md
     * §4.3, §7.3).
     *
     * @param array<string, list<string>> $testToFiles
     */
    public function unionEdges(array $testToFiles): void
    {
        foreach ($testToFiles as $testFile => $sources) {
            $testRel = $this->relative($testFile);

            if ($testRel === null) {
                continue;
            }

            $this->edges[$testRel] ??= [];

            foreach ($sources as $source) {
                $this->link($testFile, $source);
            }

            $this->edges[$testRel] = array_values(array_unique($this->edges[$testRel]));
        }

        $this->reverseIndex = null;
    }

    /** @param list<string> $testFiles */
    public function markKnownTestFiles(array $testFiles): void
    {
        foreach ($testFiles as $testFile) {
            $rel = $this->relative($testFile);

            if ($rel === null) {
                continue;
            }

            if (! isset($this->edges[$rel])) {
                $this->edges[$rel] = [];
                $this->reverseIndex = null;
            }
        }
    }

    public function knowsTest(string $testFile): bool
    {
        $rel = $this->relative($testFile);

        return $rel !== null && isset($this->edges[$rel]);
    }

    /** @return list<string> */
    public function allTestFiles(): array
    {
        return array_keys($this->edges);
    }

    /** @return list<string> */
    public function files(): array
    {
        return array_values($this->files);
    }

    public function fileId(string $relative): ?int
    {
        return $this->fileIds[$relative] ?? null;
    }

    /** @return list<string> */
    public function dependenciesOf(string $testFileRel): array
    {
        $deps = [];

        foreach ($this->edges[$testFileRel] ?? [] as $id) {
            if (isset($this->files[$id])) {
                $deps[] = $this->files[$id];
            }
        }

        return $deps;
    }

    /** @return list<string> */
    public function testFilesDependingOn(string $relative): array
    {
        return $this->buildReverseIndex()[$relative] ?? [];
    }

    /** @return array<string, list<string>> */
    private function buildReverseIndex(): array
    {
        if ($this->reverseIndex !== null) {
            return $this->reverseIndex;
        }

        $index = [];

        foreach ($this->edges as $testFile => $ids) {
            foreach ($ids as $id) {
                if (isset($this->files[$id])) {
                    $index[$this->files[$id]][] = $testFile;
                }
            }
        }

        $this->reverseIndex = $index;

        return $index;
    }

    /** @param array<string, list<string>> $testToTables */
    public function replaceTestTables(array $testToTables): void
    {
        foreach ($testToTables as $testFile => $tables) {
            $testRel = $this->relative($testFile);

            if ($testRel === null) {
                continue;
            }

            $normalised = [];

            foreach ($tables as $table) {
                $lower = strtolower($table);

                if ($lower !== '') {
                    $normalised[$lower] = true;
                }
            }

            $names = array_keys($normalised);
            sort($names);

            $this->testTables[$testRel] = $names;
        }
    }

    /** @return array<string, list<string>> */
    public function testTables(): array
    {
        return $this->testTables;
    }

    /** @param list<string> $testFiles */
    public function setNotCacheable(array $testFiles): void
    {
        $set = [];

        foreach ($testFiles as $testFile) {
            $rel = $this->relative($testFile);

            if ($rel !== null) {
                $set[$rel] = true;
            }
        }

        $this->notCacheable = array_keys($set);
    }

    /** @return list<string> */
    public function notCacheable(): array
    {
        return $this->notCacheable;
    }

    /**
     * Whether `not_cacheable` holds this entry, given either as a test file (absolute or
     * project-relative) or as a test id (`Class::method`). Test ids are compared verbatim
     * first, since {@see self::relative()} would rewrite their namespace separators.
     */
    public function isNotCacheable(string $fileOrTestId): bool
    {
        if (in_array($fileOrTestId, $this->notCacheable, true)) {
            return true;
        }

        $rel = $this->relative($fileOrTestId);

        return $rel !== null && in_array($rel, $this->notCacheable, true);
    }

    /** @param array<string, mixed> $fingerprint */
    public function setFingerprint(array $fingerprint): void
    {
        $this->fingerprint = $fingerprint;
    }

    /** @return array<string, mixed> */
    public function fingerprint(): array
    {
        return $this->fingerprint;
    }

    public function setDefaultBranch(string $branch): void
    {
        $this->defaultBranch = $branch;
    }

    public function defaultBranch(): string
    {
        return $this->defaultBranch;
    }

    /**
     * DECISIONS.md D-039: the baseline `Change\BaselineResolver` picked as the nearest
     * ancestor of HEAD, consulted for results BEFORE the default branch and AFTER this
     * branch's own baseline.
     *
     * It sits between the two rather than replacing the default branch because a
     * long-lived branch's baseline is a delta: a pass on `develop` only ever writes the
     * results of the tests it executed into `develop`, leaving the rest in `main`. Reading
     * `develop` alone would silently drop every test `develop` never had to re-run.
     */
    public function setNearestBranch(?string $branch): void
    {
        $this->nearestBranch = $branch;
    }

    public function nearestBranch(): ?string
    {
        return $this->nearestBranch;
    }

    /** @return list<string> */
    public function branches(): array
    {
        return array_keys($this->baselines);
    }

    public function recordedSha(string $branch): ?string
    {
        $own = $this->baselines[$branch]['sha'] ?? null;

        if ($own !== null) {
            return $own;
        }

        foreach ($this->fallbackChain($branch) as $fallback) {
            $sha = $this->baselines[$fallback]['sha'] ?? null;

            if ($sha !== null) {
                return $sha;
            }
        }

        return null;
    }

    /**
     * The sha this branch's OWN baseline was recorded at, with no fallback to the default
     * branch — what {@see \Manuglopez\Replay\Change\BaselineResolver} compares candidates
     * by (a candidate reporting the default branch's sha under its own name would be
     * counted twice, at the wrong distance).
     */
    public function ownRecordedSha(string $branch): ?string
    {
        return $this->baselines[$branch]['sha'] ?? null;
    }

    public function setRecordedSha(string $branch, ?string $sha): void
    {
        $this->ensureBaseline($branch);
        $this->baselines[$branch]['sha'] = $sha;
    }

    public function isBaselineComplete(string $branch): bool
    {
        return ($this->baselines[$branch]['complete'] ?? false) === true;
    }

    public function markBaselineComplete(string $branch): void
    {
        $this->ensureBaseline($branch);
        $this->baselines[$branch]['complete'] = true;
    }

    /** @param TestResultArray $result */
    public function setResult(string $branch, string $testId, array $result): void
    {
        $this->ensureBaseline($branch);
        $this->baselines[$branch]['results'][$testId] = $result;
    }

    /** @return TestResultArray|null */
    public function result(string $branch, string $testId): ?array
    {
        return $this->mergedResults($branch)[$testId] ?? null;
    }

    /** @return array<string, TestResultArray> */
    public function results(string $branch): array
    {
        return $this->mergedResults($branch);
    }

    /** @return array<string, TestResultArray> */
    public function ownResults(string $branch): array
    {
        return $this->baselines[$branch]['results'] ?? [];
    }

    public function clearResults(?string $branch = null): void
    {
        if ($branch === null) {
            foreach (array_keys($this->baselines) as $existing) {
                $this->baselines[$existing]['results'] = [];
            }

            return;
        }

        $this->ensureBaseline($branch);
        $this->baselines[$branch]['results'] = [];
    }

    /**
     * This branch's own results layered over its fallbacks: the nearest baseline first,
     * the default branch under it. A *complete* layer is authoritative for the test files
     * it covers, so results the layer below holds for those same files are dropped rather
     * than merged (a file whose tests were renamed must not keep reporting the old names).
     *
     * @return array<string, TestResultArray>
     */
    private function mergedResults(string $branch): array
    {
        $under = [];

        foreach (array_reverse($this->fallbackChain($branch)) as $fallback) {
            $under = $this->layer($under, $fallback);
        }

        $own = $this->baselines[$branch]['results'] ?? null;

        if ($own === null) {
            return $under;
        }

        return $this->layer($under, $branch);
    }

    /**
     * The fallback branches for `$branch`, nearest first. Empty when `$branch` is itself
     * the last stop, which is what makes the default branch read only its own results.
     *
     * @return list<string>
     */
    private function fallbackChain(string $branch): array
    {
        $chain = [];

        foreach ([$this->nearestBranch, $this->defaultBranch] as $candidate) {
            if ($candidate !== null && $candidate !== $branch && ! in_array($candidate, $chain, true)) {
                $chain[] = $candidate;
            }
        }

        return $chain;
    }

    /**
     * @param  array<string, TestResultArray>  $under
     * @return array<string, TestResultArray>
     */
    private function layer(array $under, string $branch): array
    {
        $results = $this->baselines[$branch]['results'] ?? [];

        if ($results === []) {
            return $under;
        }

        if (($this->baselines[$branch]['complete'] ?? false) === true) {
            $under = $this->withoutFilesCoveredBy($under, $results);
        }

        return array_replace($under, $results);
    }

    /**
     * @param  array<string, TestResultArray>  $results
     * @param  array<string, TestResultArray>  $authoritative
     * @return array<string, TestResultArray>
     */
    private function withoutFilesCoveredBy(array $results, array $authoritative): array
    {
        $covered = [];

        foreach ($authoritative as $entry) {
            $file = $entry['file'] ?? null;

            if (is_string($file) && $file !== '') {
                $covered[$file] = true;
            }
        }

        if ($covered === []) {
            return $results;
        }

        foreach ($results as $testId => $entry) {
            $file = $entry['file'] ?? null;

            if (is_string($file) && isset($covered[$file])) {
                unset($results[$testId]);
            }
        }

        return $results;
    }

    private function ensureBaseline(string $branch): void
    {
        $this->baselines[$branch] ??= ['sha' => null, 'results' => []];
    }

    public function pruneMissingTestFiles(): void
    {
        $known = array_unique(array_merge(
            array_keys($this->edges),
            array_keys($this->testTables),
        ));

        $edgesChanged = false;

        foreach ($known as $testRel) {
            if (is_file($this->absolute($testRel))) {
                continue;
            }

            if (isset($this->edges[$testRel])) {
                unset($this->edges[$testRel]);
                $edgesChanged = true;
            }

            unset($this->testTables[$testRel]);

            $this->notCacheable = array_values(array_diff($this->notCacheable, [$testRel]));
        }

        // `not_cacheable` may also hold `Class::method` ids (SPEC.md §8 rule 1, a
        // method-level attribute) alongside file paths (a class-level one): an id is not
        // a path `is_file()` could ever meaningfully check, so only file-shaped entries
        // are pruned here for being gone from disk.
        foreach ($this->notCacheable as $entry) {
            if (str_contains($entry, '::') || is_file($this->absolute($entry))) {
                continue;
            }

            $this->notCacheable = array_values(array_diff($this->notCacheable, [$entry]));
        }

        if ($edgesChanged) {
            $this->reverseIndex = null;
        }
    }

    /**
     * Drops a single dependency edge — leaving the rest of the test file's edges, and its
     * whole entry, untouched — when the dependency's own file no longer exists on disk.
     * Unlike {@see self::pruneMissingTestFiles()} (which drops a whole test's edge set
     * once ITS OWN file is gone), this targets one stale dependency at a time inside a
     * test file that still exists, e.g. after a real code change stopped a test from
     * needing something it used to.
     *
     * Deliberately narrow: an edge is dropped ONLY when its target is confirmed absent
     * from disk (`is_file()`), never merely because some recording pass did not
     * re-observe it. {@see self::unionEdges()}'s docblock explains why the latter would be
     * unsound: a coverage driver credits a file's declaration footprint to whichever test
     * happens to load it first in its worker, so which test "gets" a shared edge can vary
     * from one complete, full-suite pass to the next even when nothing about either test
     * changed — "not seen this run" is never evidence a dependency is gone, no matter how
     * many runs in a row it holds, so it is never used as a staleness signal here. "The
     * file itself does not exist" is a plain filesystem fact, independent of coverage
     * attribution or run order, and is the only signal this method trusts (SPEC.md §7.3,
     * docs/INTERNALS.md "pruning").
     *
     * @return int number of dependency edges removed
     */
    public function pruneMissingDependencies(): int
    {
        $removed = 0;

        foreach ($this->edges as $testRel => $ids) {
            $kept = [];

            foreach ($ids as $id) {
                $path = $this->files[$id] ?? null;

                if ($path !== null && ! is_file($this->absolute($path))) {
                    $removed++;

                    continue;
                }

                $kept[] = $id;
            }

            if (count($kept) !== count($ids)) {
                $this->edges[$testRel] = $kept;
            }
        }

        if ($removed > 0) {
            $this->reverseIndex = null;
        }

        return $removed;
    }

    public function pruneResultsForMissingFiles(string $branch): void
    {
        if (! isset($this->baselines[$branch]['results'])) {
            return;
        }

        foreach ($this->baselines[$branch]['results'] as $testId => $result) {
            $file = $result['file'] ?? null;

            if (! is_string($file) || $file === '') {
                continue;
            }

            $rel = $this->relative($file);

            if ($rel === null || is_file($this->absolute($rel))) {
                continue;
            }

            unset($this->baselines[$branch]['results'][$testId]);
        }
    }

    /** @param list<string> $keep */
    public function pruneMissingBranches(array $keep): void
    {
        $survivors = array_fill_keys($keep, true);

        foreach (array_keys($this->baselines) as $branch) {
            if (! isset($survivors[$branch])) {
                unset($this->baselines[$branch]);
            }
        }
    }

    /**
     * @param  list<string>  $touchedTestFiles
     * @param  list<string>  $keepTestIds
     */
    public function pruneStaleResults(string $branch, array $touchedTestFiles, array $keepTestIds): void
    {
        if (! isset($this->baselines[$branch]['results'])) {
            return;
        }

        $touched = [];

        foreach ($touchedTestFiles as $file) {
            $rel = $this->relative($file);

            if ($rel !== null) {
                $touched[$rel] = true;
            }
        }

        if ($touched === []) {
            return;
        }

        $keep = array_fill_keys($keepTestIds, true);

        foreach ($this->baselines[$branch]['results'] as $testId => $result) {
            $file = $result['file'] ?? null;

            if (! is_string($file) || ! isset($touched[$file])) {
                continue;
            }

            if (isset($keep[$testId])) {
                continue;
            }

            unset($this->baselines[$branch]['results'][$testId]);
        }
    }

    /** @return array{files:int, test_files:int, edges:int, branches:int, results:int, tables:int} */
    public function stats(): array
    {
        $edgeCount = 0;

        foreach ($this->edges as $ids) {
            $edgeCount += count(array_unique($ids));
        }

        $resultCount = 0;

        foreach ($this->baselines as $baseline) {
            $resultCount += count($baseline['results']);
        }

        $tables = [];

        foreach ($this->testTables as $names) {
            foreach ($names as $name) {
                $tables[$name] = true;
            }
        }

        return [
            'files' => count($this->files),
            'test_files' => count($this->edges),
            'edges' => $edgeCount,
            'branches' => count($this->baselines),
            'results' => $resultCount,
            // Distinct table names across every test file (Laravel, SPEC.md §10) — 0 on a
            // non-Laravel project.
            'tables' => count($tables),
        ];
    }

    public static function decode(string $json, string $projectRoot): ?self
    {
        $data = Json::decodeArray($json);

        if ($data === null || ($data['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }

        $graph = new self($projectRoot);
        $graph->fingerprint = self::decodeFingerprint($data['fingerprint'] ?? null);
        $graph->files = self::decodeFiles($data['files'] ?? null);
        $graph->fileIds = array_flip($graph->files);
        $graph->edges = self::decodeEdges($data['edges'] ?? null);
        $graph->testTables = self::decodeStringMap($data['test_tables'] ?? null);
        $graph->notCacheable = self::decodeStringList($data['not_cacheable'] ?? null);
        $graph->baselines = self::decodeBaselines($data['baselines'] ?? null);

        return $graph;
    }

    /** @return array<string, mixed> */
    private static function decodeFingerprint(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $out = [];

        foreach ($section as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function decodeFiles(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $files = [];

        foreach ($section as $path) {
            if (is_string($path) && $path !== '') {
                $files[] = $path;
            }
        }

        return $files;
    }

    /** @return array<string, list<int>> */
    private static function decodeEdges(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $edges = [];

        foreach ($section as $key => $ids) {
            $testFile = is_string($key) ? $key : (string) $key;

            if ($testFile === '' || ! is_array($ids)) {
                continue;
            }

            $clean = [];

            foreach ($ids as $id) {
                if (is_int($id)) {
                    $clean[] = $id;
                }
            }

            $edges[$testFile] = $clean;
        }

        return $edges;
    }

    /** @return array<string, list<string>> */
    private static function decodeStringMap(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $out = [];

        foreach ($section as $key => $values) {
            if (! is_string($key) || $key === '' || ! is_array($values)) {
                continue;
            }

            $names = [];

            foreach ($values as $value) {
                if (is_string($value) && $value !== '') {
                    $names[] = $value;
                }
            }

            if ($names !== []) {
                $out[$key] = $names;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function decodeStringList(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $out = [];

        foreach ($section as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, Baseline> */
    private static function decodeBaselines(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $baselines = [];

        foreach ($section as $key => $baseline) {
            $branch = is_string($key) ? $key : (string) $key;

            if ($branch === '' || ! is_array($baseline)) {
                continue;
            }

            $sha = $baseline['sha'] ?? null;

            $entry = [
                'sha' => is_string($sha) ? $sha : null,
                'results' => self::decodeResults($baseline['results'] ?? null),
            ];

            if (($baseline['complete'] ?? null) === true) {
                $entry['complete'] = true;
            }

            $baselines[$branch] = $entry;
        }

        return $baselines;
    }

    /** @return array<string, TestResultArray> */
    private static function decodeResults(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $results = [];

        foreach ($section as $key => $entry) {
            $testId = is_string($key) ? $key : (string) $key;

            if ($testId === '' || ! is_array($entry) || ! is_int($entry['s'] ?? null)) {
                continue;
            }

            $time = $entry['t'] ?? null;
            $assertions = $entry['a'] ?? null;

            $result = [
                'status' => $entry['s'],
                'message' => is_string($entry['m'] ?? null) ? $entry['m'] : '',
                'time' => (is_int($time) || is_float($time)) ? (float) $time : 0.0,
                'assertions' => is_int($assertions) ? $assertions : 0,
            ];

            if (is_string($entry['f'] ?? null) && $entry['f'] !== '') {
                $result['file'] = $entry['f'];
            }

            if (is_string($entry['k'] ?? null) && $entry['k'] !== '') {
                $result['key'] = $entry['k'];
            }

            $results[$testId] = $result;
        }

        return $results;
    }

    public function encode(): ?string
    {
        // A file id that no edge references any more (its only test was deleted —
        // pruneMissingTestFiles() — or its edge was dropped — pruneMissingDependencies(),
        // or a partial replaceEdges()) would otherwise be carried into `files` forever:
        // link() only ever adds, and nothing else in this class ever shrinks $this->files.
        // Filtering to what $this->edges still points to keeps graph.json bounded by what
        // is actually reachable, on every encode, not just under `prune`.
        //
        // Keyed by PATH, deliberately, not by file id — link() itself can never assign two
        // ids to the same path (it only mints a fresh one when the path is not already in
        // $fileIds), but decode() trusts a `files` JSON array verbatim with no
        // de-duplication (below), so a foreign/older/hand-edited graph.json can hold one
        // path under two ids. array_unique($this->files) then keeps the FIRST of the two
        // as that path's representative key, while $fileIds (array_flip($this->files))
        // keeps the LAST — so an edge recorded against whichever id array_unique did NOT
        // keep would silently vanish under an id-keyed filter (array_intersect_key
        // against $referenced), instead of being remapped like every other live edge.
        // Resolving each referenced id back to its path first, and filtering on path
        // membership, makes it irrelevant which of the two raw ids "won".
        $referencedPaths = [];

        foreach ($this->edges as $ids) {
            foreach ($ids as $id) {
                if (isset($this->files[$id])) {
                    $referencedPaths[$this->files[$id]] = true;
                }
            }
        }

        $live = array_filter(
            array_unique($this->files),
            static fn (string $path): bool => isset($referencedPaths[$path]),
        );

        $sortedFiles = array_values($live);
        sort($sortedFiles);

        $remap = [];

        foreach ($sortedFiles as $newId => $path) {
            $oldId = $this->fileIds[$path] ?? null;

            if ($oldId !== null) {
                $remap[$oldId] = $newId;
            }
        }

        $edges = [];

        foreach ($this->edges as $testFile => $ids) {
            $mapped = [];

            foreach ($ids as $id) {
                if (isset($remap[$id])) {
                    $mapped[] = $remap[$id];
                }
            }

            $mapped = array_values(array_unique($mapped));
            sort($mapped);

            $edges[$testFile] = $mapped;
        }

        ksort($edges);

        $testTables = $this->testTables;
        ksort($testTables);

        foreach ($testTables as &$tables) {
            sort($tables);
        }

        unset($tables);

        $notCacheable = array_values(array_unique($this->notCacheable));
        sort($notCacheable);

        $baselines = [];

        foreach ($this->baselines as $branch => $baseline) {
            $entry = [
                'sha' => $baseline['sha'] ?? null,
                'results' => [],
            ];

            if (($baseline['complete'] ?? false) === true) {
                $entry['complete'] = true;
            }

            $results = $baseline['results'];
            ksort($results);

            foreach ($results as $testId => $result) {
                $short = [
                    's' => $result['status'],
                    'a' => $result['assertions'],
                    't' => $result['time'],
                    'm' => $result['message'],
                ];

                if (isset($result['file'])) {
                    $short['f'] = $result['file'];
                }

                if (isset($result['key'])) {
                    $short['k'] = $result['key'];
                }

                $entry['results'][$testId] = $short;
            }

            $baselines[$branch] = $entry;
        }

        ksort($baselines);

        $payload = [
            'schema' => self::SCHEMA,
            'generator' => 'manuglopez/phpunit-replay ' . Version::id(),
            'fingerprint' => $this->fingerprint,
            'files' => $sortedFiles,
            'edges' => $edges,
            'test_tables' => $testTables,
            'not_cacheable' => $notCacheable,
            'baselines' => $baselines,
        ];

        return Json::encode($payload);
    }

    private function absolute(string $relative): string
    {
        return rtrim($this->projectRoot, '/') . '/' . $relative;
    }
}
