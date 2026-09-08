<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * Bug fix: `ReplayState::decideMode()` checks `ConfigurationReader::hasPartialSelection()`
 * before `$config->mode === 'record'`, so a project whose `phpunit.xml`/config declares the
 * standing `mode: record` (SPEC.md §6.1) — "always record", not just "record because there
 * is no baseline yet" — silently downgraded to `Mode::ResultsOnly` whenever a developer ran
 * `phpunit --filter=X` directly (no wrapper): no graph refresh, and nothing said.
 *
 * Downgrading to results-only IS the correct behaviour here (a partial run cannot produce
 * a valid baseline, same reasoning as the wrapper's own `record` command, SPEC.md §3.3) —
 * a filtered run mid-development is an ordinary thing to do. What was missing was only
 * visibility. Considered and rejected: an unconditional `Warnings::warn()` (would fire on
 * most filtered runs in a `mode: record` project during normal development — noise people
 * learn to ignore) and a `Warnings::debug()`-gated one (invisible unless
 * `PHPUNIT_REPLAY_DEBUG=1` is already set, useless to the person it's meant to help — the
 * one wondering why their baseline never refreshes).
 *
 * Fixed instead by piggybacking on the summary line the extension already prints
 * unconditionally once per in-process run: `Report\Summary` gains a
 * `record mode: baseline NOT refreshed (partial selection)` segment when
 * `ReplayState::$recordModeDowngraded` is true — set only when the mode a selection
 * defeated was the explicit, standing `mode: record`, never `auto`'s ordinary "no baseline
 * yet" (the same silent first-run bootstrap `run --filter` already shares through the
 * wrapper, which {@see Scenario10InProcessReplayTest::test_a_filtered_run_is_results_only_and_leaves_the_baseline_alone()}
 * already covers staying silent — this file's second test asserts the new wording
 * specifically stays absent there too).
 */
final class RecordModeFilteredRunVisibilityTest extends TestCase
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

    private function fixture(): FixtureProject
    {
        $fixture = FixtureProject::inprocess();
        $this->fixtures[] = $fixture;

        return $fixture;
    }

    /** Flips this fixture instance's own copy of phpunit.xml to the standing `mode="record"`. */
    private function configureStandingRecordMode(FixtureProject $fixture): void
    {
        $xml = $fixture->read('phpunit.xml');
        self::assertStringContainsString('value="auto"', $xml);

        $fixture->write('phpunit.xml', str_replace('value="auto"', 'value="record"', $xml));
    }

    public function test_a_filtered_run_under_standing_record_mode_says_the_baseline_was_not_refreshed(): void
    {
        $fixture = $this->fixture();
        $this->configureStandingRecordMode($fixture);

        $first = $fixture->phpunitInProcess([], ['PHPUNIT_REPLAY_DEBUG' => '1']);
        self::assertSame(0, $first['exitCode'], $first['stdout'] . $first['stderr']);
        self::assertStringContainsString('Replay  ● recorded', $first['stdout']);

        $before = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($before);
        $shaBefore = $before->recordedSha('main');
        self::assertNotNull($shaBefore);

        // Still `mode="record"`: an ordinary second run with no filter would re-record the
        // whole suite again from scratch (that is the point of a standing "always record"
        // configuration) — this run instead carries a filter, so it must downgrade.
        $filtered = $fixture->phpunitInProcess(['--filter', 'GreeterTest'], ['PHPUNIT_REPLAY_DEBUG' => '1']);

        self::assertSame(0, $filtered['exitCode'], $filtered['stdout'] . $filtered['stderr']);
        self::assertStringContainsString('OK (4 tests, 8 assertions)', $filtered['stdout']);

        // The signal the fix adds: still on the summary line that already prints
        // unconditionally, no new warning channel opened.
        self::assertStringContainsString(
            'record mode: baseline NOT refreshed (partial selection)',
            $filtered['stdout'],
        );

        // Downgrading to results-only is still the correct behaviour: no baseline sha
        // rewrite from a run that never covered the suite.
        $after = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($after);
        self::assertSame($shaBefore, $after->recordedSha('main'));
    }

    public function test_a_filtered_run_under_the_default_auto_mode_stays_silent_about_it(): void
    {
        // Unmodified fixture: mode="auto" — the ordinary case (already covered
        // behaviourally by Scenario10InProcessReplayTest's own filtered-run test), asserted
        // here for the specific new wording rather than the general behaviour.
        $fixture = $this->fixture();

        $first = $fixture->phpunitInProcess();
        self::assertSame(0, $first['exitCode'], $first['stdout'] . $first['stderr']);

        $filtered = $fixture->phpunitInProcess(['--filter', 'GreeterTest']);

        self::assertSame(0, $filtered['exitCode'], $filtered['stdout'] . $filtered['stderr']);
        self::assertStringNotContainsString('record mode', $filtered['stdout']);
        self::assertStringNotContainsString('NOT refreshed', $filtered['stdout']);
    }
}
