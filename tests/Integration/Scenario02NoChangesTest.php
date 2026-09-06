<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 2: a second invocation with no changes at all executes nothing
 * and replays everything from the cached graph, fast, without leaving a `runs/` dir behind.
 */
final class Scenario02NoChangesTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_second_run_with_no_changes_replays_everything(): void
    {
        $graphBefore = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphBefore);
        $statsBefore = $graphBefore->stats();

        $started = microtime(true);
        $result = $this->fixture->replay([]);
        $elapsed = microtime(true) - $started;

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString(
            '0 executed (0 affected, 0 uncached) · 35 replayed',
            ReplayAssert::lastLine($result['stdout']),
        );

        // Wall time of the wrapper itself: comfortably under 2s + PHP startup. 4s keeps
        // this from flaking under CI load while still catching an accidental full re-run.
        self::assertLessThan(4.0, $elapsed, 'a no-op replay pass should be fast');

        // The wrapper deletes each runs/<id> directory in `finally`; only an emptied
        // (already-existing, from setUp's `record` call) `runs/` parent may remain.
        self::assertSame([], glob(ReplayAssert::stateDir($this->fixture) . '/runs/*', GLOB_ONLYDIR) ?: []);

        $graphAfter = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphAfter);
        self::assertSame($statsBefore, $graphAfter->stats());
    }
}
