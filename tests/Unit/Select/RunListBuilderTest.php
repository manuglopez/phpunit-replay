<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Hermeticity\Policy;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\Select\RunList;
use Manuglopez\Replay\Select\RunListBuilder;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class RunListBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('run-list-builder');

        $fixture = dirname(__DIR__, 2) . '/Fixtures/Projects/plain/phpunit.xml';
        $xml = file_get_contents($fixture);
        self::assertIsString($xml);
        TempDir::write($this->root . '/phpunit.xml', $xml);

        $this->write('src/Foo.php');
        $this->write('tests/FooTest.php');
        $this->write('tests/BarTest.php');
        $this->write('tests/helpers.php');
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

    /** @param array<string, int> $statuses test id => status, all attributed to $file */
    private function graph(array $statuses = [], string $file = 'tests/FooTest.php'): Graph
    {
        $graph = new Graph($this->root);
        $graph->link($this->root . '/tests/FooTest.php', $this->root . '/src/Foo.php');
        $graph->markKnownTestFiles(['tests/BarTest.php']);

        foreach ($statuses as $testId => $status) {
            $graph->setResult('main', $testId, [
                'status' => $status,
                'message' => '',
                'time' => 0.5,
                'assertions' => 2,
                'file' => $file,
            ]);
        }

        return $graph;
    }

    private function builder(Graph $graph): RunListBuilder
    {
        return new RunListBuilder(
            $graph,
            new TestPaths(['tests'], [], ['Test.php']),
            new WatchPatterns(),
            ConfigurationReader::fromXmlFile($this->root . '/phpunit.xml'),
            new Policy($graph, Config::defaults(), Quarantine::load($this->root . '/state'), $this->root),
            $this->root,
        );
    }

    public function test_nothing_changed_and_everything_known_yields_an_empty_run_list(): void
    {
        $list = $this->builder($this->graph())->build([], 'main');

        self::assertSame([], $list->files());
        self::assertSame([], $list->unknown);
        self::assertSame([], $list->rerun);
        self::assertSame([], $list->quarantined);
    }

    public function test_a_changed_source_file_pulls_in_its_dependents(): void
    {
        $list = $this->builder($this->graph())->build(['src/Foo.php'], 'main');

        self::assertSame(['tests/FooTest.php'], $list->files());
        self::assertTrue($list->has('tests/FooTest.php'));
        self::assertFalse($list->has('tests/BarTest.php'));

        $reasons = $list->reasonsFor('tests/FooTest.php');
        self::assertNotSame([], $reasons);
        self::assertSame('PhpEdge', $reasons[0]->rule);
        self::assertSame('src/Foo.php', $reasons[0]->trigger);
    }

    public function test_a_test_file_the_graph_does_not_know_is_uncached(): void
    {
        $this->write('tests/NewTest.php');

        $list = $this->builder($this->graph())->build([], 'main');

        self::assertSame(['tests/NewTest.php'], $list->unknown);
        self::assertSame(['tests/NewTest.php'], $list->files());
        self::assertSame('Uncached', $list->reasonsFor('tests/NewTest.php')[0]->rule);
        self::assertSame('new test file', $list->reasonsFor('tests/NewTest.php')[0]->trigger);
    }

    public function test_a_non_test_file_under_the_test_directory_is_never_uncached(): void
    {
        $list = $this->builder($this->graph())->build([], 'main');

        self::assertNotContains('tests/helpers.php', $list->unknown);
    }

    public function test_a_failed_result_puts_its_file_in_the_rerun_bucket_with_the_status_name(): void
    {
        $graph = $this->graph(['App\Tests\FooTest::testOne' => 7]);

        $list = $this->builder($graph)->build([], 'main');

        self::assertSame(['tests/FooTest.php'], $list->rerun);
        self::assertSame(['tests/FooTest.php'], $list->files());
        self::assertSame('Rerun', $list->reasonsFor('tests/FooTest.php')[0]->rule);
        self::assertSame('failure', $list->reasonsFor('tests/FooTest.php')[0]->trigger);
    }

    public function test_a_passing_result_is_not_rerun(): void
    {
        $graph = $this->graph(['App\Tests\FooTest::testOne' => 0]);

        self::assertSame([], $this->builder($graph)->build([], 'main')->rerun);
    }

    public function test_a_risky_result_is_rerun_because_the_fixture_config_fails_on_risky(): void
    {
        $graph = $this->graph(['App\Tests\FooTest::testOne' => 5]);

        $list = $this->builder($graph)->build([], 'main');

        self::assertSame(['tests/FooTest.php'], $list->rerun);
        self::assertSame('risky', $list->reasonsFor('tests/FooTest.php')[0]->trigger);
    }

    public function test_a_result_whose_file_is_gone_is_not_rerun(): void
    {
        $graph = $this->graph(['App\Tests\GoneTest::testOne' => 7], 'tests/GoneTest.php');

        self::assertSame([], $this->builder($graph)->build([], 'main')->rerun);
    }

    public function test_a_file_marked_not_cacheable_lands_in_the_not_cacheable_bucket(): void
    {
        $graph = $this->graph(['App\Tests\FooTest::testOne' => 0]);
        $graph->setNotCacheable(['tests/FooTest.php']);

        $list = $this->builder($graph)->build([], 'main');

        // The `#[NotCacheable]` attribute and automatic quarantine are distinct run-list
        // buckets (SPEC.md §8, docs/INTERNALS.md "Hermeticity"): only the latter is
        // `$quarantined`.
        self::assertSame(['tests/FooTest.php'], $list->notCacheable);
        self::assertSame([], $list->quarantined);
        self::assertSame(['tests/FooTest.php'], $list->files());
        self::assertSame('NotCacheable', $list->reasonsFor('tests/FooTest.php')[0]->rule);
        self::assertSame('attribute', $list->reasonsFor('tests/FooTest.php')[0]->trigger);
    }

    public function test_a_flipped_test_lands_in_the_quarantined_bucket_not_not_cacheable(): void
    {
        $graph = $this->graph(['App\Tests\FooTest::testOne' => 0]);
        $quarantine = Quarantine::load($this->root . '/state');
        $quarantine->recordFlip('App\Tests\FooTest::testOne', 'k1');

        $list = (new RunListBuilder(
            $graph,
            new TestPaths(['tests'], [], ['Test.php']),
            new WatchPatterns(),
            ConfigurationReader::fromXmlFile($this->root . '/phpunit.xml'),
            new Policy($graph, Config::defaults(), $quarantine, $this->root),
            $this->root,
        ))->build([], 'main');

        self::assertSame(['tests/FooTest.php'], $list->quarantined);
        self::assertSame([], $list->notCacheable);
        self::assertSame(['tests/FooTest.php'], $list->files());
        self::assertSame('Quarantine', $list->reasonsFor('tests/FooTest.php')[0]->rule);
    }

    public function test_all_test_files_on_disk_lists_every_test_file_regardless_of_the_graph(): void
    {
        self::assertSame(
            ['tests/BarTest.php', 'tests/FooTest.php'],
            $this->builder($this->graph())->allTestFilesOnDisk(),
        );
    }

    public function test_status_names_cover_the_phpunit_status_ints(): void
    {
        self::assertSame('success', RunList::statusName(0));
        self::assertSame('error', RunList::statusName(8));
        self::assertSame('unknown', RunList::statusName(42));
    }
}
