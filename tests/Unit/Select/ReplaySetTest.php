<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Select\ReplaySet;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * `Select\ReplaySet` is the single answer to "would the fast lane have served this test
 * from cache?", shared by `run`'s replayed counter and `verify`'s `would replay` figure
 * (SPEC.md §12.2). These pin the contract those two now share.
 *
 * Every case runs against a real temporary project root, because the class checks that a
 * test file still exists on disk — the one reason a file can be missing from the run list
 * without that meaning it is replayable.
 */
final class ReplaySetTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('replay-set');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_a_test_whose_file_is_not_in_the_run_list_would_be_replayed(): void
    {
        $graph = $this->graphWith([
            'App\Tests\AlphaTest::testOne' => ['tests/AlphaTest.php', 0.5],
            'App\Tests\BetaTest::testOne' => ['tests/BetaTest.php', 0.25],
        ]);

        $set = ReplaySet::against($graph, 'main', ['tests/BetaTest.php']);

        self::assertTrue($set->has('App\Tests\AlphaTest::testOne'));
        self::assertFalse($set->has('App\Tests\BetaTest::testOne'));
        self::assertSame(1, $set->count());
        self::assertSame(['App\Tests\AlphaTest::testOne'], $set->testIds());
        self::assertSame(0.5, $set->savedSeconds());
    }

    /**
     * The whole point of deciding off the run list rather than off a per-test rule of its
     * own: a file lands in the run list for ANY of its tests (a rerun status, a quarantined
     * or `#[NotCacheable]` sibling), and `run` then re-executes every test in it — so no
     * test in that file may be counted as replayed. `RunListBuilder` produces the buckets;
     * this class only has to respect them.
     */
    public function test_no_test_in_a_run_list_file_is_counted_even_when_its_sibling_is_the_reason(): void
    {
        $graph = $this->graphWith([
            'App\Tests\MixedTest::testClean' => ['tests/MixedTest.php', 1.0],
            'App\Tests\MixedTest::testNotCacheable' => ['tests/MixedTest.php', 2.0],
        ]);

        $set = ReplaySet::against($graph, 'main', ['tests/MixedTest.php']);

        self::assertFalse($set->has('App\Tests\MixedTest::testClean'));
        self::assertFalse($set->has('App\Tests\MixedTest::testNotCacheable'));
        self::assertSame(0, $set->count());
        self::assertSame(0.0, $set->savedSeconds());
    }

    /**
     * The figure is decided by run-list membership alone and never by a content key —
     * exactly like `PHPUnit\ReplayState::decideFresh()`, which reads the cached result
     * straight out of the graph. Two results in the same file with different stored keys
     * are both replayed or neither is.
     */
    public function test_the_stored_content_key_does_not_affect_the_decision(): void
    {
        $graph = $this->graphWith([
            'App\Tests\KeyedTest::testStale' => ['tests/KeyedTest.php', 0.1],
            'App\Tests\KeyedTest::testFresh' => ['tests/KeyedTest.php', 0.1],
        ]);
        $graph->setResult('main', 'App\Tests\KeyedTest::testStale', [
            'status' => 0, 'message' => '', 'time' => 0.1, 'assertions' => 1,
            'file' => 'tests/KeyedTest.php', 'key' => 'aaaaaaaaaaaaaaaa',
        ]);
        $graph->setResult('main', 'App\Tests\KeyedTest::testFresh', [
            'status' => 0, 'message' => '', 'time' => 0.1, 'assertions' => 1,
            'file' => 'tests/KeyedTest.php', 'key' => 'bbbbbbbbbbbbbbbb',
        ]);
        $graph->setResult('main', 'App\Tests\KeyedTest::testKeyless', [
            'status' => 0, 'message' => '', 'time' => 0.1, 'assertions' => 1,
            'file' => 'tests/KeyedTest.php',
        ]);

        $set = ReplaySet::against($graph, 'main', []);

        self::assertSame(3, $set->count());
    }

    /**
     * The one way a file can be absent from the run list without that meaning "replayable":
     * it is gone from disk, so no `RunListBuilder` bucket could hold it (`unknown` and
     * `notCacheable` come from a directory walk, `rerun` skips a missing file, and
     * `Selector::dropMissingTestFiles()` drops it from the selection). `run` usually hides
     * this by pruning first — deleting a test file is itself a change — but `verify`
     * deliberately does not prune, so the check has to live here.
     */
    public function test_a_test_file_gone_from_disk_is_never_replayed(): void
    {
        $graph = $this->graphWith(['App\Tests\HereTest::testOne' => ['tests/HereTest.php', 1.0]]);
        $graph->setResult('main', 'App\Tests\GoneTest::testOne', [
            'status' => 0, 'message' => '', 'time' => 3.0, 'assertions' => 1,
            'file' => 'tests/GoneTest.php', 'key' => 'kkkkkkkkkkkkkkkk',
        ]);

        $set = ReplaySet::against($graph, 'main', []);

        self::assertFalse($set->has('App\Tests\GoneTest::testOne'));
        self::assertTrue($set->has('App\Tests\HereTest::testOne'));
        self::assertSame(1, $set->count());
        self::assertSame(1.0, $set->savedSeconds());
    }

    public function test_a_result_with_no_test_file_is_never_replayed(): void
    {
        $graph = new Graph($this->root);
        $graph->setResult('main', 'App\Tests\OrphanTest::testOne', [
            'status' => 0, 'message' => '', 'time' => 0.75, 'assertions' => 1,
        ]);

        $set = ReplaySet::against($graph, 'main', []);

        self::assertFalse($set->has('App\Tests\OrphanTest::testOne'));
        self::assertSame(0, $set->count());
    }

    /**
     * The branch's results *as `Graph::results()` reports them* — its own layered over the
     * nearest baseline and the default branch. That is the same layered view
     * `PHPUnit\ReplayState::decideFresh()` reads (`Graph::result($branch, $testId)`), and it
     * is what lets a feature branch's fast lane replay off `main`'s baseline having recorded
     * nothing itself.
     */
    public function test_a_branch_replays_the_results_it_inherits_from_the_default_branch(): void
    {
        $graph = $this->graphWith(['App\Tests\AlphaTest::testOne' => ['tests/AlphaTest.php', 0.5]]);
        $graph->setDefaultBranch('main');

        self::assertSame(1, ReplaySet::against($graph, 'main', [])->count());
        self::assertSame(1, ReplaySet::against($graph, 'feature/x', [])->count());
        self::assertTrue(ReplaySet::against($graph, 'feature/x', [])->has('App\Tests\AlphaTest::testOne'));
        self::assertSame(0, ReplaySet::against($graph, 'feature/x', ['tests/AlphaTest.php'])->count());
    }

    public function test_none_replays_nothing(): void
    {
        $set = ReplaySet::none();

        self::assertSame(0, $set->count());
        self::assertSame([], $set->testIds());
        self::assertSame(0.0, $set->savedSeconds());
        self::assertFalse($set->has('App\Tests\AnyTest::testOne'));
    }

    /**
     * Each named test file is created on disk, since the class checks for it.
     *
     * @param array<string, array{0: string, 1: float}> $results test id => [test file, time]
     */
    private function graphWith(array $results): Graph
    {
        $graph = new Graph($this->root);

        foreach ($results as $testId => [$file, $time]) {
            TempDir::write($this->root . '/' . $file, "<?php\n");

            $graph->setResult('main', $testId, [
                'status' => 0,
                'message' => '',
                'time' => $time,
                'assertions' => 1,
                'file' => $file,
                'key' => 'k' . strlen($testId),
            ]);
        }

        return $graph;
    }
}
