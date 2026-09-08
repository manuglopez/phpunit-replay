<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * Bug fix: `RunPipeline::run()` — the entry point `record` shares with `run` itself,
 * `RecordCommand` just sets `RunRequest::$record = true` — checked
 * `ConfigurationReader::hasPartialSelection()` before ever looking at `$request->record`,
 * so `record -- --filter=X` (or `--group`, `--testsuite`, an explicit path) silently took
 * the results-only branch meant for `run` (SPEC.md §3.1's "disable selection" paragraph):
 * it wrote no edges, published no baseline, printed no warning, and returned PHPUnit's own
 * exit code — indistinguishable from a `record` that had actually recorded something.
 *
 * A partial run cannot produce the one thing `record` exists to produce — pruning and the
 * published baseline sha both assume the whole suite ran — so `resolveEnvironment()` now
 * refuses this combination outright, before `loadGraph()` ever runs: it degrades exactly
 * like every other "the wrapper's own job cannot proceed" reason. The user's own selection
 * still runs, for real, via the plain unwrapped PHPUnit fallback `degrade()` always uses
 * (so `record` never silently does nothing observable), the graph is left completely
 * untouched, and the existing `$request->record` rule in `degrade()`
 * ({@see \Manuglopez\Replay\Tests\Integration\RecordDegradeExitCodeTest}) turns a passing
 * fallback into exit `2` instead of a false "0".
 *
 * `run`/`verify` are unaffected: only `$request->record` reaches the new check, so a plain
 * `run --filter` keeps taking the results-only path exactly as before
 * ({@see \Manuglopez\Replay\Tests\Integration\Scenario06FilterDoesNotTouchEdgesTest}).
 */
final class RecordPartialSelectionRefusesTest extends TestCase
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

    public function test_record_with_filter_degrades_instead_of_silently_recording_nothing(): void
    {
        $result = $this->fixture->replay(['record', '--', '--filter', 'testAdditionAndSubtractionKeepCurrency']);

        self::assertStringContainsString(
            'record does not accept a partial PHPUnit selection',
            $result['stderr'],
        );
        self::assertStringContainsString('record degraded', $result['stderr']);
        self::assertStringContainsString('NOT refreshed', $result['stderr']);

        // The selection still ran for real, via the plain PHPUnit fallback: `record`
        // never silently does nothing, the tests actually executed and passed.
        self::assertStringContainsString('1 / 1 (100%)', $result['stdout']);

        // But nothing was recorded: no graph, no state directory at all.
        self::assertFalse(is_dir(ReplayAssert::stateDir($this->fixture)));
        self::assertNull(ReplayAssert::loadGraph($this->fixture));

        // Mirrors RecordDegradeExitCodeTest: a degraded `record` whose fallback run itself
        // passed must not exit 0 — nothing was published.
        self::assertSame(2, $result['exitCode'], $result['stdout'] . $result['stderr']);
    }

    public function test_record_with_group_degrades_instead_of_silently_recording_nothing(): void
    {
        // None of the fixture's tests declare an explicit #[Group]; PHPUnit implicitly
        // assigns every one of them to "default", so this still counts as a partial
        // selection (`ConfigurationReader::hasPartialSelection()` checks `hasGroups()`
        // regardless of how many tests the group actually matches).
        $result = $this->fixture->replay(['record', '--', '--group', 'default']);

        self::assertStringContainsString(
            'record does not accept a partial PHPUnit selection',
            $result['stderr'],
        );
        self::assertStringContainsString('record degraded', $result['stderr']);
        self::assertStringContainsString('NOT refreshed', $result['stderr']);

        self::assertFalse(is_dir(ReplayAssert::stateDir($this->fixture)));
        self::assertNull(ReplayAssert::loadGraph($this->fixture));
        self::assertSame(2, $result['exitCode'], $result['stdout'] . $result['stderr']);
    }

    public function test_record_with_an_explicit_path_degrades_instead_of_silently_recording_nothing(): void
    {
        $result = $this->fixture->replay(['record', '--', 'tests/MoneyTest.php']);

        self::assertStringContainsString(
            'record does not accept a partial PHPUnit selection',
            $result['stderr'],
        );
        self::assertStringContainsString('record degraded', $result['stderr']);
        self::assertStringContainsString('NOT refreshed', $result['stderr']);

        self::assertFalse(is_dir(ReplayAssert::stateDir($this->fixture)));
        self::assertNull(ReplayAssert::loadGraph($this->fixture));
        self::assertSame(2, $result['exitCode'], $result['stdout'] . $result['stderr']);
    }

    public function test_an_existing_baseline_survives_a_record_filter_attempt_completely_untouched(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graphPath = ReplayAssert::graphPath($this->fixture);
        $rawBefore = file_get_contents($graphPath);
        self::assertIsString($rawBefore);

        $result = $this->fixture->replay(['record', '--', '--filter', 'testAdditionAndSubtractionKeepCurrency']);

        self::assertStringContainsString(
            'record does not accept a partial PHPUnit selection',
            $result['stderr'],
        );
        self::assertSame(2, $result['exitCode'], $result['stdout'] . $result['stderr']);

        // The refusal happens before loadGraph() ever runs: the previously recorded
        // baseline is not merely equivalent, it is byte-for-byte untouched on disk.
        self::assertSame($rawBefore, file_get_contents($graphPath));
    }

    public function test_run_with_filter_is_unaffected_and_keeps_using_results_only_mode(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $result = $this->fixture->replay(['--', '--filter', 'testAdditionAndSubtractionKeepCurrency']);

        // Unchanged: silent (no warning at all) and exit-code-transparent, exactly like
        // Scenario06FilterDoesNotTouchEdgesTest already asserts.
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('1 / 1 (100%)', $result['stdout']);
        self::assertStringNotContainsString('record does not accept', $result['stderr']);
        self::assertStringNotContainsString('record degraded', $result['stderr']);

        self::assertNotNull(ReplayAssert::loadGraph($this->fixture));
    }
}
