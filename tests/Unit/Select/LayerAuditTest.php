<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Change\LastRunTree;
use Manuglopez\Replay\Select\LayerAudit;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Tests\Support\GitRepo;
use PHPUnit\Framework\TestCase;

/**
 * The layer-validity rule (`Select\LayerAudit`) over a real repository, with a rule chain
 * reduced to a lookup table: `src/<X>.txt` selects `tests/<X>Test.php`.
 */
final class LayerAuditTest extends TestCase
{
    private GitRepo $repo;

    private string $first = '';

    private string $second = '';

    /** @var list<list<string>> every change set the rule chain was asked about */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->repo = GitRepo::init();
        $this->repo->write('src/A.txt', 'a1');
        $this->repo->write('src/B.txt', 'b1');
        $this->first = $this->repo->commitAll('first');
        $this->repo->write('src/A.txt', 'a2');
        $this->second = $this->repo->commitAll('second: A changes');
    }

    protected function tearDown(): void
    {
        $this->repo->destroy();
    }

    public function test_the_layer_the_pass_diffs_from_and_everything_below_it_are_not_audited(): void
    {
        $graph = $this->graph();
        $graph->setNearestBranch('develop');
        $graph->setRecordedSha('develop', $this->second);
        $graph->setRecordedSha('main', $this->first);
        $graph->setRecordedSha('feature', $this->second);

        // The own layer IS the diff base; develop and main sit below it and are sound by
        // Graph::fallbackChain()'s argument even though main's sha is a commit behind.
        self::assertSame([], $this->audit($graph)->apply('feature', $this->second));
        self::assertSame([], $this->asked, 'no layer needed a diff of its own');
        self::assertSame('feature', $graph->results('feature')['ATest::a']['message']);
    }

    public function test_an_own_layer_above_the_diff_base_forgets_what_its_own_diff_selects(): void
    {
        $graph = $this->graph();
        $graph->setNearestBranch('develop');
        $graph->setRecordedSha('develop', $this->second);
        $graph->setRecordedSha('main', $this->first);
        // The branch's own results were recorded at `first`; a nearer baseline won.
        $graph->setRecordedSha('feature', $this->first);

        $stale = $this->audit($graph)->apply('feature', $this->second);

        self::assertSame([['src/A.txt']], $this->asked);
        self::assertSame(['tests/ATest.php'], array_keys($stale));
        self::assertEquals(new Reason('StaleLayer', 'src/A.txt', 'feature@' . substr($this->first, 0, 7)), $stale['tests/ATest.php']['reason']);
        self::assertSame(['ATest::a'], $stale['tests/ATest.php']['ids']);

        // Forgotten from the own layer for good; B's own result is untouched...
        self::assertSame(['BTest::b'], array_keys($graph->ownResults('feature')));
        // ...and A is now served from develop, the layer the diff vouches for.
        self::assertSame('develop', $graph->results('feature')['ATest::a']['message']);
        self::assertSame('feature', $graph->results('feature')['BTest::b']['message']);
    }

    public function test_an_own_layer_with_no_sha_serves_nothing(): void
    {
        $graph = $this->graph();
        $graph->setRecordedSha('main', $this->second);

        $stale = $this->audit($graph)->apply('feature', $this->second);

        self::assertSame(['tests/ATest.php', 'tests/BTest.php'], array_keys($stale));
        self::assertSame('no recorded sha', $stale['tests/BTest.php']['reason']->detail);
        self::assertSame([], $graph->ownResults('feature'));
        self::assertSame([], $this->asked);
    }

    public function test_an_own_layer_whose_sha_git_does_not_have_serves_nothing(): void
    {
        $graph = $this->graph();
        $graph->setRecordedSha('main', $this->second);
        $graph->setRecordedSha('feature', str_repeat('ab', 20));

        $stale = $this->audit($graph)->apply('feature', $this->second);

        self::assertSame('sha not available', $stale['tests/ATest.php']['reason']->detail);
        self::assertSame([], $graph->ownResults('feature'), 'a miss, never a stale hit (a shallow clone)');
    }

    public function test_an_own_layer_whose_sha_is_not_an_ancestor_is_still_checked_by_content(): void
    {
        // A rebased branch: its own sha is off to the side, but git still has it, and what
        // its results are valid for is the content at that sha.
        $this->repo->checkout('side', create: true);
        $this->repo->write('src/B.txt', 'b-side');
        $side = $this->repo->commitAll('side');
        $this->repo->checkout('main');

        $graph = $this->graph();
        $graph->setRecordedSha('main', $this->second);
        $graph->setRecordedSha('feature', $side);

        $stale = $this->audit($graph)->apply('feature', $this->second);

        self::assertSame(['tests/BTest.php'], array_keys($stale));
        self::assertSame(['ATest::a'], array_keys($graph->ownResults('feature')));
    }

    public function test_layers_below_a_base_this_graph_holds_no_layer_for_are_withheld_not_edited(): void
    {
        $graph = $this->graph(withDevelop: false);
        // develop's sha came off the remote: no develop layer here at all.
        $graph->setNearestBranch('develop');
        $graph->setRecordedSha('main', $this->first);
        $graph->setRecordedSha('feature', $this->second);

        $stale = $this->audit($graph)->apply('feature', $this->second . 'remote');

        // feature's own sha is not the diff base either, and HEAD is its sha: nothing moved.
        // main's diff (first..HEAD) selects A: withheld for this pass, main's layer intact.
        self::assertSame(['tests/ATest.php'], array_keys($stale));
        self::assertSame('main@' . substr($this->first, 0, 7), $stale['tests/ATest.php']['reason']->detail);
        self::assertCount(2, $graph->ownResults('main'));

        $graph->clearResults('feature');
        self::assertArrayNotHasKey('ATest::a', $graph->results('feature'));
        self::assertArrayHasKey('BTest::b', $graph->results('feature'));

        // A mask lasts one pass: it is never encoded.
        $decoded = Graph::decode((string) $graph->encode(), $this->repo->root);
        self::assertNotNull($decoded);
        $decoded->setNearestBranch('develop');
        self::assertArrayHasKey('ATest::a', $decoded->results('feature'));
    }

    public function test_the_own_layers_diff_honours_the_last_run_snapshot(): void
    {
        $graph = $this->graph();
        $graph->setNearestBranch('develop');
        $graph->setRecordedSha('develop', $this->second);
        $graph->setRecordedSha('feature', $this->first);

        // B was dirty when the own layer was last recorded and still has that content: the
        // own layer's B results were recorded against it. A was committed since.
        $this->repo->write('src/B.txt', 'b-dirty');
        $snapshot = (new ChangedFiles($this->repo->root))->snapshotTree(['src/B.txt']);
        $lastRun = new LastRunTree('feature', $this->first, $snapshot, time());

        $stale = $this->audit($graph)->apply('feature', $this->second, $lastRun);

        self::assertSame([['src/A.txt']], $this->asked);
        self::assertSame(['tests/ATest.php'], array_keys($stale));

        // A snapshot of another branch says nothing about this layer.
        $graph = $this->graph();
        $graph->setNearestBranch('develop');
        $graph->setRecordedSha('develop', $this->second);
        $graph->setRecordedSha('feature', $this->first);
        $this->asked = [];

        $this->audit($graph)->apply('feature', $this->second, new LastRunTree('other', $this->first, $snapshot, time()));

        self::assertSame([['src/A.txt', 'src/B.txt']], $this->asked);

        // Nor does one of this branch taken at another head (a pass that saved a snapshot
        // without moving the layer's sha).
        $graph = $this->graph();
        $graph->setNearestBranch('develop');
        $graph->setRecordedSha('develop', $this->second);
        $graph->setRecordedSha('feature', $this->first);
        $this->asked = [];

        $this->audit($graph)->apply('feature', $this->second, new LastRunTree('feature', $this->second, $snapshot, time()));

        self::assertSame([['src/A.txt', 'src/B.txt']], $this->asked);
    }

    private function audit(Graph $graph): LayerAudit
    {
        return new LayerAudit($graph, new ChangedFiles($this->repo->root), function (array $changed): Selection {
            $this->asked[] = $changed;
            $selection = new Selection();

            foreach ($changed as $file) {
                if (preg_match('#^src/(\w+)\.txt$#', $file, $m) === 1) {
                    $selection->add('tests/' . $m[1] . 'Test.php', new Reason('PhpEdge', $file));
                }
            }

            return $selection;
        });
    }

    /** feature, develop and main each hold a result for ATest::a and BTest::b, tagged with the layer's name. */
    private function graph(bool $withDevelop = true): Graph
    {
        $graph = new Graph($this->repo->root);

        foreach (['feature', ...($withDevelop ? ['develop'] : []), 'main'] as $branch) {
            foreach (['A', 'B'] as $name) {
                $graph->setResult($branch, $name . 'Test::' . strtolower($name), [
                    'status' => 0,
                    'message' => $branch,
                    'time' => 0.1,
                    'assertions' => 1,
                    'file' => 'tests/' . $name . 'Test.php',
                ]);
            }
        }

        return $graph;
    }
}
