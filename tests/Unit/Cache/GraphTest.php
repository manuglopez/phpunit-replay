<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Tests\Support\TempDir;
use Manuglopez\Replay\Version;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type TestResultArray from Graph
 */
final class GraphTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('graph');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /** @return TestResultArray */
    private function makeResult(
        int $status = 0,
        string $message = '',
        float $time = 0.1,
        int $assertions = 1,
        ?string $file = null,
        ?string $key = null,
    ): array {
        $result = [
            'status' => $status,
            'message' => $message,
            'time' => $time,
            'assertions' => $assertions,
        ];

        if ($file !== null) {
            $result['file'] = $file;
        }

        if ($key !== null) {
            $result['key'] = $key;
        }

        return $result;
    }

    private function write(string $relative, string $content = '<?php'): string
    {
        $path = $this->root . '/' . $relative;
        TempDir::write($path, $content);

        return $path;
    }

    // -- link() / files / edges -------------------------------------------------

    public function test_link_registers_a_file_and_an_edge(): void
    {
        $testFile = $this->write('tests/Feature/FooTest.php');
        $sourceFile = $this->write('app/Foo.php');

        $graph = new Graph($this->root);
        $graph->link($testFile, $sourceFile);

        self::assertSame(['app/Foo.php'], $graph->files());
        self::assertSame(0, $graph->fileId('app/Foo.php'));
        self::assertTrue($graph->knowsTest('tests/Feature/FooTest.php'));
        self::assertSame(['app/Foo.php'], $graph->dependenciesOf('tests/Feature/FooTest.php'));
    }

    public function test_link_accepts_relative_paths_directly(): void
    {
        $this->write('tests/Feature/FooTest.php');
        $this->write('app/Foo.php');

        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'app/Foo.php');

        self::assertSame(['app/Foo.php'], $graph->files());
        self::assertTrue($graph->knowsTest('tests/Feature/FooTest.php'));
    }

    public function test_link_ignores_vendor_source_paths(): void
    {
        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'vendor/pkg/src/Thing.php');

        self::assertSame([], $graph->files());
        self::assertFalse($graph->knowsTest('tests/Feature/FooTest.php'));
    }

    public function test_link_ignores_paths_outside_project_root(): void
    {
        $outside = TempDir::make('outside');

        try {
            $graph = new Graph($this->root);
            $graph->link('tests/Feature/FooTest.php', $outside . '/Thing.php');

            self::assertSame([], $graph->files());
            self::assertFalse($graph->knowsTest('tests/Feature/FooTest.php'));
        } finally {
            TempDir::remove($outside);
        }
    }

    public function test_replace_edges_replaces_rather_than_unions_and_dedups(): void
    {
        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'app/Old.php');

        $graph->replaceEdges([
            'tests/Feature/FooTest.php' => ['app/New.php', 'app/New.php', 'app/Other.php'],
        ]);

        self::assertSame(['app/Old.php', 'app/New.php', 'app/Other.php'], $graph->files());
        self::assertEqualsCanonicalizing(
            ['app/New.php', 'app/Other.php'],
            $graph->dependenciesOf('tests/Feature/FooTest.php'),
        );
    }

    public function test_mark_known_test_files_makes_knows_test_true_with_empty_dependencies(): void
    {
        $graph = new Graph($this->root);

        self::assertFalse($graph->knowsTest('tests/Unit/ExampleTest.php'));

        $graph->markKnownTestFiles(['tests/Unit/ExampleTest.php']);

        self::assertTrue($graph->knowsTest('tests/Unit/ExampleTest.php'));
        self::assertSame([], $graph->dependenciesOf('tests/Unit/ExampleTest.php'));
    }

    public function test_mark_known_test_files_does_not_clobber_existing_edges(): void
    {
        $graph = new Graph($this->root);
        $graph->link('tests/Feature/UserTest.php', 'app/Models/User.php');

        $graph->markKnownTestFiles(['tests/Feature/UserTest.php']);

        self::assertSame(['app/Models/User.php'], $graph->dependenciesOf('tests/Feature/UserTest.php'));
    }

    public function test_mark_known_test_files_ignores_paths_outside_root(): void
    {
        $graph = new Graph($this->root);
        $graph->markKnownTestFiles(['/somewhere/else/tests/FooTest.php']);

        self::assertFalse($graph->knowsTest('/somewhere/else/tests/FooTest.php'));
    }

    public function test_all_test_files_lists_known_test_files(): void
    {
        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'app/Foo.php');
        $graph->markKnownTestFiles(['tests/Unit/BarTest.php']);

        self::assertEqualsCanonicalizing(
            ['tests/Feature/FooTest.php', 'tests/Unit/BarTest.php'],
            $graph->allTestFiles(),
        );
    }

    public function test_test_files_depending_on_reverse_index_is_correct_after_replace_edges(): void
    {
        $graph = new Graph($this->root);
        $graph->replaceEdges([
            'tests/Feature/ATest.php' => ['app/Shared.php', 'app/Only1.php'],
            'tests/Feature/BTest.php' => ['app/Shared.php'],
        ]);

        self::assertEqualsCanonicalizing(
            ['tests/Feature/ATest.php', 'tests/Feature/BTest.php'],
            $graph->testFilesDependingOn('app/Shared.php'),
        );
        self::assertSame(['tests/Feature/ATest.php'], $graph->testFilesDependingOn('app/Only1.php'));

        // Reverse index is invalidated by a subsequent write.
        $graph->replaceEdges([
            'tests/Feature/ATest.php' => ['app/Only1.php'],
        ]);

        self::assertSame(['tests/Feature/BTest.php'], $graph->testFilesDependingOn('app/Shared.php'));
        self::assertSame(['tests/Feature/ATest.php'], $graph->testFilesDependingOn('app/Only1.php'));
    }

    public function test_test_files_depending_on_unknown_file_is_empty(): void
    {
        $graph = new Graph($this->root);

        self::assertSame([], $graph->testFilesDependingOn('app/Nothing.php'));
    }

    // -- tables / hermeticity -----------------------------------------------

    public function test_replace_test_tables_normalises_lowercases_sorts_and_dedups(): void
    {
        $graph = new Graph($this->root);
        $graph->replaceTestTables([
            'tests/Feature/OrderTest.php' => ['Orders', 'USERS', 'orders', ''],
        ]);

        self::assertSame(
            ['orders', 'users'],
            $graph->testTables()['tests/Feature/OrderTest.php'],
        );
    }

    public function test_set_not_cacheable_replaces_and_normalises(): void
    {
        $graph = new Graph($this->root);
        $graph->setNotCacheable(['tests/Feature/ExternalApiTest.php']);

        self::assertSame(['tests/Feature/ExternalApiTest.php'], $graph->notCacheable());

        $graph->setNotCacheable(['tests/Feature/OtherTest.php']);

        self::assertSame(['tests/Feature/OtherTest.php'], $graph->notCacheable());
    }

    // -- fingerprint -----------------------------------------------------------

    public function test_fingerprint_setter_and_getter(): void
    {
        $graph = new Graph($this->root);
        $fingerprint = ['structural' => ['schema' => 1], 'environmental' => ['php' => '8.4']];

        $graph->setFingerprint($fingerprint);

        self::assertSame($fingerprint, $graph->fingerprint());
    }

    // -- baselines ---------------------------------------------------------

    public function test_default_branch_setter_getter_and_default_value(): void
    {
        $graph = new Graph($this->root);

        self::assertSame('main', $graph->defaultBranch());

        $graph->setDefaultBranch('trunk');

        self::assertSame('trunk', $graph->defaultBranch());
    }

    public function test_branches_lists_known_baselines(): void
    {
        $graph = new Graph($this->root);
        $graph->setRecordedSha('main', 'a');
        $graph->setRecordedSha('feature', 'b');

        self::assertEqualsCanonicalizing(['main', 'feature'], $graph->branches());
    }

    public function test_recorded_sha_falls_back_to_default_branch(): void
    {
        $graph = new Graph($this->root);
        $graph->setDefaultBranch('main');
        $graph->setRecordedSha('main', 'sha-main');

        self::assertSame('sha-main', $graph->recordedSha('main'));
        self::assertSame('sha-main', $graph->recordedSha('feature'));

        $graph->setRecordedSha('feature', 'sha-feature');

        self::assertSame('sha-feature', $graph->recordedSha('feature'));
    }

    public function test_recorded_sha_is_null_when_nothing_recorded(): void
    {
        $graph = new Graph($this->root);

        self::assertNull($graph->recordedSha('main'));
    }

    public function test_is_baseline_complete_and_mark_complete(): void
    {
        $graph = new Graph($this->root);

        self::assertFalse($graph->isBaselineComplete('main'));

        $graph->markBaselineComplete('main');

        self::assertTrue($graph->isBaselineComplete('main'));
        self::assertFalse($graph->isBaselineComplete('other'));
    }

    public function test_set_result_and_own_results(): void
    {
        $graph = new Graph($this->root);
        $result = $this->makeResult(status: 0, file: 'tests/Feature/FooTest.php');

        $graph->setResult('main', 'Tests\FooTest::it_passes', $result);

        self::assertSame($result, $graph->result('main', 'Tests\FooTest::it_passes'));
        self::assertSame(['Tests\FooTest::it_passes' => $result], $graph->ownResults('main'));
        self::assertNull($graph->result('main', 'unknown'));
    }

    public function test_result_merge_on_incomplete_branch_uses_array_replace(): void
    {
        $graph = new Graph($this->root);
        $graph->setDefaultBranch('main');

        $graph->setResult('main', 'T1', $this->makeResult(status: 0, file: 'tests/FooTest.php'));
        $graph->setResult('main', 'T2', $this->makeResult(status: 0, file: 'tests/BarTest.php'));

        $graph->setResult('feature', 'T2', $this->makeResult(status: 7, message: 'boom', file: 'tests/BarTest.php'));

        $results = $graph->results('feature');

        self::assertSame(0, $results['T1']['status']);
        self::assertSame(7, $results['T2']['status']);
        self::assertSame('boom', $results['T2']['message']);
    }

    public function test_result_merge_on_complete_branch_uses_default_only_for_uncovered_files(): void
    {
        $graph = new Graph($this->root);
        $graph->setDefaultBranch('main');

        $graph->setResult('main', 'T1', $this->makeResult(status: 0, file: 'tests/FooTest.php'));
        $graph->setResult('main', 'T2', $this->makeResult(status: 0, file: 'tests/BarTest.php'));

        // The feature branch itself recorded a (renamed) test in FooTest.php,
        // so it "completely" covers that file — main's T1 must not leak through.
        $graph->setResult('feature', 'T3', $this->makeResult(status: 0, file: 'tests/FooTest.php'));
        $graph->markBaselineComplete('feature');

        $results = $graph->results('feature');

        self::assertArrayNotHasKey('T1', $results);
        self::assertArrayHasKey('T2', $results);
        self::assertArrayHasKey('T3', $results);
    }

    public function test_own_results_are_unaffected_by_merge(): void
    {
        $graph = new Graph($this->root);
        $graph->setResult('main', 'T1', $this->makeResult());
        $graph->setResult('feature', 'T2', $this->makeResult());

        self::assertSame(['T1'], array_keys($graph->ownResults('main')));
        self::assertSame(['T2'], array_keys($graph->ownResults('feature')));
    }

    public function test_clear_results_for_a_specific_branch(): void
    {
        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/FooTest.php' => ['app/Foo.php']]);
        $graph->setResult('main', 'T1', $this->makeResult());
        $graph->setResult('feature', 'T2', $this->makeResult());

        $graph->clearResults('main');

        self::assertSame([], $graph->ownResults('main'));
        self::assertSame(['T2'], array_keys($graph->ownResults('feature')));
        self::assertSame(['app/Foo.php'], $graph->dependenciesOf('tests/FooTest.php'));
    }

    public function test_clear_results_for_all_branches_keeps_edges(): void
    {
        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/FooTest.php' => ['app/Foo.php']]);
        $graph->setResult('main', 'T1', $this->makeResult());
        $graph->setResult('feature', 'T2', $this->makeResult());

        $graph->clearResults();

        self::assertSame([], $graph->ownResults('main'));
        self::assertSame([], $graph->ownResults('feature'));
        self::assertSame(['app/Foo.php'], $graph->dependenciesOf('tests/FooTest.php'));
        self::assertSame(['tests/FooTest.php'], $graph->allTestFiles());
    }

    // -- pruning -------------------------------------------------------------

    public function test_prune_missing_test_files_also_drops_tables_and_not_cacheable(): void
    {
        $this->write('tests/Feature/KeepTest.php');
        $goneAbsolute = $this->write('tests/Feature/GoneTest.php');

        $graph = new Graph($this->root);
        $graph->link('tests/Feature/KeepTest.php', 'app/Foo.php');
        $graph->link('tests/Feature/GoneTest.php', 'app/Bar.php');
        $graph->replaceTestTables([
            'tests/Feature/KeepTest.php' => ['keep_table'],
            'tests/Feature/GoneTest.php' => ['gone_table'],
        ]);
        $graph->setNotCacheable(['tests/Feature/KeepTest.php', 'tests/Feature/GoneTest.php']);

        unlink($goneAbsolute);

        $graph->pruneMissingTestFiles();

        self::assertTrue($graph->knowsTest('tests/Feature/KeepTest.php'));
        self::assertFalse($graph->knowsTest('tests/Feature/GoneTest.php'));
        self::assertArrayHasKey('tests/Feature/KeepTest.php', $graph->testTables());
        self::assertArrayNotHasKey('tests/Feature/GoneTest.php', $graph->testTables());
        self::assertSame(['tests/Feature/KeepTest.php'], $graph->notCacheable());
    }

    /**
     * pruneMissingTestFiles() runs on every ordinary `run`/`record` where anything
     * changed (RunPipeline.php:1017, ReplayState.php:772) — not only under
     * `prune --stale-edges` — so a deleted test file orphaning its only dependency is
     * the everyday trigger for a `files` entry nothing references any more, not a rare
     * `prune`-only case. See encode()'s own orphan-filtering and this change's commit
     * message.
     */
    public function test_encode_drops_a_dependency_orphaned_by_a_deleted_test_files_own_edges(): void
    {
        $this->write('tests/Feature/KeepTest.php');
        $goneAbsolute = $this->write('tests/Feature/GoneTest.php');
        $this->write('app/Shared.php');
        $this->write('app/OnlyGoneUsed.php');

        $graph = new Graph($this->root);
        $graph->link('tests/Feature/KeepTest.php', 'app/Shared.php');
        $graph->link('tests/Feature/GoneTest.php', 'app/OnlyGoneUsed.php');

        unlink($goneAbsolute);

        $graph->pruneMissingTestFiles();

        $json = $graph->encode();
        self::assertNotNull($json);

        $raw = json_decode($json, true);
        self::assertIsArray($raw);
        self::assertSame(['app/Shared.php'], $raw['files']);
    }

    public function test_prune_missing_dependencies_drops_only_the_edge_whose_file_is_gone(): void
    {
        $this->write('tests/Feature/FooTest.php');
        $this->write('app/Kept.php');
        $goneAbsolute = $this->write('app/Gone.php');

        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'app/Kept.php');
        $graph->link('tests/Feature/FooTest.php', 'app/Gone.php');

        unlink($goneAbsolute);

        $removed = $graph->pruneMissingDependencies();

        self::assertSame(1, $removed);
        self::assertSame(['app/Kept.php'], $graph->dependenciesOf('tests/Feature/FooTest.php'));
        self::assertTrue($graph->knowsTest('tests/Feature/FooTest.php'));
    }

    public function test_encode_drops_a_dependency_orphaned_by_prune_missing_dependencies(): void
    {
        $this->write('tests/Feature/FooTest.php');
        $this->write('app/Kept.php');
        $goneAbsolute = $this->write('app/Gone.php');

        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'app/Kept.php');
        $graph->link('tests/Feature/FooTest.php', 'app/Gone.php');

        unlink($goneAbsolute);
        $graph->pruneMissingDependencies();

        $json = $graph->encode();
        self::assertNotNull($json);

        $raw = json_decode($json, true);
        self::assertIsArray($raw);

        // app/Gone.php has no edge pointing to it any more: it must not be carried into
        // the persisted graph forever just because it was linked once, unlike
        // app/Kept.php, which tests/Feature/FooTest.php still depends on.
        self::assertSame(['app/Kept.php'], $raw['files']);
    }

    public function test_prune_missing_dependencies_keeps_the_test_entry_when_every_dependency_is_gone(): void
    {
        $this->write('tests/Feature/FooTest.php');
        $goneAbsolute = $this->write('app/Gone.php');

        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'app/Gone.php');

        unlink($goneAbsolute);

        $removed = $graph->pruneMissingDependencies();

        self::assertSame(1, $removed);
        self::assertSame([], $graph->dependenciesOf('tests/Feature/FooTest.php'));
        self::assertTrue($graph->knowsTest('tests/Feature/FooTest.php'));
    }

    /**
     * The safety invariant the whole feature rests on: a dependency is only ever dropped
     * because its file is confirmed gone from disk, never because a pass simply didn't
     * re-observe it (coverage attribution is first-loader-wins and varies run to run —
     * see Graph::unionEdges()'s docblock — so "not seen this run" is never evidence a
     * dependency is gone).
     */
    public function test_prune_missing_dependencies_keeps_edges_whose_files_still_exist(): void
    {
        $this->write('tests/Feature/FooTest.php');
        $this->write('app/Foo.php');
        $this->write('app/Bar.php');

        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'app/Foo.php');
        $graph->link('tests/Feature/FooTest.php', 'app/Bar.php');

        $removed = $graph->pruneMissingDependencies();

        self::assertSame(0, $removed);
        self::assertEqualsCanonicalizing(
            ['app/Foo.php', 'app/Bar.php'],
            $graph->dependenciesOf('tests/Feature/FooTest.php'),
        );
    }

    public function test_prune_missing_dependencies_invalidates_the_memoised_reverse_index(): void
    {
        $this->write('tests/Feature/FooTest.php');
        $goneAbsolute = $this->write('app/Gone.php');

        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'app/Gone.php');

        // Force the reverse index to memoise before pruning.
        self::assertSame(['tests/Feature/FooTest.php'], $graph->testFilesDependingOn('app/Gone.php'));

        unlink($goneAbsolute);
        $graph->pruneMissingDependencies();

        self::assertSame([], $graph->testFilesDependingOn('app/Gone.php'));
    }

    public function test_prune_results_for_missing_files(): void
    {
        $this->write('tests/Feature/KeepTest.php');
        $goneAbsolute = $this->write('tests/Feature/GoneTest.php');

        $graph = new Graph($this->root);
        $graph->setResult('main', 'Keep::it_works', $this->makeResult(file: 'tests/Feature/KeepTest.php'));
        $graph->setResult('main', 'Gone::it_worked', $this->makeResult(file: 'tests/Feature/GoneTest.php'));
        $graph->setResult('main', 'NoFile::it_works', $this->makeResult());

        unlink($goneAbsolute);

        $graph->pruneResultsForMissingFiles('main');

        $own = $graph->ownResults('main');
        self::assertArrayHasKey('Keep::it_works', $own);
        self::assertArrayNotHasKey('Gone::it_worked', $own);
        self::assertArrayHasKey('NoFile::it_works', $own);
    }

    public function test_prune_missing_branches(): void
    {
        $graph = new Graph($this->root);
        $graph->setRecordedSha('main', 'a');
        $graph->setRecordedSha('feature', 'b');
        $graph->setRecordedSha('stale', 'c');

        $graph->pruneMissingBranches(['main', 'feature']);

        self::assertEqualsCanonicalizing(['main', 'feature'], $graph->branches());
    }

    public function test_prune_stale_results_deletes_touched_files_not_kept(): void
    {
        $this->write('tests/Feature/FooTest.php');

        $graph = new Graph($this->root);
        $graph->setResult('main', 'T1', $this->makeResult(file: 'tests/Feature/FooTest.php'));
        $graph->setResult('main', 'T2', $this->makeResult(file: 'tests/Feature/FooTest.php'));
        $graph->setResult('main', 'T3', $this->makeResult(file: 'tests/Feature/BarTest.php'));

        $graph->pruneStaleResults('main', ['tests/Feature/FooTest.php'], ['T1']);

        $own = $graph->ownResults('main');
        self::assertArrayHasKey('T1', $own);
        self::assertArrayNotHasKey('T2', $own);
        self::assertArrayHasKey('T3', $own);
    }

    // -- addressable keys --------------------------------------------------------

    public function test_addressable_keys_spans_every_branch_and_deduplicates(): void
    {
        $graph = new Graph($this->root);
        $graph->setDefaultBranch('main');

        $graph->setResult('main', 'T1', $this->makeResult(file: 'tests/FooTest.php', key: 'key-a'));
        $graph->setResult('main', 'T2', $this->makeResult(file: 'tests/BarTest.php', key: 'key-b'));
        // A branch that is neither the default nor (in a caller's terms) the one currently
        // checked out — still counts (docs/proposals/remote-layout.md: "every baseline the
        // local graph holds, not just the current branch").
        $graph->setResult('feature', 'T3', $this->makeResult(file: 'tests/BazTest.php', key: 'key-b'));
        $graph->setResult('feature', 'T4', $this->makeResult(file: 'tests/QuxTest.php', key: 'key-c'));
        // Never published anywhere (e.g. no remote configured) — contributes nothing.
        $graph->setResult('feature', 'T5', $this->makeResult(file: 'tests/NoKeyTest.php'));

        $keys = $graph->addressableKeys();
        sort($keys);

        self::assertSame(['key-a', 'key-b', 'key-c'], $keys);
    }

    public function test_addressable_keys_is_empty_for_a_fresh_graph(): void
    {
        self::assertSame([], (new Graph($this->root))->addressableKeys());
    }

    // -- stats -----------------------------------------------------------------

    public function test_stats_reports_expected_counters(): void
    {
        $graph = new Graph($this->root);
        $graph->replaceEdges([
            'tests/FooTest.php' => ['app/A.php', 'app/B.php'],
            'tests/BarTest.php' => ['app/A.php'],
        ]);
        $graph->setResult('main', 'T1', $this->makeResult());
        $graph->setResult('main', 'T2', $this->makeResult());
        $graph->setResult('feature', 'T3', $this->makeResult());
        $graph->replaceTestTables([
            'tests/FooTest.php' => ['users', 'posts'],
            'tests/BarTest.php' => ['users'],
        ]);

        self::assertSame([
            'files' => 2,
            'test_files' => 2,
            'edges' => 3,
            'branches' => 2,
            'results' => 3,
            'tables' => 2,
        ], $graph->stats());
    }

    /**
     * Deliberate scope boundary, not an oversight (see this change's commit message):
     * pruning removes an edge, never the file id it pointed to, so files()/stats() keep
     * counting an orphan until the next encode() — only the persisted graph.json, and a
     * graph decoded back from it, are ever guaranteed free of one. `files()`/`stats()`
     * are not compacted eagerly because (a) no current caller ever reads them between a
     * prune and a save on the same Graph instance — the two call sites that print
     * `stats()['files']` (RunPipeline::printRecordSummary(), ReplayState's Record mode)
     * only ever run against a freshly built graph that cannot yet contain an orphan, and
     * (b) test_replace_edges_replaces_rather_than_unions_and_dedups() above already locks
     * in files() returning every path ever linked, orphaned or not, as replaceEdges()'s
     * existing contract. This test pins that boundary so a future change doesn't
     * silently cross it.
     */
    public function test_stats_files_counter_still_counts_an_orphan_until_the_next_encode(): void
    {
        $this->write('tests/Feature/FooTest.php');
        $goneAbsolute = $this->write('app/Gone.php');

        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'app/Gone.php');

        unlink($goneAbsolute);
        $graph->pruneMissingDependencies();

        self::assertSame(['app/Gone.php'], $graph->files());
        self::assertSame(1, $graph->stats()['files']);

        $decoded = Graph::decode((string) $graph->encode(), $this->root);
        self::assertNotNull($decoded);
        self::assertSame([], $decoded->files());
        self::assertSame(0, $decoded->stats()['files']);
    }

    // -- codec -------------------------------------------------------------

    public function test_encode_decode_round_trip_preserves_all_sections(): void
    {
        $graph = new Graph($this->root);
        $graph->setFingerprint(['structural' => ['schema' => 1, 'composer_lock' => 'abc'], 'environmental' => ['php' => '8.4']]);
        $graph->replaceEdges([
            'tests/Feature/FooTest.php' => ['app/Foo.php', 'app/Bar.php'],
        ]);
        $graph->replaceTestTables(['tests/Feature/FooTest.php' => ['foos']]);
        $graph->setNotCacheable(['tests/Feature/ExternalTest.php']);
        $graph->setRecordedSha('main', '40charshaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $graph->setResult('main', 'Tests\FooTest::it_works', $this->makeResult(
            status: 0,
            message: '',
            time: 0.042,
            assertions: 3,
            file: 'tests/Feature/FooTest.php',
            key: 'deadbeef',
        ));
        $graph->markBaselineComplete('main');

        $json = $graph->encode();
        self::assertNotNull($json);

        $raw = json_decode($json, true);
        self::assertIsArray($raw);
        $encodedResult = $raw['baselines']['main']['results']['Tests\FooTest::it_works'];
        self::assertSame(0, $encodedResult['s']);
        self::assertSame(3, $encodedResult['a']);
        self::assertSame(0.042, $encodedResult['t']);
        self::assertSame('', $encodedResult['m']);
        self::assertSame('tests/Feature/FooTest.php', $encodedResult['f']);
        self::assertSame('deadbeef', $encodedResult['k']);
        self::assertTrue($raw['baselines']['main']['complete']);
        self::assertSame(['tests/Feature/ExternalTest.php'], $raw['not_cacheable']);
        self::assertSame('manuglopez/phpunit-replay ' . Version::id(), $raw['generator']);

        $decoded = Graph::decode($json, $this->root);
        self::assertNotNull($decoded);

        self::assertSame($graph->fingerprint(), $decoded->fingerprint());
        self::assertEqualsCanonicalizing($graph->files(), $decoded->files());
        self::assertEqualsCanonicalizing(
            $graph->dependenciesOf('tests/Feature/FooTest.php'),
            $decoded->dependenciesOf('tests/Feature/FooTest.php'),
        );
        self::assertSame($graph->testTables(), $decoded->testTables());
        self::assertSame($graph->notCacheable(), $decoded->notCacheable());
        self::assertSame($graph->recordedSha('main'), $decoded->recordedSha('main'));
        self::assertTrue($decoded->isBaselineComplete('main'));
        self::assertSame(
            $graph->result('main', 'Tests\FooTest::it_works'),
            $decoded->result('main', 'Tests\FooTest::it_works'),
        );
    }

    /**
     * Regression found in review: encode()'s orphan filter must key on PATH, not file id.
     * Graph::link() can never itself produce two ids for the same path — it only mints a
     * fresh one when the path is not already in $fileIds — so the only route to this
     * state (which is why this test goes in through decode() with a hand-built payload,
     * rather than through the normal link()/replaceEdges() API) is decode() trusting a
     * `files` JSON array verbatim with no de-duplication (Graph.php's decode()): a
     * graph.json from a foreign or older writer, or a hand-edited one, can hold one path
     * under two ids. Here 'dup.php' is both id 1 and id 2; the edge references id 2.
     * array_unique($this->files) keeps id 1 as 'dup.php''s representative (first
     * occurrence); array_flip($this->files) — what decode() builds $fileIds from — keeps
     * id 2 (last occurrence). An id-keyed filter checks whether id 1 is referenced, finds
     * it is not (only id 2 is), and silently drops the edge instead of remapping it.
     */
    public function test_encode_keeps_an_edge_to_a_path_duplicated_under_two_ids(): void
    {
        $json = json_encode([
            'schema' => 1,
            'generator' => 'test',
            'fingerprint' => [],
            'files' => ['a.php', 'dup.php', 'dup.php'],
            'edges' => ['tests/Feature/FooTest.php' => [0, 2]],
            'test_tables' => [],
            'not_cacheable' => [],
            'baselines' => [],
        ]);
        self::assertIsString($json);

        $graph = Graph::decode($json, $this->root);
        self::assertNotNull($graph);
        self::assertEqualsCanonicalizing(
            ['a.php', 'dup.php'],
            $graph->dependenciesOf('tests/Feature/FooTest.php'),
        );

        $reEncoded = $graph->encode();
        self::assertNotNull($reEncoded);

        $raw = json_decode($reEncoded, true);
        self::assertIsArray($raw);
        self::assertEqualsCanonicalizing(['a.php', 'dup.php'], $raw['files']);
        self::assertEqualsCanonicalizing([0, 1], $raw['edges']['tests/Feature/FooTest.php']);

        $decoded = Graph::decode($reEncoded, $this->root);
        self::assertNotNull($decoded);
        self::assertEqualsCanonicalizing(
            ['a.php', 'dup.php'],
            $decoded->dependenciesOf('tests/Feature/FooTest.php'),
        );
    }

    public function test_decode_returns_null_for_invalid_json(): void
    {
        self::assertNull(Graph::decode('{not valid json', $this->root));
    }

    public function test_decode_returns_null_for_non_array_json(): void
    {
        self::assertNull(Graph::decode('"just a string"', $this->root));
    }

    public function test_decode_returns_null_when_schema_does_not_match(): void
    {
        $json = json_encode(['schema' => 2, 'files' => [], 'edges' => [], 'baselines' => []]);
        self::assertIsString($json);

        self::assertNull(Graph::decode($json, $this->root));
    }

    public function test_decode_treats_non_array_files_section_as_empty(): void
    {
        $json = json_encode(['schema' => 1, 'files' => 'oops', 'edges' => [], 'baselines' => []]);
        self::assertIsString($json);

        $graph = Graph::decode($json, $this->root);

        self::assertNotNull($graph);
        self::assertSame([], $graph->files());
    }

    public function test_decode_skips_non_int_edge_ids_but_keeps_valid_ones(): void
    {
        $json = json_encode([
            'schema' => 1,
            'files' => ['app/A.php', 'app/B.php'],
            'edges' => ['tests/FooTest.php' => [0, 'bad', 1]],
            'baselines' => [],
        ]);
        self::assertIsString($json);

        $graph = Graph::decode($json, $this->root);

        self::assertNotNull($graph);
        self::assertSame(['app/A.php', 'app/B.php'], $graph->dependenciesOf('tests/FooTest.php'));
    }

    public function test_decode_skips_results_with_non_int_status(): void
    {
        $json = json_encode([
            'schema' => 1,
            'files' => [],
            'edges' => [],
            'baselines' => [
                'main' => [
                    'sha' => null,
                    'results' => [
                        'Valid::test' => ['s' => 0, 'a' => 1, 't' => 0.1, 'm' => ''],
                        'Invalid::test' => ['s' => 'zero', 'a' => 1, 't' => 0.1, 'm' => ''],
                    ],
                ],
            ],
        ]);
        self::assertIsString($json);

        $graph = Graph::decode($json, $this->root);

        self::assertNotNull($graph);
        $own = $graph->ownResults('main');
        self::assertArrayHasKey('Valid::test', $own);
        self::assertArrayNotHasKey('Invalid::test', $own);
    }

    public function test_decode_skips_a_baseline_that_is_a_string(): void
    {
        $json = json_encode([
            'schema' => 1,
            'files' => [],
            'edges' => [],
            'baselines' => [
                'main' => 'oops',
                'dev' => ['sha' => null, 'results' => []],
            ],
        ]);
        self::assertIsString($json);

        $graph = Graph::decode($json, $this->root);

        self::assertNotNull($graph);
        self::assertSame(['dev'], $graph->branches());
    }

    public function test_encode_output_is_stable_regardless_of_insertion_order(): void
    {
        $graphA = new Graph($this->root);
        $graphA->replaceEdges([
            'tests/ATest.php' => ['app/C.php', 'app/B.php'],
            'tests/BTest.php' => ['app/C.php'],
        ]);
        $graphA->replaceTestTables(['tests/ATest.php' => ['zebra', 'alpha']]);
        $graphA->setNotCacheable(['tests/BTest.php', 'tests/ATest.php']);
        $graphA->setResult('main', 'T2', $this->makeResult(status: 1));
        $graphA->setResult('main', 'T1', $this->makeResult(status: 0));
        $graphA->setResult('feature', 'T3', $this->makeResult(status: 0));

        $graphB = new Graph($this->root);
        $graphB->replaceEdges([
            'tests/BTest.php' => ['app/C.php'],
            'tests/ATest.php' => ['app/B.php', 'app/C.php'],
        ]);
        $graphB->replaceTestTables(['tests/ATest.php' => ['alpha', 'zebra']]);
        $graphB->setNotCacheable(['tests/ATest.php', 'tests/BTest.php']);
        $graphB->setResult('feature', 'T3', $this->makeResult(status: 0));
        $graphB->setResult('main', 'T1', $this->makeResult(status: 0));
        $graphB->setResult('main', 'T2', $this->makeResult(status: 1));

        self::assertSame($graphA->encode(), $graphB->encode());
    }
}
