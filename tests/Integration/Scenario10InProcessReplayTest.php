<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 10 and §16 criterion 3: real PHPUnit runs inside the `inprocess`
 * fixture (no wrapper — just `vendor/bin/phpunit`, the extension registered in the
 * fixture's own phpunit.xml and the `Replayable` trait on its base test case).
 *
 * The fixture's `setUp()` writes the number of times its expensive half actually ran to
 * `<root>/.setup-count`, which is how these tests prove a replayed test never paid for it.
 */
final class Scenario10InProcessReplayTest extends TestCase
{
    /** Every test file of the fixture with an edge to src/Money.php (see Scenario03). */
    private const MONEY_DEPENDENT_CLASSES = [
        'App\Tests\CartTest',
        'App\Tests\CommentedTest',
        'App\Tests\DependsTest',
        'App\Tests\DiscountTest',
        'App\Tests\MoneyTest',
        'App\Tests\TaxCalculatorTest',
    ];

    /** @var list<FixtureProject> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        $this->fixtures = [];

        parent::tearDown();
    }

    private function fixture(): FixtureProject
    {
        $fixture = FixtureProject::inprocess();
        $this->fixtures[] = $fixture;

        return $fixture;
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function phpunit(FixtureProject $fixture, array $args = [], array $env = []): array
    {
        @unlink($fixture->root() . '/.setup-count');

        return $fixture->phpunitInProcess($args, ['PHPUNIT_REPLAY_DEBUG' => '1', ...$env]);
    }

    /** How many times the fixture's expensive setUp() body ran; 0 when it never did. */
    private function setUpCount(FixtureProject $fixture): int
    {
        $path = $fixture->root() . '/.setup-count';

        return is_file($path) ? (int) trim((string) file_get_contents($path)) : 0;
    }

    /**
     * Test ids the run decided to execute for real, from the `PHPUNIT_REPLAY_DEBUG` trace.
     *
     * @return array<string, string> test id => reason
     */
    private function executed(string $stderr): array
    {
        preg_match_all('/decide (.+) -> run \((.+)\)/', $stderr, $matches, PREG_SET_ORDER);

        $executed = [];

        foreach ($matches as $match) {
            $executed[$match[1]] = $match[2];
        }

        return $executed;
    }

    private function graph(FixtureProject $fixture): Graph
    {
        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph, 'expected a graph at ' . ReplayAssert::graphPath($fixture));

        return $graph;
    }

    public function test_the_first_run_records_the_baseline(): void
    {
        $fixture = $this->fixture();

        $result = $this->phpunit($fixture);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Tests: 35, Assertions: 61', $result['stdout']);
        self::assertStringContainsString('Replay  ● recorded', $result['stdout']);

        $graph = $this->graph($fixture);
        self::assertCount(35, $graph->results('main'));
        self::assertNotNull($graph->recordedSha('main'));

        // tests/TestCase.php is a genuine edge of every test file here: the base class
        // runs on every test, so changing it must re-run the whole suite.
        self::assertEqualsCanonicalizing(
            ['tests/CartTest.php', 'tests/TestCase.php', 'src/Cart.php', 'src/TaxCalculator.php', 'src/Money.php'],
            $graph->dependenciesOf('tests/CartTest.php'),
        );

        // Nothing is replayable yet, so every test paid for its setUp().
        self::assertSame(35, $this->setUpCount($fixture));
    }

    public function test_a_second_run_replays_everything_except_the_depends_provider(): void
    {
        $fixture = $this->fixture();

        $first = $this->phpunit($fixture);
        self::assertSame(0, $first['exitCode'], $first['stdout'] . $first['stderr']);

        $second = $this->phpunit($fixture);

        // Same tests, same assertion total, still green with failOnRisky="true".
        self::assertSame(0, $second['exitCode'], $second['stdout'] . $second['stderr']);
        self::assertStringContainsString('OK, but some tests were skipped!', $second['stdout']);
        self::assertStringContainsString('Tests: 35, Assertions: 61, Skipped: 1.', $second['stdout']);
        self::assertStringContainsString(
            'Replay  ✓ 1 executed (0 affected, 1 uncached) · 34 replayed',
            $second['stdout'],
        );

        // SPEC §6.3: a test other tests #[Depends] on is the one thing never replayed.
        self::assertSame(
            ['App\Tests\DependsTest::testFirst' => 'depends-provider'],
            $this->executed($second['stderr']),
        );

        // ...and its dependent still gets the real return value, so it still passes.
        self::assertStringNotContainsString('DependsTest::testSecondUsesReturnedMoney', $second['stdout']);

        // Only the one executed test ran the expensive setUp() body.
        self::assertSame(1, $this->setUpCount($fixture));
    }

    public function test_only_dependents_of_a_changed_source_execute(): void
    {
        $fixture = $this->fixture();

        $first = $this->phpunit($fixture);
        self::assertSame(0, $first['exitCode'], $first['stdout'] . $first['stderr']);

        $fixture->applyVariant('Money.behaviour.php', 'src/Money.php');

        $second = $this->phpunit($fixture);

        self::assertSame(0, $second['exitCode'], $second['stdout'] . $second['stderr']);
        self::assertStringContainsString('Tests: 35, Assertions: 61, Skipped: 1.', $second['stdout']);

        $executed = array_keys($this->executed($second['stderr']));
        self::assertNotSame([], $executed);

        foreach ($executed as $testId) {
            $class = substr($testId, 0, (int) strpos($testId, '::'));
            self::assertContains($class, self::MONEY_DEPENDENT_CLASSES, $testId . ' should not have executed');
        }

        // GreeterTest has no edge to Money at all: its four tests replay.
        self::assertStringContainsString('Replay  ✓ 31 executed (31 affected, 0 uncached) · 4 replayed', $second['stdout']);
        self::assertSame(31, $this->setUpCount($fixture));
    }

    public function test_a_failing_test_is_recorded_and_re_executed_on_the_next_run(): void
    {
        $fixture = $this->fixture();

        $failing = $this->phpunit($fixture, env: ['FIXTURE_FAIL' => '1']);

        self::assertSame(1, $failing['exitCode'], $failing['stdout'] . $failing['stderr']);
        self::assertStringContainsString('Failures: 1', $failing['stdout']);

        $graph = $this->graph($fixture);
        $cached = $graph->result('main', 'App\Tests\GreeterTest::testFailsWhenFixtureFailEnvIsSet');
        self::assertNotNull($cached);
        self::assertSame(7, $cached['status']);

        $recovered = $this->phpunit($fixture);

        self::assertSame(0, $recovered['exitCode'], $recovered['stdout'] . $recovered['stderr']);

        $executed = $this->executed($recovered['stderr']);
        self::assertArrayHasKey('App\Tests\GreeterTest::testFailsWhenFixtureFailEnvIsSet', $executed);
        self::assertSame('affected', $executed['App\Tests\GreeterTest::testFailsWhenFixtureFailEnvIsSet']);

        // The whole file is re-run (that is the unit the run list works in), nothing else.
        self::assertSame(5, count($executed), implode(', ', array_keys($executed)));

        $after = $this->graph($fixture);
        $healed = $after->result('main', 'App\Tests\GreeterTest::testFailsWhenFixtureFailEnvIsSet');
        self::assertNotNull($healed);
        self::assertSame(0, $healed['status']);
    }

    public function test_the_legacy_reflection_hook_replays_the_same_way(): void
    {
        $fixture = $this->fixture();

        $first = $this->phpunit($fixture);
        self::assertSame(0, $first['exitCode'], $first['stdout'] . $first['stderr']);

        $second = $this->phpunit($fixture, env: ['PHPUNIT_REPLAY_LEGACY_HOOK' => '1']);

        self::assertSame(0, $second['exitCode'], $second['stdout'] . $second['stderr']);
        self::assertStringContainsString('Tests: 35, Assertions: 61, Skipped: 1.', $second['stdout']);
        self::assertStringContainsString(
            'Replay  ✓ 1 executed (0 affected, 1 uncached) · 34 replayed',
            $second['stdout'],
        );
        self::assertSame(1, $this->setUpCount($fixture));
    }

    public function test_a_filtered_run_is_results_only_and_leaves_the_baseline_alone(): void
    {
        $fixture = $this->fixture();

        $first = $this->phpunit($fixture);
        self::assertSame(0, $first['exitCode'], $first['stdout'] . $first['stderr']);

        $before = $this->graph($fixture);
        $sha = $before->recordedSha('main');
        $edges = $before->dependenciesOf('tests/CartTest.php');

        $filtered = $this->phpunit($fixture, ['--filter', 'GreeterTest']);

        self::assertSame(0, $filtered['exitCode'], $filtered['stdout'] . $filtered['stderr']);
        self::assertStringContainsString('OK (4 tests, 8 assertions)', $filtered['stdout']);

        // Nothing is replayed under a partial selection: the user asked for those tests.
        self::assertCount(4, $this->executed($filtered['stderr']));
        self::assertSame(4, $this->setUpCount($fixture));

        $after = $this->graph($fixture);
        self::assertSame($sha, $after->recordedSha('main'));
        self::assertSame($edges, $after->dependenciesOf('tests/CartTest.php'));
        self::assertCount(35, $after->results('main'));
    }

    public function test_the_wrapper_still_drives_a_suite_that_uses_the_trait(): void
    {
        $fixture = $this->fixture();

        // The wrapper sets PHPUNIT_REPLAY_MODE, so the extension takes its phase-1 path
        // and the trait must be a transparent no-op: filtered mode does the selecting.
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
        self::assertStringContainsString('Tests: 35, Assertions: 61', $recorded['stdout']);

        $replayed = $fixture->replay([]);
        self::assertSame(0, $replayed['exitCode'], $replayed['stdout'] . $replayed['stderr']);
        self::assertStringContainsString(
            '0 executed (0 affected, 0 uncached) · 35 replayed',
            ReplayAssert::lastLine($replayed['stdout']),
        );
    }

    public function test_disabling_replay_leaves_plain_phpunit_and_writes_no_state(): void
    {
        $fixture = $this->fixture();

        $result = $this->phpunit($fixture, env: ['PHPUNIT_REPLAY' => '0']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Tests: 35, Assertions: 61, Skipped: 1.', $result['stdout']);
        self::assertStringNotContainsString('Replay ', $result['stdout']);
        self::assertDirectoryDoesNotExist(ReplayAssert::stateDir($fixture));
        self::assertSame(35, $this->setUpCount($fixture));
    }
}
