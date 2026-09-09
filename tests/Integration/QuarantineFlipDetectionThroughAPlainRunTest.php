<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof that SPEC.md §8.3's automatic-quarantine flip detection — the real
 * `GraphUpdater::detectFlip()` path, `reason: 'flip'` — is still reachable through a
 * genuinely ordinary command, with no CLI selection involved at all. This is distinct
 * from `verify`'s own, separate divergence detection ({@see
 * Scenario12QuarantinedTestAlwaysRunsTest}, `reason: 'divergence'`, `GraphUpdater`
 * constructed with `quarantine: null` there): that mechanism never exercises
 * `GraphUpdater`'s own quarantine-carrying `apply()` call, so it does not, on its own,
 * prove the thing this test proves.
 *
 * Why this cannot use `--filter` to force the re-execution the way {@see
 * Scenario12QuarantinedTestAlwaysRunsTest} used to: a CLI selection now persists
 * NOTHING at all — no edges, no results, no quarantine write (this package's rule 2) —
 * so `run --filter FlakyTest` no longer reaches `GraphUpdater::apply()` at all, let
 * alone its flip-detecting branch. `FlakyTest` is `#[NotCacheable]` instead
 * (tests/Fixtures/Projects/plain-variants/FlakyTest.flaky.php), which forces the same
 * re-execution through an entirely ordinary, unfiltered `run` — the one path this
 * package's fix never touches, because {@see
 * \Manuglopez\Replay\Console\Runner\RunPipeline::executeReplay()} (unlike
 * `runResultsOnly()`) is only ever reached when
 * `ConfigurationReader::hasPartialSelection()` is false, and it still constructs its
 * `GraphUpdater` with a real, non-null quarantine.
 *
 * If this test (or its fixture's `#[NotCacheable]`) is ever "simplified" back to a
 * `--filter`-based one, it will pass just as emptily as the rewritten
 * `Scenario12QuarantinedTestAlwaysRunsTest` would have: a CLI selection cannot write a
 * quarantine entry any more, of either reason.
 */
final class QuarantineFlipDetectionThroughAPlainRunTest extends TestCase
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

    public function test_a_plain_run_with_no_cli_arguments_detects_and_persists_a_genuine_flip(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $baseline = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($baseline);
        $before = $baseline->result('main', self::FLAKY_ID);
        self::assertNotNull($before);
        self::assertSame(0, $before['status']);

        $stateDir = ReplayAssert::stateDir($this->fixture);
        self::assertFalse(Quarantine::load($stateDir)->isQuarantined(self::FLAKY_ID));

        // The whole point: no `--`, no phpunit arguments, nothing forwarded at all.
        // `#[NotCacheable]` alone is what forces FlakyTest to execute for real here,
        // through the exact `RunPipeline::executeReplay()` path an entirely ordinary
        // `phpunit-replay run` takes — never `runResultsOnly()`.
        $flipped = $this->fixture->replay([], ['FIXTURE_FLIP' => '1']);
        self::assertSame(1, $flipped['exitCode'], $flipped['stdout'] . $flipped['stderr']);

        // The genuine article: written by GraphUpdater::detectFlip()'s own
        // `$quarantine->recordFlip($testId, $key)` call — default reason 'flip' — never
        // `verify`'s separate divergence detection (`reason: 'divergence'`), which does
        // not run at all in this test.
        $flakyJson = $stateDir . '/flaky.json';
        self::assertFileExists($flakyJson);

        $decoded = json_decode((string) file_get_contents($flakyJson), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey(self::FLAKY_ID, $decoded);
        self::assertSame(1, $decoded[self::FLAKY_ID]['flips']);
        self::assertSame('flip', $decoded[self::FLAKY_ID]['reason']);

        // A FRESH `Quarantine::load()` — the real round-trip through disk the next real
        // process would perform, not the same in-memory object this pass just wrote —
        // agrees: the test is thereafter forced to run, for real, regardless of a
        // future unfiltered pass's own replay decision.
        self::assertTrue(Quarantine::load($stateDir)->isQuarantined(self::FLAKY_ID));

        $graphFlipped = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphFlipped);
        $flippedResult = $graphFlipped->result('main', self::FLAKY_ID);
        self::assertNotNull($flippedResult);
        self::assertSame(7, $flippedResult['status']);
    }
}
