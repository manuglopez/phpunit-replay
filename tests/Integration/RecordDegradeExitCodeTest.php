<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * Reported from a real 9056-test suite: the wrapper hit a removed PHPUnit method mid
 * `record`, `RunPipeline::degrade()` caught it, ran the full suite for real via a plain
 * `PhpunitProcess`, printed a normal PHPUnit summary, and returned PHPUnit's own exit
 * code — 0, every test passed. `record` wrote no graph and no state directory at all;
 * the only reason anyone noticed was running `ls`. `record`'s only job is the graph, so
 * a degrade that produces none of it must not look like success.
 *
 * `degrade()` now escalates to exit `2` (mirrors PHPUnit's own `ShellExitCodeCalculator`
 * convention: 0 pass, 1 test failures, 2 something outside the tests went wrong) when
 * `RunRequest::$record` is true and the fallback run's own exit code was 0 — the case
 * that used to read as clean success. A fallback that itself fails for real (nonzero)
 * is untouched: that already turns red for the right reason.
 *
 * `run` (used here via the forced-no-driver knob, {@see \Manuglopez\Replay\Tests\Integration\AcceptanceCriteriaTest}
 * criterion 7) must keep its own exit code unchanged when it degrades — that fallback is
 * documented, legitimate behaviour, and its exit code is the actual pass/fail signal CI
 * gates on.
 */
final class RecordDegradeExitCodeTest extends TestCase
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

    public function test_a_degraded_record_exits_nonzero_and_writes_no_graph_even_though_the_tests_passed(): void
    {
        $result = $this->fixture->replay(['record'], ['PHPUNIT_REPLAY_FORCE_NO_DRIVER' => '1']);

        // PHPUnit really did run, for real, and every test really did pass — the wrapper
        // must never hide that.
        self::assertStringContainsString('no coverage driver', $result['stderr']);
        self::assertStringContainsString('35 / 35 (100%)', $result['stdout']);
        self::assertStringContainsString('Tests: 35, Assertions: 61, Skipped: 1.', $result['stdout']);

        // Nothing was recorded.
        self::assertFalse(is_dir(ReplayAssert::stateDir($this->fixture)));
        self::assertNull(ReplayAssert::loadGraph($this->fixture));

        // But unlike a degraded `run` (AcceptanceCriteriaTest::test_criterion_7_...,
        // which asserts exitCode === 0 for the very same forced-no-driver knob), a
        // degraded `record` must not exit 0: nothing was published, and record's whole
        // reason to exist is publishing something.
        self::assertSame(2, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('record degraded', $result['stderr']);
        self::assertStringContainsString('NOT refreshed', $result['stderr']);
    }

    public function test_a_degraded_run_still_exits_zero_when_the_fallback_suite_passes(): void
    {
        // Same forced degrade, plain `run` instead of `record`: this must be entirely
        // unaffected by the fix above.
        $result = $this->fixture->replay([], ['PHPUNIT_REPLAY_FORCE_NO_DRIVER' => '1']);

        self::assertStringContainsString('no coverage driver', $result['stderr']);
        self::assertStringContainsString('35 / 35 (100%)', $result['stdout']);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertNull(ReplayAssert::loadGraph($this->fixture));
    }
}
