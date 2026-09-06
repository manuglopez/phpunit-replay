<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Paratest support (SPEC.md §13): `RunPartial::load()` transparently merges
 * `worker-<TEST_TOKEN>-<basename>.json` files, written by {@see \Manuglopez\Replay\Record\RunWriter}
 * once per worker, into a single logical partial.
 */
final class RunPartialTest extends TestCase
{
    private string $runDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runDir = TempDir::make('run-partial-run');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->runDir);

        parent::tearDown();
    }

    #[Test]
    public function load_merges_edges_by_union_per_test_file(): void
    {
        $this->writeWorker(1, [
            'edges' => ['tests/CartTest.php' => ['src/Cart.php', 'src/Money.php']],
            'results' => ['App\Tests\CartTest::testA' => self::resultFixture(0)],
            'tables' => [],
            'meta' => ['driver' => 'pcov', 'truncated' => false],
        ]);

        $this->writeWorker(2, [
            'edges' => ['tests/CartTest.php' => ['src/Money.php', 'src/TaxCalculator.php']],
            'results' => ['App\Tests\MoneyTest::testB' => self::resultFixture(0)],
            'tables' => [],
            'meta' => ['driver' => 'pcov', 'truncated' => false],
        ]);

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);

        $edges = $partial->edges['tests/CartTest.php'];
        sort($edges);
        self::assertSame(['src/Cart.php', 'src/Money.php', 'src/TaxCalculator.php'], $edges);
    }

    #[Test]
    public function load_merges_results_last_write_wins(): void
    {
        $this->writeWorker(1, [
            'edges' => [],
            'results' => [
                'App\Tests\CartTest::testA' => self::resultFixture(0),
                'App\Tests\FlakyTest::testFlips' => self::resultFixture(0),
            ],
            'tables' => [],
            'meta' => ['driver' => 'pcov', 'truncated' => false],
        ]);

        $this->writeWorker(2, [
            'edges' => [],
            'results' => [
                'App\Tests\MoneyTest::testB' => self::resultFixture(0),
                'App\Tests\FlakyTest::testFlips' => self::resultFixture(7),
            ],
            'tables' => [],
            'meta' => ['driver' => 'pcov', 'truncated' => false],
        ]);

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);

        self::assertCount(3, $partial->results);
        // worker-2 sorts after worker-1: its result for the shared testId wins.
        self::assertSame(7, $partial->results['App\Tests\FlakyTest::testFlips']['status']);
    }

    #[Test]
    public function load_unions_tables_and_uses_database_and_dedupes(): void
    {
        $this->writeWorker(1, [
            'edges' => [],
            'results' => ['App\Tests\A::testA' => self::resultFixture(0)],
            'tables' => ['tests/UsersTest.php' => ['users']],
            'meta' => ['driver' => 'pcov', 'truncated' => false],
            'usesDatabase' => ['tests/PostsTest.php'],
        ]);

        $this->writeWorker(2, [
            'edges' => [],
            'results' => ['App\Tests\B::testB' => self::resultFixture(0)],
            'tables' => ['tests/UsersTest.php' => ['users', 'accounts']],
            'meta' => ['driver' => 'pcov', 'truncated' => false],
            'usesDatabase' => ['tests/PostsTest.php', 'tests/UsersTest.php'],
        ]);

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);

        $tables = $partial->tables['tests/UsersTest.php'];
        sort($tables);
        self::assertSame(['accounts', 'users'], $tables);

        $usesDatabase = $partial->usesDatabase;
        sort($usesDatabase);
        self::assertSame(['tests/PostsTest.php', 'tests/UsersTest.php'], $usesDatabase);
    }

    #[Test]
    public function load_meta_comes_from_the_first_worker_but_truncated_is_true_when_any_worker_set_it(): void
    {
        $this->writeWorker(1, [
            'edges' => [],
            'results' => ['App\Tests\A::testA' => self::resultFixture(0)],
            'tables' => [],
            'meta' => ['driver' => 'pcov', 'php' => '8.4', 'truncated' => false],
        ]);

        $this->writeWorker(2, [
            'edges' => [],
            'results' => ['App\Tests\B::testB' => self::resultFixture(0)],
            'tables' => [],
            'meta' => ['driver' => 'pcov', 'php' => '8.4', 'truncated' => true],
        ]);

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);

        self::assertSame('pcov', $partial->meta['driver']);
        self::assertTrue($partial->meta['truncated']);
    }

    #[Test]
    public function load_falls_back_to_the_plain_files_when_no_worker_files_exist(): void
    {
        file_put_contents($this->runDir . '/results.json', (string) json_encode(['App\Tests\A::testA' => self::resultFixture(0)]));
        file_put_contents($this->runDir . '/meta.json', (string) json_encode(['driver' => 'pcov', 'truncated' => false]));

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);
        self::assertCount(1, $partial->results);
    }

    #[Test]
    public function load_returns_null_when_no_worker_or_plain_results_exist(): void
    {
        self::assertNull(RunPartial::load($this->runDir));
    }

    /**
     * @param array{
     *     edges: array<string, list<string>>,
     *     results: array<string, array<string, mixed>>,
     *     tables: array<string, list<string>>,
     *     meta: array<string, mixed>,
     *     usesDatabase?: list<string>,
     * } $data
     */
    private function writeWorker(int $token, array $data): void
    {
        file_put_contents($this->runDir . '/worker-' . $token . '-edges.json', (string) json_encode($data['edges']));
        file_put_contents($this->runDir . '/worker-' . $token . '-results.json', (string) json_encode($data['results']));
        file_put_contents($this->runDir . '/worker-' . $token . '-tables.json', (string) json_encode($data['tables']));
        file_put_contents($this->runDir . '/worker-' . $token . '-meta.json', (string) json_encode($data['meta']));
        file_put_contents($this->runDir . '/worker-' . $token . '-uses_database.json', (string) json_encode($data['usesDatabase'] ?? []));
    }

    /** @return array<string, mixed> */
    private static function resultFixture(int $status): array
    {
        return ['status' => $status, 'message' => '', 'time' => 0.01, 'assertions' => 1];
    }
}
