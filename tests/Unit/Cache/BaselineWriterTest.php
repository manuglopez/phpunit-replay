<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\BaselineWriter;
use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\GraphUpdater;
use Manuglopez\Replay\Cache\RunContext;
use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Change\LastRunTree;
use Manuglopez\Replay\Tests\Support\GitRepo;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class BaselineWriterTest extends TestCase
{
    private GitRepo $repo;

    private string $stateDir;

    private string $head;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = GitRepo::init();
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");
        $this->head = $this->repo->commitAll('initial');

        $this->stateDir = TempDir::make('baseline-writer-state');
    }

    protected function tearDown(): void
    {
        $this->repo->destroy();
        TempDir::remove($this->stateDir);

        parent::tearDown();
    }

    private function context(bool $persist = true, bool $ciMode = false, bool $allowCiBaseline = false): RunContext
    {
        return new RunContext(
            $this->repo->root,
            $this->stateDir,
            'main',
            $this->head,
            'main',
            $persist,
            $ciMode,
            $allowCiBaseline,
        );
    }

    /** @return array{0: BaselineWriter, 1: Graph, 2: GraphUpdater, 3: GraphStore} */
    private function make(): array
    {
        $root = $this->repo->root;
        $git = new Git($root);
        $store = new GraphStore($this->stateDir, $root);
        $graph = new Graph($root);
        $graph->link($root . '/tests/FooTest.php', $root . '/src/Foo.php');
        $updater = new GraphUpdater($graph, $root, new ContentKey($root));

        return [new BaselineWriter($store, $git, new ChangedFiles($root, $git)), $graph, $updater, $store];
    }

    public function test_a_complete_pass_records_the_sha_marks_it_complete_and_snapshots_the_tree(): void
    {
        [$writer, $graph, $updater, $store] = $this->make();

        self::assertTrue($writer->commit($graph, $updater, $this->context(), true));

        self::assertSame($this->head, $graph->recordedSha('main'));
        self::assertTrue($graph->isBaselineComplete('main'));
        self::assertFileExists($store->path());

        $lastRun = LastRunTree::load($this->stateDir);
        self::assertNotNull($lastRun);
        self::assertSame('main', $lastRun->branch);
        self::assertSame($this->head, $lastRun->sha);
    }

    public function test_an_incomplete_pass_saves_the_graph_without_a_baseline(): void
    {
        [$writer, $graph, $updater, $store] = $this->make();

        self::assertTrue($writer->commit($graph, $updater, $this->context(), false));

        self::assertNull($graph->recordedSha('main'));
        self::assertFalse($graph->isBaselineComplete('main'));
        self::assertFileExists($store->path());
        self::assertNull(LastRunTree::load($this->stateDir));
    }

    public function test_a_detached_head_saves_the_graph_without_a_baseline(): void
    {
        [$writer, $graph, $updater, $store] = $this->make();

        self::assertTrue($writer->commit($graph, $updater, $this->context(persist: false), true));

        self::assertNull($graph->recordedSha('main'));
        self::assertFileExists($store->path());
    }

    public function test_ci_does_not_publish_the_baseline_unless_allowed(): void
    {
        [$writer, $graph, $updater] = $this->make();

        self::assertTrue($writer->commit($graph, $updater, $this->context(ciMode: true), true));

        self::assertNull($graph->recordedSha('main'));
        self::assertNull(LastRunTree::load($this->stateDir));
    }

    public function test_ci_publishes_the_baseline_when_explicitly_allowed(): void
    {
        [$writer, $graph, $updater] = $this->make();

        self::assertTrue($writer->commit($graph, $updater, $this->context(ciMode: true, allowCiBaseline: true), true));

        self::assertSame($this->head, $graph->recordedSha('main'));
        self::assertNotNull(LastRunTree::load($this->stateDir));
    }
}
