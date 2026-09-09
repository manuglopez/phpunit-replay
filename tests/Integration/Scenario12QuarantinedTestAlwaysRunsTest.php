<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 12 / §8.3: a test whose outcome flips (same content key, a
 * different result status class) is quarantined automatically and always executes for
 * real afterward — until `prune --flaky` releases it.
 *
 * `FlakyTest` depends only on the `FIXTURE_FLIP` environment variable, so its content
 * key never changes between passes. What forces it to *execute* against the existing
 * graph, rather than being skipped by the normal replay decision, is `verify`: a
 * full-suite recording pass that always re-executes everything and compares each result
 * against the baseline (SPEC.md §12.2), so it re-observes an environment-only change a
 * normal, cache-trusting `run` never would.
 *
 * Bug fix: this used to force the re-execution with a wrapper-level `run --filter
 * FlakyTest` instead — `--filter` narrows what PHPUnit runs, and (until the false-green
 * vector fix this class's sibling {@see RunPartialSelectionPersistsNothingTest} covers)
 * "results-only" mode still merged that one result into the graph regardless. A CLI
 * selection now persists nothing at all (no edges, no results, no quarantine write —
 * rule 2), so it can no longer be the mechanism that surfaces a flip: `verify`, a
 * full-suite pass with no CLI selection at all, is the one this package still lets
 * observe and quarantine a flake (its OWN divergence detection, `reason: 'divergence'`,
 * not the generic `'flip'` a CLI-selected results-only merge used to write).
 *
 * `FlakyTest` is also `#[NotCacheable]` now (added for
 * {@see QuarantineFlipDetectionThroughAPlainRunTest}'s sake, so a genuine `'flip'` stays
 * reachable through a plain, unfiltered `run` too) — a separate, permanent reason it
 * always executes, independent of quarantine. That is why the final assertion here
 * checks `Quarantine::isQuarantined()` directly rather than the summary's
 * "executed"/"quarantined" counts the way it used to: those counts can no longer tell
 * "still quarantined" apart from "not cacheable regardless."
 */
final class Scenario12QuarantinedTestAlwaysRunsTest extends TestCase
{
    private const FLAKY_ID = 'App\Tests\FlakyTest::testDependsOnAnExternalFlag';

    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
        $this->fixture->applyVariant('FlakyTest.flaky.php', 'tests/FlakyTest.php');
        $this->fixture->repo->commitAll('add FlakyTest');
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_a_flip_quarantines_the_test_until_it_is_pruned(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $baseline = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($baseline);
        $before = $baseline->result('main', self::FLAKY_ID);
        self::assertNotNull($before);
        self::assertSame(0, $before['status']);

        // A full-suite `verify` always re-executes for real (never a replay), so the
        // same content key now produces a different result class.
        $flipped = $this->fixture->replay(['verify'], ['FIXTURE_FLIP' => '1']);
        self::assertSame(1, $flipped['exitCode'], $flipped['stdout'] . $flipped['stderr']);

        $stateDir = ReplayAssert::stateDir($this->fixture);
        $flakyJson = $stateDir . '/flaky.json';
        self::assertFileExists($flakyJson);

        $entries = self::readFlakyJson($flakyJson);
        self::assertArrayHasKey(self::FLAKY_ID, $entries);
        self::assertSame(1, $entries[self::FLAKY_ID]['flips']);
        self::assertSame('divergence', $entries[self::FLAKY_ID]['reason']);

        // Heal it back to passing. Recovering from a cached "fail" class is excluded
        // from divergence detection (SPEC §15 scenario 5's rerun rule already explains
        // that transition unconditionally), so `flips` stays at 1 — but the entry, and
        // therefore the quarantine, survives regardless: only `release()`/`prune
        // --flaky` clears it. This is also what makes the next unchanged run's forced
        // execution exclusively about the quarantine, not about scenario 5's rerun rule.
        $healed = $this->fixture->replay(['verify']);
        self::assertSame(0, $healed['exitCode'], $healed['stdout'] . $healed['stderr']);
        self::assertSame(1, self::readFlakyJson($flakyJson)[self::FLAKY_ID]['flips']);

        $graphHealed = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphHealed);
        $healedResult = $graphHealed->result('main', self::FLAKY_ID);
        self::assertNotNull($healedResult);
        self::assertSame(0, $healedResult['status']);

        // Unchanged, status healthy, NOT rerun-worthy — but still quarantined: it still
        // executes for real, while the rest of the suite replays.
        $again = $this->fixture->replay([]);
        self::assertSame(0, $again['exitCode'], $again['stdout'] . $again['stderr']);
        self::assertSame(1, ReplayAssert::executedCount($again['stdout']));
        self::assertGreaterThanOrEqual(1, ReplayAssert::quarantinedCount($again['stdout']));

        self::assertTrue(Quarantine::load($stateDir)->isQuarantined(self::FLAKY_ID));

        // `prune --flaky` releases it from the QUARANTINE table specifically. It is
        // verified here through `Quarantine::load()` directly, not through the summary's
        // "executed"/"quarantined" counts the way this test used to: this fixture's
        // `#[NotCacheable]` (added for `QuarantineFlipDetectionThroughAPlainRunTest`'s
        // sake, see FlakyTest.flaky.php) is a separate, permanent property that keeps
        // forcing FlakyTest to execute regardless of whether it is quarantined — so
        // those counts can no longer distinguish "still quarantined" from "not cacheable
        // anyway," only the quarantine table itself can.
        $pruned = $this->fixture->replay(['prune', '--flaky']);
        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);
        self::assertStringContainsString('quarantine cleared', $pruned['stdout']);

        self::assertFalse(Quarantine::load($stateDir)->isQuarantined(self::FLAKY_ID));

        // FlakyTest itself keeps executing every pass regardless — `#[NotCacheable]`,
        // not the (now-cleared) quarantine.
        $final = $this->fixture->replay([]);
        self::assertSame(0, $final['exitCode'], $final['stdout'] . $final['stderr']);
        self::assertSame(1, ReplayAssert::executedCount($final['stdout']));
    }

    /** @return array<string, array{firstSeen:int, flips:int, stable:int, lastKey:string, reason:string}> */
    private static function readFlakyJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
