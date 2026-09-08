<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * Bug fix: `run --dry-run` is documented as printing the plan without running anything
 * (README, docs/INTERNALS.md "Wrapper pipeline"), but `RunRequest::$dryRun` was only ever
 * consulted inside `RunPipeline::runReplay()` — the branch reached once a cached baseline
 * already exists. Every path that decides to launch PHPUnit *before* that point ignored
 * the flag entirely:
 *
 * - `runRecord()` (no baseline yet — the first run on a project, or right after a
 *   structural change invalidated the cached one) ran the full suite for real.
 * - `degrade()` (the single funnel every "something is wrong, fall back to a plain
 *   PHPUnit run" case goes through: no coverage driver, environment resolution
 *   failures, an unexpected exception) also ran the full suite for real.
 * - `runResultsOnly()` (a partial CLI selection: `--filter`/`--group`/`--testsuite`/an
 *   explicit path) sits *before* the record/replay decision in `run()`
 *   (`reader->hasPartialSelection()` is checked first), so it never reached the
 *   dry-run block in `runReplay()` either — it had no dry-run check of its own at all,
 *   and ran the filtered suite for real regardless of the flag or of whether a baseline
 *   existed.
 *
 * All three are exercised here via the real `bin/phpunit-replay` wrapper (never
 * in-process): a project with no cached baseline is exactly what a developer's very
 * first `phpunit-replay run --dry-run` looks like, and is also the only way `run` (as
 * opposed to `record`/`verify`) ever reaches the "no coverage driver" reason inside
 * `runRecord()`'s own degrade() call.
 */
final class DryRunNeverExecutesTest extends TestCase
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

    public function test_dry_run_with_no_baseline_runs_nothing_and_writes_no_state(): void
    {
        $fixture = $this->plainFixture();

        $result = $fixture->replay(['--dry-run']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame('Replay  would record the full suite (no cached baseline)', trim($result['stdout']));

        // PHPUnit was never launched: none of its own banner/summary text made it to
        // stdout — the only thing there is the wrapper's own plan line above.
        self::assertStringNotContainsString('PHPUnit', $result['stdout']);
        self::assertStringNotContainsString('Tests:', $result['stdout']);
        self::assertStringNotContainsString('Runtime:', $result['stdout']);

        // No state was written at all — not even the state directory itself, since the
        // check happens before runRecord() constructs a Graph or opens a run directory.
        self::assertFalse(is_dir(ReplayAssert::stateDir($fixture)));
        self::assertNull(ReplayAssert::loadGraph($fixture));
    }

    public function test_dry_run_on_a_degrade_path_runs_nothing_and_writes_no_state(): void
    {
        $fixture = $this->plainFixture();

        // Same "no baseline yet" precondition as above, plus the test-only knob (SPEC §16
        // acceptance criterion 7) that makes DriverDetector behave as if no coverage
        // driver were loaded — this is what routes runRecord() into degrade() instead of
        // recording for real.
        $result = $fixture->replay(['--dry-run'], ['PHPUNIT_REPLAY_FORCE_NO_DRIVER' => '1']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('no coverage driver', $result['stderr']);
        self::assertStringStartsWith(
            'Replay  would run the full suite via plain phpunit (degraded: no coverage driver',
            trim($result['stdout']),
        );

        self::assertStringNotContainsString('PHPUnit', $result['stdout']);
        self::assertStringNotContainsString('Tests:', $result['stdout']);
        self::assertStringNotContainsString('Runtime:', $result['stdout']);

        self::assertFalse(is_dir(ReplayAssert::stateDir($fixture)));
        self::assertNull(ReplayAssert::loadGraph($fixture));
    }

    public function test_dry_run_with_a_partial_selection_runs_nothing_and_writes_no_state(): void
    {
        $fixture = $this->plainFixture();

        // A baseline present or not makes no difference here: hasPartialSelection() sends
        // any --filter/--group/--testsuite/explicit-path run to runResultsOnly() before
        // run() ever looks at whether a graph exists.
        $result = $fixture->replay(['--dry-run', '--', '--filter', 'testAdditionAndSubtractionKeepCurrency']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(
            'Replay  would run only your own selection (--filter/--group/--testsuite/an explicit path); no plan to compute for a partial selection',
            trim($result['stdout']),
        );

        self::assertStringNotContainsString('PHPUnit', $result['stdout']);
        self::assertStringNotContainsString('Tests:', $result['stdout']);
        self::assertStringNotContainsString('Runtime:', $result['stdout']);

        self::assertFalse(is_dir(ReplayAssert::stateDir($fixture)));
        self::assertNull(ReplayAssert::loadGraph($fixture));
    }

    private function plainFixture(): FixtureProject
    {
        return $this->fixtures[] = FixtureProject::plain();
    }
}
