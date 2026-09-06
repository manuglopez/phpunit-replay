<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphUpdater;
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

    private function updater(Graph $graph): GraphUpdater
    {
        return new GraphUpdater($graph, $this->root, new ContentKey($this->root));
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
}
