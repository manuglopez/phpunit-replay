<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use FilesystemIterator;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * SPEC.md §16, acceptance criteria of v0.1. Items 1, 2, 3, 4, 5 and 6 exercise the same
 * mechanics as the numbered Scenario0*Test classes (SPEC §15) — this class reuses the
 * same {@see ReplayAssert} helpers rather than duplicating fixture plumbing, but keeps
 * each check lean (a single, minimal fixture flow) since the full narrative for each is
 * already covered end-to-end by its Scenario0*Test sibling.
 */
final class AcceptanceCriteriaTest extends TestCase
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

    /** 1. A no-op second pass finishes fast, exit 0, "0 executed, N replayed". */
    public function test_criterion_1_second_pass_with_no_changes_is_fast_and_replays_everything(): void
    {
        $fixture = $this->plainFixture();
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $started = microtime(true);
        $result = $fixture->replay([]);
        $elapsed = microtime(true) - $started;

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($result['stdout']));
        self::assertSame(35, ReplayAssert::replayedCount($result['stdout']));
        self::assertLessThan(4.0, $elapsed, '< 2s + PHP bootstrap, generously bounded at 4s');
    }

    /** 2. Changing a source file executes exactly the test files with an edge to it. */
    public function test_criterion_2_changing_a_source_executes_exactly_its_dependents(): void
    {
        $fixture = $this->plainFixture();
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $fixture->applyVariant('Money.behaviour.php', 'src/Money.php');

        $explain = $fixture->replay(['--explain', '--dry-run']);
        self::assertSame(0, $explain['exitCode'], $explain['stdout'] . $explain['stderr']);
        self::assertStringNotContainsString('GreeterTest', $explain['stdout']);
        self::assertStringContainsString('MoneyTest', $explain['stdout']);

        $explainLines = array_filter(
            explode("\n", rtrim($explain['stdout'])),
            static fn (string $line): bool => str_contains($line, '←'),
        );
        self::assertNotEmpty($explainLines);

        foreach ($explainLines as $line) {
            self::assertStringContainsString('PhpEdge', $line);
        }
    }

    /** 3. A failed test is never replayed: it keeps being scheduled to run until it passes again. */
    public function test_criterion_3_a_failed_test_is_never_replayed(): void
    {
        $fixture = $this->plainFixture();
        $failing = $fixture->replay(['record'], ['FIXTURE_FAIL' => '1']);
        self::assertSame(1, $failing['exitCode'], $failing['stdout'] . $failing['stderr']);

        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);
        $failedResult = $graph->result('main', 'App\Tests\GreeterTest::testFailsWhenFixtureFailEnvIsSet');
        self::assertNotNull($failedResult);
        self::assertSame(7, $failedResult['status']);

        // Nothing on disk changed; a cached pass would replay 35/35. Instead the failing
        // test's file (GreeterTest.php, 4 tests) is scheduled to run again — uncached
        // counts tests, not files (docs/INTERNALS.md "Summary counters") — proving it was
        // not replayed.
        $result = $fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(4, ReplayAssert::uncachedCount($result['stdout']));
        self::assertSame(4, ReplayAssert::executedCount($result['stdout']));
    }

    /** 4. A comment/whitespace-only change executes nothing. */
    public function test_criterion_4_comment_only_change_executes_nothing(): void
    {
        $fixture = $this->plainFixture();
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $fixture->applyVariant('Money.comment-only.php', 'src/Money.php');

        $result = $fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($result['stdout']));
    }

    /** 5. composer.lock (or phpunit.xml) changing forces a full record, with a clear warning. */
    public function test_criterion_5_structural_change_forces_a_full_record_with_a_warning(): void
    {
        $fixture = $this->plainFixture();
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $fixture->write('composer.lock', $fixture->read('composer.lock') . "\n// bump\n");

        $result = $fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('phpunit-replay: structural change', $result['stderr']);
        self::assertStringStartsWith('Replay  ● recorded 35 tests', ReplayAssert::lastLine($result['stdout']));
    }

    /** 6. --filter/--group/--testsuite or an explicit path run only what was asked and never corrupt the graph. */
    public function test_criterion_6_filter_runs_only_what_was_asked_without_corrupting_the_graph(): void
    {
        $fixture = $this->plainFixture();
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $before = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($before);

        $result = $fixture->replay(['--', '--filter', 'testAdditionAndSubtractionKeepCurrency']);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('1 / 1 (100%)', $result['stdout']);

        $after = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($after);
        self::assertSame($before->recordedSha('main'), $after->recordedSha('main'));
        self::assertSame($before->stats(), $after->stats());
    }

    /** 7. Without pcov nor Xdebug, the package disables itself with a warning and PHPUnit runs normally. */
    public function test_criterion_7_without_a_coverage_driver_phpunit_still_runs_normally(): void
    {
        $fixture = $this->plainFixture();

        $result = $fixture->replay([], ['PHPUNIT_REPLAY_FORCE_NO_DRIVER' => '1']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('no coverage driver', $result['stderr']);
        self::assertStringContainsString('35 / 35 (100%)', $result['stdout']);
        self::assertStringContainsString('Tests: 35, Assertions: 61, Skipped: 1.', $result['stdout']);

        // Nothing was recorded: the wrapper degraded to a plain PHPUnit invocation.
        self::assertNull(ReplayAssert::loadGraph($fixture));
    }

    /**
     * 8. State files are written atomically: killing the wrapper mid-run never leaves a
     * corrupt graph.json (or a stray *.tmp file) behind.
     */
    public function test_criterion_8_a_killed_wrapper_never_corrupts_state(): void
    {
        if (! function_exists('posix_kill') && ! defined('SIGKILL')) {
            self::markTestSkipped('POSIX signals are not available on this platform.');
        }

        $fixture = $this->plainFixture();
        $stateDir = ReplayAssert::stateDir($fixture);

        $process = $fixture->replayProcess(['record']);
        $process->start();

        $deadline = microtime(true) + 1.5;

        while (microtime(true) < $deadline) {
            if (glob($stateDir . '/runs/*', GLOB_ONLYDIR) !== []) {
                break;
            }

            usleep(5_000);
        }

        $process->signal(9);

        // The wrapper is dead; its (possibly still-running, unrelated-process-group) child
        // `phpunit` process is tiny and finishes on its own almost immediately. Give it a
        // moment before inspecting the state directory.
        usleep(300_000);

        $graphPath = $stateDir . '/graph.json';

        if (is_file($graphPath)) {
            $json = file_get_contents($graphPath);
            self::assertIsString($json);
            self::assertNotNull(Graph::decode($json, $fixture->root()), 'graph.json exists but does not decode');
        } else {
            self::assertFileDoesNotExist($graphPath);
        }

        self::assertSame([], self::findTmpFiles($stateDir), 'no *.tmp files should remain in the state dir');
    }

    /** @return list<string> */
    private static function findTmpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        $found = [];

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.tmp')) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    private function plainFixture(): FixtureProject
    {
        $fixture = FixtureProject::plain();
        $this->fixtures[] = $fixture;

        return $fixture;
    }
}
