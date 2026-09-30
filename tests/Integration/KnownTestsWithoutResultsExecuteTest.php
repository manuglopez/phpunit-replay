<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * A test file the graph knows must never be neither executed nor replayed. After an
 * environmental drift the pipeline clears the cached results and keeps the edges; with
 * nothing changed on disk the run list used to be empty and the replay set empty too, so
 * the pass printed `0 executed … 0 replayed` and exited 0 while a plain `phpunit` failed.
 * The invariant closes that (and every other way results go missing): a known test file
 * with no servable result executes, reported as `Uncached`.
 */
final class KnownTestsWithoutResultsExecuteTest extends TestCase
{
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

    private function recorded(): FixtureProject
    {
        $fixture = FixtureProject::plain();
        $this->fixtures[] = $fixture;

        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        return $fixture;
    }

    /** Flips the stored coverage driver, the way a machine moving from pcov to xdebug would. */
    private function drift(FixtureProject $fixture): void
    {
        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);

        $fingerprint = $graph->fingerprint();
        $before = $fingerprint['environmental']['driver'] ?? null;
        $fingerprint['environmental']['driver'] = $before === 'xdebug' ? 'pcov' : 'xdebug';
        $graph->setFingerprint($fingerprint);

        $encoded = $graph->encode();
        self::assertNotNull($encoded);
        file_put_contents(ReplayAssert::graphPath($fixture), $encoded);
    }

    public function test_the_wrapper_executes_known_tests_whose_results_an_environment_change_cleared(): void
    {
        $fixture = $this->recorded();
        $this->drift($fixture);

        $result = $fixture->replay([], ['FIXTURE_FAIL' => '1']);

        self::assertStringContainsString('environment change (driver)', $result['stderr']);
        self::assertNotSame(0, $result['exitCode'], 'a failing test must not be masked: ' . $result['stdout']);
        self::assertGreaterThan(0, ReplayAssert::uncachedCount($result['stdout']), $result['stdout']);
        self::assertSame(0, ReplayAssert::replayedCount($result['stdout']), $result['stdout']);
    }

    public function test_the_run_after_it_heals_and_replays_everything(): void
    {
        $fixture = $this->recorded();
        $this->drift($fixture);

        $healing = $fixture->replay([]);
        self::assertSame(0, $healing['exitCode'], $healing['stdout'] . $healing['stderr']);
        self::assertGreaterThan(0, ReplayAssert::executedCount($healing['stdout']), $healing['stdout']);

        $next = $fixture->replay([]);
        self::assertSame(0, $next['exitCode'], $next['stdout'] . $next['stderr']);
        self::assertStringNotContainsString('environment change', $next['stderr'], 'the drift warning must not repeat forever');
        self::assertSame(0, ReplayAssert::executedCount($next['stdout']), $next['stdout']);
        self::assertSame(35, ReplayAssert::replayedCount($next['stdout']), $next['stdout']);
    }

    public function test_explain_names_the_reason_as_uncached(): void
    {
        $fixture = $this->recorded();
        $this->drift($fixture);

        $explain = $fixture->replay(['--explain', '--dry-run']);

        self::assertStringContainsString('tests/GreeterTest.php', $explain['stdout']);
        self::assertStringContainsString('Uncached', $explain['stdout']);
    }

    public function test_positive_control_an_unchanged_run_still_executes_nothing(): void
    {
        $fixture = $this->recorded();

        $result = $fixture->replay([]);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($result['stdout']), $result['stdout']);
        self::assertSame(35, ReplayAssert::replayedCount($result['stdout']), $result['stdout']);
    }

    public function test_in_process_executes_known_tests_whose_results_an_environment_change_cleared(): void
    {
        $fixture = FixtureProject::inprocess();
        $this->fixtures[] = $fixture;
        $debug = ['PHPUNIT_REPLAY_DEBUG' => '1'];

        $recorded = $fixture->phpunitInProcess([], $debug);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->drift($fixture);

        $result = $fixture->phpunitInProcess([], [...$debug, 'FIXTURE_FAIL' => '1']);

        self::assertStringContainsString('environment change (driver)', $result['stderr']);
        self::assertNotSame(0, $result['exitCode'], 'a failing test must not be masked: ' . $result['stdout']);
        self::assertStringContainsString('FIXTURE_FAIL was set', $result['stdout']);

        $healing = $fixture->phpunitInProcess([], $debug);
        self::assertSame(0, $healing['exitCode'], $healing['stdout'] . $healing['stderr']);

        $next = $fixture->phpunitInProcess([], $debug);
        self::assertSame(0, $next['exitCode'], $next['stdout'] . $next['stderr']);
        self::assertStringNotContainsString('environment change', $next['stderr']);
        self::assertSame(0, preg_match('/decide .+ -> run \((uncached|affected)/', $next['stderr']), $next['stderr']);
    }
}
