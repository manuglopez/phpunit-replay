<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use PHPUnit\Framework\TestCase;

/**
 * Bug fix: `RunPipeline::verify()`'s `$wouldReplay` loop used to iterate
 * `$graph->results($this->branch)` — the whole cached corpus, every baseline layer
 * merged — instead of the tests this run actually executed. A test the run never
 * touched has the same entry before and after `GraphUpdater::apply()`, so it trivially
 * matched itself and was counted: "would replay" became a figure about the cache rather
 * than about the verification, able to exceed the "N tests" printed right beside it
 * (reported from a real suite as "would replay" jumping to 6304, then 9056, across two
 * identical back-to-back runs). It now iterates `array_keys($partial->results)` — only
 * the tests this run actually executed.
 *
 * The fixture project (`tests/Fixtures/Projects/plain`) has 35 tests; this asserts that
 * a `verify` filtered down to exactly 1 of them reports "1 tests" — already correct
 * before this fix, since it always came from `count($partial->results)` — alongside a
 * "would replay" that is capped at that same 1, never inflated by the other 34 cached
 * entries this run never touched.
 */
final class VerifyWouldReplayCountsExecutedOnlyTest extends TestCase
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

    public function test_a_filtered_verify_reports_would_replay_no_higher_than_the_tests_it_ran(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        // A clean, unfiltered `verify` first, so the cached key for the filtered test
        // below is already known-stable (not required for the bug, just keeps the
        // second assertion below unambiguous: the test we filter to is cacheable and
        // matches on the very next pass).
        $first = $this->fixture->replay(['verify']);
        self::assertSame(0, $first['exitCode'], $first['stdout'] . $first['stderr']);
        self::assertStringContainsString('35 tests · 35 would replay', $first['stdout']);

        $filtered = $this->fixture->replay(['verify', '--', '--filter', 'testAdditionAndSubtractionKeepCurrency']);

        self::assertSame(0, $filtered['exitCode'], $filtered['stdout'] . $filtered['stderr']);
        self::assertStringContainsString('1 / 1 (100%)', $filtered['stdout']);

        // The one and only meaningful assertion: a run that executed 1 test cannot
        // truthfully report more than 1 "would replay" — the other 34 cached results
        // were never touched by this run and must not be counted.
        self::assertStringContainsString('1 tests · 1 would replay', $filtered['stdout']);
        self::assertStringNotContainsString('1 tests · 35 would replay', $filtered['stdout']);
    }
}
