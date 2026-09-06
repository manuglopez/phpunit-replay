<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class GraphStoreTest extends TestCase
{
    private string $root;

    private string $stateDir;

    protected function setUp(): void
    {
        $this->root = TempDir::make('graphstore-root');
        $this->stateDir = TempDir::make('graphstore-state');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
        TempDir::remove($this->stateDir);
    }

    public function test_path_is_graph_json_under_the_state_dir(): void
    {
        $store = new GraphStore($this->stateDir, $this->root);

        self::assertSame($this->stateDir . '/graph.json', $store->path());
    }

    public function test_load_returns_null_when_file_is_missing(): void
    {
        $store = new GraphStore($this->stateDir, $this->root);

        self::assertNull($store->load());
        self::assertNull($store->loadRaw());
    }

    public function test_save_then_load_round_trips_a_graph(): void
    {
        TempDir::write($this->root . '/tests/Feature/FooTest.php', '<?php');

        $graph = new Graph($this->root);
        $graph->link('tests/Feature/FooTest.php', 'app/Foo.php');
        $graph->setResult('main', 'Tests\FooTest::it_works', [
            'status' => 0,
            'message' => '',
            'time' => 0.05,
            'assertions' => 2,
            'file' => 'tests/Feature/FooTest.php',
            'key' => 'abc123',
        ]);

        $store = new GraphStore($this->stateDir, $this->root);

        self::assertTrue($store->save($graph));
        self::assertFileExists($store->path());

        $loaded = $store->load();
        self::assertNotNull($loaded);
        self::assertSame(['app/Foo.php'], $loaded->dependenciesOf('tests/Feature/FooTest.php'));
        self::assertSame(
            $graph->result('main', 'Tests\FooTest::it_works'),
            $loaded->result('main', 'Tests\FooTest::it_works'),
        );
    }

    public function test_save_leaves_no_tmp_files_behind(): void
    {
        $graph = new Graph($this->root);
        $store = new GraphStore($this->stateDir, $this->root);

        self::assertTrue($store->save($graph));

        $leftovers = glob($this->stateDir . '/*.tmp');
        self::assertSame([], $leftovers);
    }

    public function test_load_returns_null_for_undecodable_content(): void
    {
        file_put_contents($this->stateDir . '/graph.json', '{not valid json');

        $store = new GraphStore($this->stateDir, $this->root);

        self::assertNull($store->load());
        self::assertSame('{not valid json', $store->loadRaw());
    }

    public function test_delete_removes_the_file_and_is_idempotent(): void
    {
        $graph = new Graph($this->root);
        $store = new GraphStore($this->stateDir, $this->root);
        $store->save($graph);

        self::assertTrue($store->delete());
        self::assertFileDoesNotExist($store->path());
        self::assertTrue($store->delete());
    }

    public function test_save_fails_when_the_state_dir_cannot_be_created(): void
    {
        $blocker = TempDir::make('graphstore-blocker');
        file_put_contents($blocker . '/blocked', 'not a directory');

        $graph = new Graph($this->root);
        $store = new GraphStore($blocker . '/blocked/sub', $this->root);

        self::assertFalse($store->save($graph));

        TempDir::remove($blocker);
    }
}
