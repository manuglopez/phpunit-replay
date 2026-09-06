<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Hermeticity\Policy;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\PHPUnit\Decision\ReplayIncomplete;
use Manuglopez\Replay\PHPUnit\Decision\ReplayPass;
use Manuglopez\Replay\PHPUnit\Decision\ReplaySkipped;
use Manuglopez\Replay\PHPUnit\Decision\Run;
use Manuglopez\Replay\PHPUnit\Mode;
use Manuglopez\Replay\PHPUnit\ReplayState;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\RunList;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §6.2: the decision table, exercised through `ReplayState::bootForTests()` so
 * no git repository or real PHPUnit run is involved.
 */
final class ReplayStateDecideTest extends TestCase
{
    private const TEST_ID = 'App\Tests\FooTest::testOne';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('replay-decide');

        TempDir::write($this->root . '/tests/FooTest.php', "<?php\n");
        TempDir::write($this->root . '/tests/BarTest.php', "<?php\n");
    }

    #[After]
    public function resetReplayState(): void
    {
        ReplayState::reset();
        TempDir::remove($this->root);
    }

    /** @param array<string, array{status: int, message: string, time: float, assertions: int, file?: string}> $results */
    private function graph(array $results = []): Graph
    {
        $graph = new Graph($this->root);
        $graph->markKnownTestFiles(['tests/FooTest.php']);

        foreach ($results as $testId => $result) {
            $graph->setResult('main', $testId, $result);
        }

        return $graph;
    }

    /** @return array{status: int, message: string, time: float, assertions: int, file: string} */
    private function cachedResult(int $status, int $assertions = 4, string $message = ''): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'time' => 0.25,
            'assertions' => $assertions,
            'file' => 'tests/FooTest.php',
        ];
    }

    /** @param list<string> $affected */
    private function runList(array $affected = []): RunList
    {
        $selection = new Selection();

        foreach ($affected as $file) {
            $selection->add($file, new Reason('PhpEdge', 'src/Foo.php'));
        }

        return new RunList($selection, [], [], []);
    }

    private function reader(bool $failOnRisky = false): ConfigurationReader
    {
        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit failOnRisky="{$this->boolAttribute($failOnRisky)}">
            <testsuites>
                <testsuite name="default">
                    <directory>tests</directory>
                </testsuite>
            </testsuites>
        </phpunit>
        XML;

        TempDir::write($this->root . '/phpunit.xml', $xml);

        return ConfigurationReader::fromXmlFile($this->root . '/phpunit.xml');
    }

    private function boolAttribute(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function boot(Graph $graph, ?RunList $runList = null, bool $failOnRisky = false, Mode $mode = Mode::Replay): void
    {
        ReplayState::bootForTests(
            $mode,
            $this->root,
            $this->root . '/state',
            $graph,
            $runList ?? $this->runList(),
            new Policy($graph, Config::defaults(), Quarantine::load($this->root . '/state'), $this->root),
            $this->reader($failOnRisky),
        );
    }

    private function decide(string $testId = self::TEST_ID, string $file = 'tests/FooTest.php'): object
    {
        return ReplayState::decide($this->root . '/' . $file, $testId);
    }

    public function test_a_file_in_the_run_list_always_runs(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(0)]), $this->runList(['tests/FooTest.php']));

        $decision = $this->decide();

        self::assertInstanceOf(Run::class, $decision);
        self::assertSame('affected', $decision->reason);
        self::assertSame(1, ReplayState::counters()['affected']);
    }

    public function test_a_test_file_the_graph_does_not_know_runs_as_uncached(): void
    {
        $this->boot($this->graph());

        $decision = $this->decide('App\Tests\BarTest::testOne', 'tests/BarTest.php');

        self::assertInstanceOf(Run::class, $decision);
        self::assertSame('uncached', $decision->reason);
        self::assertSame(1, ReplayState::counters()['uncached']);
    }

    public function test_an_id_that_is_not_a_test_method_runs(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(0)]));

        $decision = $this->decide('tests/FooTest.php');

        self::assertInstanceOf(Run::class, $decision);
        self::assertSame('uncached', $decision->reason);
    }

    public function test_a_test_file_outside_the_project_runs(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(0)]));

        $decision = ReplayState::decide('/somewhere/else/FooTest.php', self::TEST_ID);

        self::assertInstanceOf(Run::class, $decision);
        self::assertSame('uncached', $decision->reason);
    }

    public function test_a_test_without_a_cached_result_runs_as_uncached(): void
    {
        $this->boot($this->graph());

        $decision = $this->decide();

        self::assertInstanceOf(Run::class, $decision);
        self::assertSame('uncached', $decision->reason);
    }

    public function test_a_non_cacheable_test_runs(): void
    {
        $graph = $this->graph([self::TEST_ID => $this->cachedResult(0)]);
        $graph->setNotCacheable(['tests/FooTest.php']);

        $this->boot($graph);

        $decision = $this->decide();

        self::assertInstanceOf(Run::class, $decision);
        self::assertSame('not-cacheable', $decision->reason);
        self::assertSame(1, ReplayState::counters()['quarantined']);
    }

    public function test_a_failed_result_is_never_replayed(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(7)]));

        $decision = $this->decide();

        self::assertInstanceOf(Run::class, $decision);
        self::assertSame('rerun', $decision->reason);
    }

    public function test_a_risky_result_is_rerun_when_the_configuration_fails_on_risky(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(5)]), failOnRisky: true);

        $decision = $this->decide();

        self::assertInstanceOf(Run::class, $decision);
        self::assertSame('rerun', $decision->reason);
    }

    public function test_a_risky_result_is_replayed_as_risky_when_the_configuration_tolerates_it(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(5, 0)]));

        $decision = $this->decide();

        self::assertInstanceOf(ReplayPass::class, $decision);
        self::assertTrue($decision->wasRisky);
        self::assertSame(0, $decision->assertions);
    }

    public function test_a_passing_result_is_replayed_with_its_assertion_count(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(0, 4)]));

        $decision = $this->decide();

        self::assertInstanceOf(ReplayPass::class, $decision);
        self::assertFalse($decision->wasRisky);
        self::assertSame(4, $decision->assertions);
        self::assertSame(0.25, $decision->cached['time']);
    }

    public function test_notice_deprecation_and_warning_results_are_replayed_as_passes(): void
    {
        foreach ([3, 4, 6] as $status) {
            ReplayState::reset();
            $this->boot($this->graph([self::TEST_ID => $this->cachedResult($status)]));

            self::assertInstanceOf(ReplayPass::class, $this->decide(), 'status ' . $status);
        }
    }

    public function test_a_skipped_result_is_replayed_as_skipped(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(1, 0, 'fixture skip')]));

        $decision = $this->decide();

        self::assertInstanceOf(ReplaySkipped::class, $decision);
        self::assertSame('fixture skip', $decision->message);
    }

    public function test_an_incomplete_result_is_replayed_as_incomplete(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(2, 0, 'not done')]));

        $decision = $this->decide();

        self::assertInstanceOf(ReplayIncomplete::class, $decision);
        self::assertSame('not done', $decision->message);
    }

    public function test_the_decision_is_memoised_per_test_id(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(0)]));

        self::assertSame($this->decide(), $this->decide());
        self::assertSame(0, ReplayState::counters()['affected']);
    }

    public function test_nothing_is_replayed_outside_replay_mode(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(0)]), mode: Mode::Record);

        $decision = $this->decide();

        self::assertInstanceOf(Run::class, $decision);
        self::assertSame('no-baseline', $decision->reason);
    }

    public function test_nothing_is_replayed_when_the_state_was_never_booted_in_process(): void
    {
        $decision = ReplayState::decide($this->root . '/tests/FooTest.php', self::TEST_ID);

        self::assertInstanceOf(Run::class, $decision);
        self::assertSame('no-baseline', $decision->reason);
        self::assertFalse(ReplayState::isInProcess());
    }

    public function test_mark_replayed_counts_the_test_and_the_time_it_saved(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(0)]));

        $decision = $this->decide();
        self::assertInstanceOf(ReplayPass::class, $decision);

        ReplayState::markReplayed(self::TEST_ID, $decision);
        ReplayState::markReplayed(self::TEST_ID, $decision);

        $counters = ReplayState::counters();
        self::assertSame(1, $counters['replayed']);
        self::assertSame(0, $counters['executed']);
    }

    public function test_mark_replayed_ignores_a_run_decision(): void
    {
        $this->boot($this->graph());

        ReplayState::markReplayed(self::TEST_ID, new Run('affected'));

        self::assertSame(0, ReplayState::counters()['replayed']);
    }

    public function test_the_summary_line_is_null_when_no_in_process_run_happened(): void
    {
        self::assertNull(ReplayState::summaryLine());
    }

    public function test_the_summary_line_reports_the_counters_of_a_replay_run(): void
    {
        $this->boot($this->graph([self::TEST_ID => $this->cachedResult(0)]));

        $decision = $this->decide();
        self::assertInstanceOf(ReplayPass::class, $decision);
        ReplayState::markReplayed(self::TEST_ID, $decision);

        $line = ReplayState::summaryLine();

        self::assertIsString($line);
        self::assertStringContainsString('0 executed (0 affected, 0 uncached)', $line);
        self::assertStringContainsString('1 replayed', $line);
    }
}
