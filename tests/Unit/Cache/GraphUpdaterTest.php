<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphUpdater;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class GraphUpdaterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('graph-updater');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        parent::tearDown();
    }

    private function write(string $relative, string $content = "<?php\n"): void
    {
        TempDir::write($this->root . '/' . $relative, $content);
    }

    /** @param array{status:int, message:string, time:float, assertions:int, file?:string} $result */
    private function makeResult(int $status = 0, string $file = '', string $message = ''): array
    {
        $result = ['status' => $status, 'message' => $message, 'time' => 0.01, 'assertions' => 1];

        if ($file !== '') {
            $result['file'] = $file;
        }

        return $result;
    }

    private function updater(Graph $graph, ?Quarantine $quarantine = null): GraphUpdater
    {
        return new GraphUpdater($graph, $this->root, new ContentKey($this->root), $quarantine);
    }

    public function test_apply_with_edges_and_results_sets_edges_results_and_keys(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('src/Foo.php');

        $graph = new Graph($this->root);
        $partial = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it_works' => $this->makeResult(file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
        );

        $summary = $this->updater($graph)->apply($partial, 'main', recordsEdges: true, complete: false);

        self::assertTrue($graph->knowsTest('tests/FooTest.php'));
        self::assertSame(['src/Foo.php'], $graph->dependenciesOf('tests/FooTest.php'));

        $result = $graph->result('main', 'Foo::test_it_works');
        self::assertNotNull($result);
        self::assertSame(0, $result['status']);
        self::assertArrayHasKey('key', $result);
        self::assertNotNull($result['key']);

        self::assertSame(['tests/FooTest.php'], $summary['touched']);
        self::assertSame(1, $summary['results']);
        self::assertSame(1, $summary['edges']);
    }

    public function test_results_only_apply_on_unknown_test_file_is_ignored(): void
    {
        $this->write('tests/FooTest.php');

        $graph = new Graph($this->root);
        $partial = new RunPartial(
            edges: [],
            results: ['Foo::test_it_works' => $this->makeResult(file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
        );

        $summary = $this->updater($graph)->apply($partial, 'main', recordsEdges: false, complete: false);

        self::assertNull($graph->result('main', 'Foo::test_it_works'));
        self::assertSame([], $summary['touched']);
        self::assertSame(0, $summary['results']);
    }

    public function test_results_only_apply_on_known_file_updates_result_and_key(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('src/Foo.php');

        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/FooTest.php' => ['src/Foo.php']]);
        $graph->markKnownTestFiles(['tests/FooTest.php']);
        $graph->setResult('main', 'Foo::test_it_works', $this->makeResult(status: 0, file: 'tests/FooTest.php'));

        $partial = new RunPartial(
            edges: [],
            results: ['Foo::test_it_works' => $this->makeResult(status: 7, file: 'tests/FooTest.php', message: 'boom')],
            tables: [],
            meta: [],
        );

        $this->updater($graph)->apply($partial, 'main', recordsEdges: false, complete: false);

        $result = $graph->result('main', 'Foo::test_it_works');
        self::assertNotNull($result);
        self::assertSame(7, $result['status']);
        self::assertSame('boom', $result['message']);
        self::assertArrayHasKey('key', $result);
        self::assertNotNull($result['key']);

        // Results-only never touches edges.
        self::assertSame(['src/Foo.php'], $graph->dependenciesOf('tests/FooTest.php'));
    }

    public function test_complete_apply_prunes_a_stale_test_id_of_a_touched_file(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('src/Foo.php');

        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/FooTest.php' => ['src/Foo.php']]);
        $graph->markKnownTestFiles(['tests/FooTest.php']);
        $graph->setResult('main', 'Foo::test_old', $this->makeResult(file: 'tests/FooTest.php'));

        $partial = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_new' => $this->makeResult(file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
        );

        $this->updater($graph)->apply($partial, 'main', recordsEdges: true, complete: true);

        self::assertNull($graph->result('main', 'Foo::test_old'));
        self::assertNotNull($graph->result('main', 'Foo::test_new'));
    }

    public function test_finalize_baseline_sets_sha_and_complete_and_prunes_other_branches_but_not_default_or_current(): void
    {
        $graph = new Graph($this->root);
        $graph->setDefaultBranch('main');
        $graph->setRecordedSha('main', 'main-sha');
        $graph->setRecordedSha('feature', 'old-feature-sha');
        $graph->setRecordedSha('stale', 'stale-sha');

        // Deliberately omit both 'feature' (the branch being finalised) and 'main'
        // (the default branch) from $keepBranches: both must survive regardless.
        $this->updater($graph)->finalizeBaseline('feature', 'new-feature-sha', []);

        self::assertSame('new-feature-sha', $graph->recordedSha('feature'));
        self::assertTrue($graph->isBaselineComplete('feature'));
        self::assertEqualsCanonicalizing(['main', 'feature'], $graph->branches());
    }

    // -- hermeticity: flip detection (SPEC.md §8.3) -------------------------

    public function test_apply_records_a_flip_when_the_content_key_is_unchanged_but_the_status_class_changes(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('src/Foo.php');

        $graph = new Graph($this->root);
        $quarantine = new Quarantine();

        $first = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 0, file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
        );

        $this->updater($graph, $quarantine)->apply($first, 'main', recordsEdges: true, complete: false);

        self::assertFalse($quarantine->isQuarantined('Foo::test_it'));

        // Same file contents (so the recomputed content key is identical), but the
        // result flips from pass (class "pass") to failure (class "fail").
        $second = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 7, file: 'tests/FooTest.php', message: 'boom')],
            tables: [],
            meta: [],
        );

        $this->updater($graph, $quarantine)->apply($second, 'main', recordsEdges: true, complete: false);

        self::assertTrue($quarantine->isQuarantined('Foo::test_it'));
        $entry = $quarantine->all()['Foo::test_it'];
        self::assertSame(1, $entry['flips']);
        self::assertSame(0, $entry['stable']);
        self::assertSame('flip', $entry['reason']);
    }

    public function test_apply_records_a_stable_pass_when_the_status_class_is_unchanged(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('src/Foo.php');

        $graph = new Graph($this->root);
        $quarantine = new Quarantine();

        $passing = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 0, file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
        );
        $this->updater($graph, $quarantine)->apply($passing, 'main', recordsEdges: true, complete: false);

        $failing = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 7, file: 'tests/FooTest.php', message: 'boom')],
            tables: [],
            meta: [],
        );
        $this->updater($graph, $quarantine)->apply($failing, 'main', recordsEdges: true, complete: false);

        self::assertSame(1, $quarantine->all()['Foo::test_it']['flips']);

        // Recovering from a cached failure/error is excluded from flip/stable tracking
        // entirely (SPEC §15 scenario 5: a cached "fail" class always reruns
        // unconditionally, so this transition is expected, not a signal either way).
        $recovering = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 0, file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
        );
        $this->updater($graph, $quarantine)->apply($recovering, 'main', recordsEdges: true, complete: false);

        $entry = $quarantine->all()['Foo::test_it'];
        self::assertSame(1, $entry['flips'], 'recovering from a cached failure is not itself a flip');
        self::assertSame(0, $entry['stable']);

        // Now the cached status is "pass" again: the same content key producing the
        // same class ("pass") once more is a genuine stable pass.
        $stillPassing = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 0, file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
        );
        $this->updater($graph, $quarantine)->apply($stillPassing, 'main', recordsEdges: true, complete: false);

        $entry = $quarantine->all()['Foo::test_it'];
        self::assertSame(1, $entry['flips']);
        self::assertSame(1, $entry['stable']);
    }

    public function test_apply_does_not_detect_a_flip_when_recovering_from_a_cached_failure(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('src/Foo.php');

        $graph = new Graph($this->root);
        $quarantine = new Quarantine();

        // Recorded failing from the start (as in SPEC §15 scenario 5): there is no
        // "old" result yet, so nothing is tracked on this first pass.
        $failing = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 7, file: 'tests/FooTest.php', message: 'boom')],
            tables: [],
            meta: [],
        );
        $this->updater($graph, $quarantine)->apply($failing, 'main', recordsEdges: true, complete: false);

        self::assertSame([], $quarantine->all());

        // Same content key, now passing: recovering from a cached "fail" class must
        // never quarantine the test (it would otherwise force it to run forever).
        $recovered = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 0, file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
        );
        $this->updater($graph, $quarantine)->apply($recovered, 'main', recordsEdges: true, complete: false);

        self::assertFalse($quarantine->isQuarantined('Foo::test_it'));
        self::assertSame([], $quarantine->all());
    }

    public function test_apply_does_not_detect_a_flip_when_the_content_key_changed_too(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('src/Foo.php', "<?php\n\$x = 1;\n");

        $graph = new Graph($this->root);
        $quarantine = new Quarantine();

        $first = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 0, file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
        );
        $this->updater($graph, $quarantine)->apply($first, 'main', recordsEdges: true, complete: false);

        // The dependency's *code* changes (not just a comment), so the recomputed
        // content key differs too: this is an ordinary "the test now covers different
        // code" case, not a flip.
        $this->write('src/Foo.php', "<?php\n\$x = 2;\n");

        $second = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 7, file: 'tests/FooTest.php', message: 'boom')],
            tables: [],
            meta: [],
        );
        $this->updater($graph, $quarantine)->apply($second, 'main', recordsEdges: true, complete: false);

        self::assertSame([], $quarantine->all());
    }

    public function test_apply_without_a_quarantine_never_detects_flips(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('src/Foo.php');

        $graph = new Graph($this->root);

        $first = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 0, file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
        );
        $this->updater($graph)->apply($first, 'main', recordsEdges: true, complete: false);

        $second = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(status: 7, file: 'tests/FooTest.php', message: 'boom')],
            tables: [],
            meta: [],
        );

        // No quarantine passed to updater(): must not throw, and there is nothing else to assert on.
        $this->updater($graph)->apply($second, 'main', recordsEdges: true, complete: false);

        $result = $graph->result('main', 'Foo::test_it');
        self::assertNotNull($result);
        self::assertSame(7, $result['status']);
    }

    // -- hermeticity: not_cacheable merge (SPEC.md §8) -----------------------

    public function test_apply_keeps_a_not_cacheable_entry_for_a_file_the_run_did_not_touch(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('tests/BarTest.php');
        $this->write('src/Foo.php');

        $graph = new Graph($this->root);
        $graph->setNotCacheable(['tests/FooTest.php', 'tests/BarTest.php']);

        $partial = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
            notCacheable: ['tests/FooTest.php'],
        );

        $this->updater($graph)->apply($partial, 'main', recordsEdges: true, complete: false);

        self::assertTrue($graph->isNotCacheable('tests/FooTest.php'));
        self::assertTrue($graph->isNotCacheable('tests/BarTest.php'));
    }

    public function test_apply_drops_a_not_cacheable_entry_for_an_executed_file_the_partial_no_longer_declares(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('src/Foo.php');

        $graph = new Graph($this->root);
        $graph->setNotCacheable(['tests/FooTest.php']);

        $partial = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
            notCacheable: [],
        );

        $this->updater($graph)->apply($partial, 'main', recordsEdges: true, complete: false);

        self::assertFalse($graph->isNotCacheable('tests/FooTest.php'));
    }

    public function test_apply_adds_a_new_not_cacheable_id_declared_by_the_partial(): void
    {
        $this->write('tests/FooTest.php');
        $this->write('src/Foo.php');

        $graph = new Graph($this->root);

        $partial = new RunPartial(
            edges: ['tests/FooTest.php' => ['src/Foo.php']],
            results: ['Foo::test_it' => $this->makeResult(file: 'tests/FooTest.php')],
            tables: [],
            meta: [],
            notCacheable: ['Foo::test_it'],
        );

        $this->updater($graph)->apply($partial, 'main', recordsEdges: true, complete: false);

        self::assertTrue($graph->isNotCacheable('Foo::test_it'));
    }

    // -- monotonic edges: a re-record must union, never replace (fix/monotonic-edges) ---
    //
    // PHP executes a file's top level exactly once per process, so a coverage driver
    // credits its declaration footprint (class/enum/const, or any top-level statement —
    // Record\Recorder's own docblock) to whichever test happened to load it first. A
    // *partial* re-record whose worker/order attribution differs from a previous,
    // complete one must not let that incidental difference silently drop an edge a test
    // genuinely still has.

    public function test_a_partial_re_record_unions_edges_instead_of_dropping_ones_a_previous_pass_proved(): void
    {
        $this->write('tests/ATest.php');
        $this->write('tests/BTest.php');
        $this->write('src/Shared.php');

        $graph = new Graph($this->root);

        // Pass 1 (e.g. a full record): in this process/order, A happens to load
        // Shared.php first, so its one-time declaration footprint is attributed to A.
        $pass1 = new RunPartial(
            edges: [
                'tests/ATest.php' => ['src/Shared.php'],
                'tests/BTest.php' => [],
            ],
            results: [
                'A::test_it' => $this->makeResult(file: 'tests/ATest.php'),
                'B::test_it' => $this->makeResult(file: 'tests/BTest.php'),
            ],
            tables: [],
            meta: [],
        );
        $this->updater($graph)->apply($pass1, 'main', recordsEdges: true, complete: true);

        self::assertContains('src/Shared.php', $graph->dependenciesOf('tests/ATest.php'));

        // Pass 2: a re-record of the SAME two tests (a different paratest worker
        // distribution, or simply a different run order) — this time B loads Shared.php
        // first, so THIS run's own partial credits B instead and says nothing about A
        // depending on it at all. A's own source code never changed.
        $pass2 = new RunPartial(
            edges: [
                'tests/ATest.php' => [],
                'tests/BTest.php' => ['src/Shared.php'],
            ],
            results: [
                'A::test_it' => $this->makeResult(file: 'tests/ATest.php'),
                'B::test_it' => $this->makeResult(file: 'tests/BTest.php'),
            ],
            tables: [],
            meta: [],
        );
        $this->updater($graph)->apply($pass2, 'main', recordsEdges: true, complete: true);

        self::assertContains(
            'src/Shared.php',
            $graph->dependenciesOf('tests/ATest.php'),
            'a re-record must union edges, not replace them, or a real dependency can silently disappear',
        );
        self::assertContains('src/Shared.php', $graph->dependenciesOf('tests/BTest.php'));
    }

    public function test_without_the_union_a_dropped_edge_would_hide_a_real_change_from_the_content_key(): void
    {
        $this->write('tests/ATest.php');
        $this->write('tests/BTest.php');
        $this->write('src/Shared.php');

        $graph = new Graph($this->root);
        $contentKey = new ContentKey($this->root);

        $pass1 = new RunPartial(
            edges: ['tests/ATest.php' => ['src/Shared.php'], 'tests/BTest.php' => []],
            results: [
                'A::test_it' => $this->makeResult(file: 'tests/ATest.php'),
                'B::test_it' => $this->makeResult(file: 'tests/BTest.php'),
            ],
            tables: [],
            meta: [],
        );
        $this->updater($graph)->apply($pass1, 'main', recordsEdges: true, complete: true);

        // Pass 2 re-records both tests; this run's attribution credits B instead of A.
        $pass2 = new RunPartial(
            edges: ['tests/ATest.php' => [], 'tests/BTest.php' => ['src/Shared.php']],
            results: [
                'A::test_it' => $this->makeResult(file: 'tests/ATest.php'),
                'B::test_it' => $this->makeResult(file: 'tests/BTest.php'),
            ],
            tables: [],
            meta: [],
        );
        $this->updater($graph)->apply($pass2, 'main', recordsEdges: true, complete: true);

        $keyBefore = $contentKey->forTestFile($graph, 'tests/ATest.php');
        self::assertNotNull($keyBefore);

        // Step 3 of the false-green chain: src/Shared.php has a genuine (non-cosmetic)
        // content change — ContentHash deliberately ignores comment/whitespace-only
        // edits, so this must be a real token change, not just a trailing comment.
        $this->write('src/Shared.php', "<?php\n\$x = 1;\n");

        $keyAfter = $contentKey->forTestFile($graph, 'tests/ATest.php');

        self::assertNotSame(
            $keyBefore,
            $keyAfter,
            'A still depends on src/Shared.php: its content key must change when that file changes '
            . '(the false green this fix closes is exactly this assertion failing)',
        );
    }

    public function test_union_still_lets_a_deleted_dependency_change_the_content_key(): void
    {
        $this->write('tests/ATest.php');
        $this->write('src/Shared.php');

        $graph = new Graph($this->root);
        $contentKey = new ContentKey($this->root);

        $pass1 = new RunPartial(
            edges: ['tests/ATest.php' => ['src/Shared.php']],
            results: ['A::test_it' => $this->makeResult(file: 'tests/ATest.php')],
            tables: [],
            meta: [],
        );
        $this->updater($graph)->apply($pass1, 'main', recordsEdges: true, complete: true);

        $keyBefore = $contentKey->forTestFile($graph, 'tests/ATest.php');

        // Pass 2 re-records A without src/Shared.php in ITS OWN partial (as in the
        // false-green scenario above); union must still keep the edge...
        $pass2 = new RunPartial(
            edges: ['tests/ATest.php' => []],
            results: ['A::test_it' => $this->makeResult(file: 'tests/ATest.php')],
            tables: [],
            meta: [],
        );
        $this->updater($graph)->apply($pass2, 'main', recordsEdges: true, complete: true);

        self::assertContains('src/Shared.php', $graph->dependenciesOf('tests/ATest.php'));

        // ...and a REAL deletion of that dependency must still change the key: ContentKey
        // contributes "rel:" with an empty hash for a dependency missing on disk instead
        // of skipping it (src/Cache/ContentKey.php's own contract).
        unlink($this->root . '/src/Shared.php');

        $keyAfterDeletion = $contentKey->forTestFile($graph, 'tests/ATest.php');

        self::assertNotNull($keyBefore);
        self::assertNotNull($keyAfterDeletion);
        self::assertNotSame($keyBefore, $keyAfterDeletion);
    }

    public function test_a_fresh_graph_never_carries_edges_across_a_full_record(): void
    {
        $this->write('tests/ATest.php');
        $this->write('src/Old.php');
        $this->write('src/New.php');

        $old = new Graph($this->root);
        $this->updater($old)->apply(
            new RunPartial(
                edges: ['tests/ATest.php' => ['src/Old.php']],
                results: ['A::test_it' => $this->makeResult(file: 'tests/ATest.php')],
                tables: [],
                meta: [],
            ),
            'main',
            recordsEdges: true,
            complete: true,
        );
        self::assertContains('src/Old.php', $old->dependenciesOf('tests/ATest.php'));

        // `record` (Console\Runner\RunPipeline::runRecord()) always starts from a brand
        // new Graph regardless of what any previous graph held — union has nothing to
        // unify with, so nothing can leak across.
        $fresh = new Graph($this->root);
        $this->updater($fresh)->apply(
            new RunPartial(
                edges: ['tests/ATest.php' => ['src/New.php']],
                results: ['A::test_it' => $this->makeResult(file: 'tests/ATest.php')],
                tables: [],
                meta: [],
            ),
            'main',
            recordsEdges: true,
            complete: true,
        );

        self::assertSame(['src/New.php'], $fresh->dependenciesOf('tests/ATest.php'));
        self::assertNotContains('src/Old.php', $fresh->dependenciesOf('tests/ATest.php'));
    }
}
