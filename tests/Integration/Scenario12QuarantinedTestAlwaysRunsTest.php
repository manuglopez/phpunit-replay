<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 12 / §8.3: a test whose outcome flips (same content key, a
 * different result status class) is quarantined automatically and always executes for
 * real afterward — until `prune --flaky` releases it.
 *
 * `FlakyTest` depends only on the `FIXTURE_FLIP` environment variable, so its content
 * key never changes between passes; a `--filter` selection is what forces it to
 * *execute* against the existing graph rather than being skipped by the normal replay
 * decision (docs/INTERNALS.md step 7: "results-only" mode still merges into the graph
 * the wrapper already loaded).
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

        // Nothing on disk changes: `--filter` still forces a real execution (never a
        // replay), so the same content key now produces a different result class.
        $flipped = $this->fixture->replay(['--', '--filter', 'FlakyTest'], ['FIXTURE_FLIP' => '1']);
        self::assertSame(1, $flipped['exitCode'], $flipped['stdout'] . $flipped['stderr']);

        $stateDir = ReplayAssert::stateDir($this->fixture);
        $flakyJson = $stateDir . '/flaky.json';
        self::assertFileExists($flakyJson);

        $entries = self::readFlakyJson($flakyJson);
        self::assertArrayHasKey(self::FLAKY_ID, $entries);
        self::assertSame(1, $entries[self::FLAKY_ID]['flips']);
        self::assertSame('flip', $entries[self::FLAKY_ID]['reason']);

        // Heal it back to passing. Recovering from a cached "fail" class is excluded
        // from flip detection (SPEC §15 scenario 5's rerun rule already explains that
        // transition unconditionally), so `flips` stays at 1 — but the entry, and
        // therefore the quarantine, survives regardless: only `release()`/`prune
        // --flaky` clears it. This is also what makes the next unchanged run's forced
        // execution exclusively about the quarantine, not about scenario 5's rerun rule.
        $healed = $this->fixture->replay(['--', '--filter', 'FlakyTest']);
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

        // `prune --flaky` releases it: the next unchanged run replays everything.
        $pruned = $this->fixture->replay(['prune', '--flaky']);
        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);
        self::assertStringContainsString('quarantine cleared', $pruned['stdout']);

        $final = $this->fixture->replay([]);
        self::assertSame(0, $final['exitCode'], $final['stdout'] . $final['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($final['stdout']));
        self::assertSame(0, ReplayAssert::quarantinedCount($final['stdout']));
    }

    /** @return array<string, array{firstSeen:int, flips:int, stable:int, lastKey:string, reason:string}> */
    private static function readFlakyJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
