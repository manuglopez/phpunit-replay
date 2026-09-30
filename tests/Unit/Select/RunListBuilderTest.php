<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Hermeticity\Policy;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\Select\Reason;
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

        foreach (['tests/FooTest.php', 'tests/BarTest.php'] as $known) {
            $graph->setResult('main', $known . '::testRecorded', [
                'status' => 0,
                'message' => '',
                'time' => 0.5,
                'assertions' => 1,
                'file' => $known,
            ]);
        }

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

    public function test_a_known_test_file_holding_no_result_is_uncached_even_when_nothing_changed(): void
    {
        $graph = new Graph($this->root);
        $graph->link($this->root . '/tests/FooTest.php', $this->root . '/src/Foo.php');
        $graph->markKnownTestFiles(['tests/BarTest.php']);
        $graph->setResult('main', 'tests/BarTest.php::testRecorded', [
            'status' => 0,
            'message' => '',
            'time' => 0.5,
            'assertions' => 1,
            'file' => 'tests/BarTest.php',
        ]);

        $list = $this->builder($graph)->build([], 'main');

        self::assertSame(['tests/FooTest.php'], $list->unknown);
        self::assertSame(['tests/FooTest.php'], $list->files());
        self::assertSame('Uncached', $list->reasonsFor('tests/FooTest.php')[0]->rule);
        self::assertSame('no cached result', $list->reasonsFor('tests/FooTest.php')[0]->trigger);
        self::assertSame('uncached', $list->primaryReasonFor('tests/FooTest.php'));
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

    public function test_a_stale_file_with_a_valid_result_left_for_every_id_is_served_not_run(): void
    {
        // Select\LayerAudit dropped the own layer's FooTest results; main still holds one
        // for each of them, so FooTest is served from main.
        $graph = $this->graph(['FooTest::a' => 0, 'FooTest::b' => 0]);
        $stale = ['tests/FooTest.php' => ['reason' => new Reason('StaleLayer', 'src/Foo.php', 'feature@abc1234'), 'ids' => ['FooTest::a', 'FooTest::b']]];

        $list = $this->builder($graph)->build([], 'main', $stale);

        self::assertSame([], $list->stale);
        self::assertSame([], $list->files());
    }

    public function test_a_stale_file_one_of_whose_ids_nobody_else_holds_executes_and_says_why(): void
    {
        $graph = $this->graph(['FooTest::a' => 0]);
        $stale = ['tests/FooTest.php' => ['reason' => new Reason('StaleLayer', 'src/Foo.php', 'feature@abc1234'), 'ids' => ['FooTest::a', 'FooTest::b']]];

        $list = $this->builder($graph)->build([], 'main', $stale);

        self::assertSame(['tests/FooTest.php'], $list->stale);
        self::assertSame(['tests/FooTest.php'], $list->files());
        self::assertSame('uncached', $list->primaryReasonFor('tests/FooTest.php'));
        self::assertEquals(new Reason('StaleLayer', 'src/Foo.php', 'feature@abc1234'), $list->reasonsFor('tests/FooTest.php')[0]);
    }

    public function test_a_file_whose_only_results_were_withheld_executes_once_as_stale_not_also_as_no_result(): void
    {
        // FooTest's only results sit in main's layer, which Select\LayerAudit withheld for
        // this pass. The builder then reads no id for it at all: the no-result invariant
        // and the stale bucket both describe it, and it must execute exactly once, with the
        // one reason that says why (StaleLayer), not also "Uncached (no cached result)".
        $graph = $this->graph(['FooTest::a' => 0, 'FooTest::b' => 0]);
        $graph->withholdResults('main', ['tests/FooTest.php']);
        $reason = new Reason('StaleLayer', 'src/Foo.php', 'main@abc1234');
        $stale = ['tests/FooTest.php' => ['reason' => $reason, 'ids' => ['FooTest::a', 'FooTest::b']]];

        $list = $this->builder($graph)->build([], 'feature', $stale);

        self::assertSame(['tests/FooTest.php'], $list->files());
        self::assertSame(['tests/FooTest.php'], $list->stale);
        self::assertNotContains('tests/FooTest.php', $list->unknown);
        self::assertEquals([$reason], $list->reasonsFor('tests/FooTest.php'));
        self::assertSame('uncached', $list->primaryReasonFor('tests/FooTest.php'));

        // Control: the same absence with no audit behind it is the no-result invariant's.
        $graph = $this->graph(['FooTest::a' => 0]);
        $graph->withholdResults('main', ['tests/FooTest.php']);

        $list = $this->builder($graph)->build([], 'feature');

        self::assertSame(['tests/FooTest.php'], $list->files());
        self::assertSame([], $list->stale);
        self::assertEquals([new Reason('Uncached', 'no cached result')], $list->reasonsFor('tests/FooTest.php'));
    }

    public function test_a_stale_file_rerun_for_the_failure_underneath_leads_with_the_stale_reason(): void
    {
        // The own layer's pass was invalid; what is left is main's failure, which re-runs.
        $graph = $this->graph(['FooTest::a' => 7]);
        $stale = ['tests/FooTest.php' => ['reason' => new Reason('StaleLayer', 'src/Foo.php', 'feature@abc1234'), 'ids' => ['FooTest::a']]];

        $list = $this->builder($graph)->build([], 'main', $stale);

        self::assertSame([], $list->stale);
        self::assertSame(['tests/FooTest.php'], $list->rerun);
        self::assertSame(['StaleLayer', 'Rerun'], array_map(static fn (Reason $r): string => $r->rule, $list->reasonsFor('tests/FooTest.php')));
    }

    public function test_select_leaves_the_builders_own_watch_patterns_alone(): void
    {
        $watch = new WatchPatterns();
        $graph = $this->graph();
        $builder = new RunListBuilder(
            $graph,
            new TestPaths(['tests'], [], ['Test.php']),
            $watch,
            ConfigurationReader::fromXmlFile($this->root . '/phpunit.xml'),
            new Policy($graph, Config::defaults(), Quarantine::load($this->root . '/state'), $this->root),
            $this->root,
            [],
            true,
        );

        // src/Unattributed.php is a PHP file no edge names: the residue fallback covers it.
        self::assertNotSame([], $builder->select(['src/Unattributed.php'])->testFiles());
        self::assertSame([], $watch->fallbackPatterns(), 'the residue fallback of an audited diff must not leak into the pass');

        $builder->build(['src/Unattributed.php'], 'main');
        self::assertArrayHasKey('src/Unattributed.php', $watch->fallbackPatterns(), 'control: build() itself does add it');
    }

    public function test_status_names_cover_the_phpunit_status_ints(): void
    {
        self::assertSame('success', RunList::statusName(0));
        self::assertSame('error', RunList::statusName(8));
        self::assertSame('unknown', RunList::statusName(42));
    }
}
