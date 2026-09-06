<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 5: a failing test is recorded as such and keeps re-executing on
 * every subsequent pass (`ConfigurationReader::shouldRerun()`: status 7/8 always rerun)
 * even when nothing else changed, until it passes again — at which point it goes back to
 * being replayed like everything else. SPEC §16 acceptance criterion 3 ("a failed test is
 * never replayed") is exercised by the same sequence.
 *
 * `FIXTURE_FAIL` is a runtime environment variable, not a file change, so it can only ever
 * be observed by the wrapper while PHPUnit is actually running — which only happens on a
 * `record` pass (a `run` with nothing on disk changed takes the "nothing to execute"
 * shortcut and never launches PHP at all). So the very first invocation here is the
 * `record` pass itself, with `FIXTURE_FAIL=1` already set: the baseline it establishes
 * already contains the recorded failure.
 */
final class Scenario05FailingTestIsRerunTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_a_failure_is_recorded_and_rerun_until_it_passes_again(): void
    {
        $recorded = $this->fixture->replay(['record'], ['FIXTURE_FAIL' => '1']);
        self::assertSame(1, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        $failingId = 'App\Tests\GreeterTest::testFailsWhenFixtureFailEnvIsSet';
        $failedResult = $graph->result('main', $failingId);
        self::assertNotNull($failedResult);
        self::assertSame(7, $failedResult['status']);

        // Without the env var and without any other change: the failing test's file is
        // rerun (its cached status says shouldRerun()), passes, and the wrapper exits 0.
        $rerun = $this->fixture->replay([]);
        self::assertSame(0, $rerun['exitCode'], $rerun['stdout'] . $rerun['stderr']);
        self::assertSame(4, ReplayAssert::executedCount($rerun['stdout']));
        self::assertSame(0, ReplayAssert::affectedCount($rerun['stdout']));
        self::assertSame(1, ReplayAssert::uncachedCount($rerun['stdout']));
        self::assertSame(31, ReplayAssert::replayedCount($rerun['stdout']));

        $graphAfterRerun = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphAfterRerun);
        $passedResult = $graphAfterRerun->result('main', $failingId);
        self::assertNotNull($passedResult);
        self::assertSame(0, $passedResult['status']);

        // Now that it passed again, a further run with nothing changed executes 0.
        $again = $this->fixture->replay([]);
        self::assertSame(0, $again['exitCode'], $again['stdout'] . $again['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($again['stdout']));
        self::assertSame(35, ReplayAssert::replayedCount($again['stdout']));
    }
}
