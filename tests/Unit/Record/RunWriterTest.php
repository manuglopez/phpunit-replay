<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\ResultCollector;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Record\RunWriter;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunWriterTest extends TestCase
{
    private string $projectRoot;

    private string $runDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = TempDir::make('run-writer-root');
        $this->runDir = TempDir::make('run-writer-run');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->projectRoot);
        TempDir::remove($this->runDir);
        putenv('TEST_TOKEN');

        parent::tearDown();
    }

    #[Test]
    public function flush_writes_relativised_edges_results_tables_and_meta_and_round_trips_via_run_partial(): void
    {
        $driver = new FakeCoverageDriver([
            [$this->projectRoot . '/src/Used.php' => [1 => 1]],
        ]);
        $recorder = new Recorder($driver);

        $testFile = $this->projectRoot . '/tests/FooTest.php';
        $recorder->beginTest($testFile);
        $recorder->linkTable('orders');
        $recorder->endTest();

        $collector = new ResultCollector();
        $collector->testPrepared('Foo::bar', $testFile);
        $collector->testPassed();
        $collector->recordAssertions('Foo::bar', 2);
        $collector->finishTest();

        $writer = new RunWriter($this->runDir, $this->projectRoot);

        $ok = $writer->flush($recorder, $collector, [
            'driver' => 'fake',
            'php' => '8.4',
            'os' => 'Linux',
            'mode' => 'record',
            'startedAt' => 1000,
            'finishedAt' => 1001,
        ]);

        self::assertTrue($ok);
        self::assertFileExists($this->runDir . '/edges.json');
        self::assertFileExists($this->runDir . '/results.json');
        self::assertFileExists($this->runDir . '/tables.json');
        self::assertFileExists($this->runDir . '/meta.json');

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);

        self::assertSame(
            ['tests/FooTest.php' => ['src/Used.php']],
            $partial->edges,
        );
        self::assertSame(
            ['tests/FooTest.php' => ['orders']],
            $partial->tables,
        );

        self::assertArrayHasKey('Foo::bar', $partial->results);
        self::assertSame('tests/FooTest.php', $partial->results['Foo::bar']['file']);
        self::assertSame(2, $partial->results['Foo::bar']['assertions']);

        self::assertSame('fake', $partial->meta['driver']);
        self::assertFalse($partial->meta['truncated']);
    }

    #[Test]
    public function mark_truncated_is_reflected_in_meta(): void
    {
        $recorder = new Recorder(new FakeCoverageDriver([]));
        $collector = new ResultCollector();

        $writer = new RunWriter($this->runDir, $this->projectRoot);
        $writer->markTruncated();
        $writer->flush($recorder, $collector, ['driver' => 'fake']);

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);
        self::assertTrue($partial->meta['truncated']);
    }

    #[Test]
    public function edges_and_results_outside_the_project_root_are_dropped(): void
    {
        $driver = new FakeCoverageDriver([
            [
                $this->projectRoot . '/src/Used.php' => [1 => 1],
                '/outside/root/Other.php' => [1 => 1],
            ],
        ]);
        $recorder = new Recorder($driver);

        // A test file entirely outside the project root: the whole edge entry is dropped.
        $recorder->beginTest('/outside/root/tests/OutsideTest.php');
        $recorder->endTest();

        // A test file inside the root, linked to one in-root and one out-of-root source:
        // only the out-of-root source is dropped from its edge list.
        $insideTestFile = $this->projectRoot . '/tests/FooTest.php';
        $recorder->beginTest($insideTestFile);
        $recorder->linkSource($this->projectRoot . '/src/Linked.php');
        $recorder->linkSource('/outside/root/src/OutOfScope.php');
        $recorder->endTest();

        $collector = new ResultCollector();
        $collector->testPrepared('Foo::outside', '/outside/root/tests/OutsideTest.php');
        $collector->testPassed();
        $collector->finishTest();

        $writer = new RunWriter($this->runDir, $this->projectRoot);
        $writer->flush($recorder, $collector, ['driver' => 'fake']);

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);

        self::assertArrayNotHasKey('tests/OutsideTest.php', $partial->edges);
        self::assertArrayHasKey('tests/FooTest.php', $partial->edges);
        self::assertSame(['src/Linked.php'], $partial->edges['tests/FooTest.php']);

        // The result for a test file outside the root keeps status/message/etc but drops 'file'.
        self::assertArrayHasKey('Foo::outside', $partial->results);
        self::assertArrayNotHasKey('file', $partial->results['Foo::outside']);
    }

    #[Test]
    public function load_returns_null_when_results_or_meta_are_missing(): void
    {
        self::assertNull(RunPartial::load($this->runDir));

        $recorder = new Recorder(new FakeCoverageDriver([]));
        $collector = new ResultCollector();
        (new RunWriter($this->runDir, $this->projectRoot))->flush($recorder, $collector, []);

        unlink($this->runDir . '/meta.json');

        self::assertNull(RunPartial::load($this->runDir));
    }

    #[Test]
    public function load_defaults_edges_and_tables_to_empty_when_their_files_are_missing(): void
    {
        $recorder = new Recorder(new FakeCoverageDriver([]));
        $collector = new ResultCollector();
        (new RunWriter($this->runDir, $this->projectRoot))->flush($recorder, $collector, ['driver' => 'fake']);

        unlink($this->runDir . '/edges.json');
        unlink($this->runDir . '/tables.json');

        $partial = RunPartial::load($this->runDir);

        self::assertNotNull($partial);
        self::assertSame([], $partial->edges);
        self::assertSame([], $partial->tables);
    }

    #[Test]
    public function load_defaults_uses_database_to_empty_when_its_file_is_missing(): void
    {
        $recorder = new Recorder(new FakeCoverageDriver([]));
        $collector = new ResultCollector();
        (new RunWriter($this->runDir, $this->projectRoot))->flush($recorder, $collector, ['driver' => 'fake']);

        $partial = RunPartial::load($this->runDir);

        self::assertNotNull($partial);
        self::assertSame([], $partial->usesDatabase);
    }

    #[Test]
    public function write_uses_database_relativises_sorts_and_dedupes_and_round_trips_via_run_partial(): void
    {
        $recorder = new Recorder(new FakeCoverageDriver([]));
        $collector = new ResultCollector();
        $writer = new RunWriter($this->runDir, $this->projectRoot);
        $writer->flush($recorder, $collector, ['driver' => 'fake']);

        $ok = $writer->writeUsesDatabase([
            $this->projectRoot . '/tests/UsersTest.php',
            $this->projectRoot . '/tests/PostsTest.php',
            $this->projectRoot . '/tests/PostsTest.php',
            '/outside/root/tests/OutsideTest.php',
        ]);

        self::assertTrue($ok);
        self::assertFileExists($this->runDir . '/uses_database.json');

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);
        self::assertSame(['tests/PostsTest.php', 'tests/UsersTest.php'], $partial->usesDatabase);
    }

    #[Test]
    public function flush_writes_coverage_snapshots_map_and_round_trips_via_run_partial(): void
    {
        $recorder = new Recorder(new FakeCoverageDriver([]));
        $collector = new ResultCollector();
        $writer = new RunWriter($this->runDir, $this->projectRoot);

        $ok = $writer->flush($recorder, $collector, ['driver' => 'piggyback'], null, [
            'tests/FooTest.php' => 'abc123',
        ]);

        self::assertTrue($ok);
        self::assertFileExists($this->runDir . '/coverage.json');

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);
        self::assertSame(['tests/FooTest.php' => 'abc123'], $partial->coverage);
    }

    #[Test]
    public function flush_defaults_coverage_to_an_empty_map_when_not_given(): void
    {
        $recorder = new Recorder(new FakeCoverageDriver([]));
        $collector = new ResultCollector();
        (new RunWriter($this->runDir, $this->projectRoot))->flush($recorder, $collector, ['driver' => 'fake']);

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);
        self::assertSame([], $partial->coverage);
    }

    #[Test]
    public function load_defaults_coverage_to_empty_when_its_file_is_missing(): void
    {
        $recorder = new Recorder(new FakeCoverageDriver([]));
        $collector = new ResultCollector();
        (new RunWriter($this->runDir, $this->projectRoot))->flush($recorder, $collector, ['driver' => 'fake']);

        unlink($this->runDir . '/coverage.json');

        $partial = RunPartial::load($this->runDir);
        self::assertNotNull($partial);
        self::assertSame([], $partial->coverage);
    }

    #[Test]
    public function flush_prefixes_every_file_with_worker_test_token_when_the_env_var_is_set(): void
    {
        putenv('TEST_TOKEN=3');

        $recorder = new Recorder(new FakeCoverageDriver([]));
        $collector = new ResultCollector();
        $writer = new RunWriter($this->runDir, $this->projectRoot);
        $writer->flush($recorder, $collector, ['driver' => 'fake']);
        $writer->writeUsesDatabase([]);

        self::assertFileDoesNotExist($this->runDir . '/edges.json');
        self::assertFileDoesNotExist($this->runDir . '/results.json');
        self::assertFileDoesNotExist($this->runDir . '/tables.json');
        self::assertFileDoesNotExist($this->runDir . '/meta.json');
        self::assertFileDoesNotExist($this->runDir . '/uses_database.json');

        self::assertFileExists($this->runDir . '/worker-3-edges.json');
        self::assertFileExists($this->runDir . '/worker-3-results.json');
        self::assertFileExists($this->runDir . '/worker-3-tables.json');
        self::assertFileExists($this->runDir . '/worker-3-meta.json');
        self::assertFileExists($this->runDir . '/worker-3-uses_database.json');
    }

    #[Test]
    public function flush_does_not_prefix_files_when_test_token_is_not_set(): void
    {
        putenv('TEST_TOKEN');

        $recorder = new Recorder(new FakeCoverageDriver([]));
        $collector = new ResultCollector();
        (new RunWriter($this->runDir, $this->projectRoot))->flush($recorder, $collector, ['driver' => 'fake']);

        self::assertFileExists($this->runDir . '/results.json');
        self::assertFileDoesNotExist($this->runDir . '/worker--results.json');
    }
}
