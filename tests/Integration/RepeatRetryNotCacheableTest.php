<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit 13.3+ hazard (docs/INTERNALS.md "hazard, real and handled now that PHPUnit
 * 13.1+ is supported"): `--repeat`/`--retry` make `PHPUnit\Event\Code\TestMethod::id()` —
 * the exact string this package keys recorded results on and looks up replay decisions
 * by — non-stable across runs that differ in those flags. A run made under either flag
 * must therefore neither replay from the cache nor record into it:
 * `RunPipeline::resolveEnvironment()` degrades to a plain PHPUnit invocation instead, the
 * same mechanism a missing coverage driver or an untracked git repository degrades
 * through.
 */
final class RepeatRetryNotCacheableTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        if (! method_exists(Configuration::class, 'repeat')) {
            self::markTestSkipped('requires PHPUnit >= 13.3 (--repeat/--retry do not exist before it)');
        }

        $this->fixture = FixtureProject::plain();
    }

    protected function tearDown(): void
    {
        // setUp() marks the test skipped (and returns) before assigning $this->fixture on
        // PHPUnit < 13.3: the typed property is then never initialised, and accessing it
        // here would turn that skip into an error.
        if (isset($this->fixture)) {
            $this->fixture->destroy();
        }

        parent::tearDown();
    }

    public function test_a_repeat_run_neither_replays_nor_pollutes_the_cache(): void
    {
        // A normal baseline first: something for a --repeat run to (wrongly) replay from,
        // or (wrongly) corrupt, if it did not degrade.
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $before = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($before);
        $shaBefore = $before->recordedSha('main');
        $statsBefore = $before->stats();

        $result = $this->fixture->replay(['--', '--filter', 'testAdditionAndSubtractionKeepCurrency', '--repeat=3']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('phpunit-replay: --repeat/--retry requested', $result['stderr']);

        // A real, undisturbed PHPUnit run (three repetitions of the one filtered test),
        // never this package's own "N executed (...) · M replayed" summary line.
        self::assertStringContainsString('OK (3 tests, 12 assertions)', $result['stdout']);
        self::assertStringNotContainsString('replayed', $result['stdout']);

        // The pre-existing baseline is completely untouched: not read from, not written to.
        $after = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($after);
        self::assertSame($shaBefore, $after->recordedSha('main'));
        self::assertSame($statsBefore, $after->stats());

        // The danger scenario itself (SPEC.md §8): recording again under the very same
        // --repeat=3 still writes nothing repetition-suffixed into the graph, so a third,
        // identical --repeat=3 pass has nothing to falsely replay.
        $recordAgain = $this->fixture->replay(['record', '--', '--repeat=3']);
        self::assertSame(0, $recordAgain['exitCode'], $recordAgain['stdout'] . $recordAgain['stderr']);
        self::assertStringContainsString('phpunit-replay: --repeat/--retry requested', $recordAgain['stderr']);

        $afterRecordAgain = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($afterRecordAgain);
        self::assertSame($shaBefore, $afterRecordAgain->recordedSha('main'));
        self::assertSame($statsBefore, $afterRecordAgain->stats());
    }

    public function test_a_retry_run_also_degrades_instead_of_touching_the_cache(): void
    {
        $result = $this->fixture->replay(['--', '--filter', 'testAdditionAndSubtractionKeepCurrency', '--retry=2']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('phpunit-replay: --repeat/--retry requested', $result['stderr']);
        self::assertNull(ReplayAssert::loadGraph($this->fixture));
    }

    /**
     * The other hazard this docs/INTERNALS.md paragraph now covers: unlike `--repeat`/
     * `--retry`, `#[Repeat]`/`#[Retry]` are read by `PHPUnit\Framework\TestBuilder`
     * regardless of any CLI flag, so a decorated method repeats on every run — including a
     * plain, flag-free one that would otherwise replay it wholesale from cache. This is a
     * per-test guard (`RecordRepeatOrRetryNotCacheableOnPreparationStarted`), not the
     * whole-run degrade the two tests above exercise: everything else in the suite still
     * replays normally.
     */
    public function test_a_repeat_attribute_always_executes_and_leaves_nothing_replayable(): void
    {
        $this->fixture->applyVariant('RepeatAttributeTest.fixture.php', 'tests/RepeatAttributeTest.php');
        $this->fixture->repo->commitAll('add #[Repeat] fixture');

        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        foreach ([1, 2, 3] as $repetition) {
            self::assertTrue($graph->isNotCacheable(sprintf(
                'App\Tests\RepeatAttributeTest::testRepeatsEveryTime (repetition %d of 3)',
                $repetition,
            )));
        }

        // Nothing changed: every other fixture test file replays; the three repetitions
        // #[Repeat(3)] forces are the only tests that actually execute again.
        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::affectedCount($result['stdout']));
        self::assertSame(0, ReplayAssert::uncachedCount($result['stdout']));
        self::assertSame(3, ReplayAssert::quarantinedCount($result['stdout']));
        self::assertSame(3, ReplayAssert::executedCount($result['stdout']));
        self::assertStringNotContainsString('3 replayed', $result['stdout']);

        // Recording again leaves exactly the same three ids not-cacheable: nothing
        // repetition-suffixed ever becomes safe to replay, on any later run either.
        $recordedAgain = $this->fixture->replay(['record']);
        self::assertSame(0, $recordedAgain['exitCode'], $recordedAgain['stdout'] . $recordedAgain['stderr']);

        $graphAgain = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphAgain);

        foreach ([1, 2, 3] as $repetition) {
            self::assertTrue($graphAgain->isNotCacheable(sprintf(
                'App\Tests\RepeatAttributeTest::testRepeatsEveryTime (repetition %d of 3)',
                $repetition,
            )));
        }
    }

    /**
     * `#[Retry]` when the test passes on its first attempt: `id()` is never suffixed
     * (`PHPUnit\Event\Code\TestMethod::id()` only appends `' (attempt N of M)'` once
     * `attempt > 1`), so the bare `Class::method` id is exactly what must stay excluded
     * from replay — the case a method-level `#[NotCacheable]`-style bare id would already
     * get right, but which this fixture proves end to end for `#[Retry]` specifically.
     */
    public function test_a_retry_attribute_that_never_needs_a_retry_still_never_replays(): void
    {
        $this->fixture->applyVariant('RetryAttributeTest.fixture.php', 'tests/RetryAttributeTest.php');
        $this->fixture->repo->commitAll('add #[Retry] fixture');

        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        self::assertTrue($graph->isNotCacheable('App\Tests\RetryAttributeTest::testRetriesEveryTime'));

        // Nothing changed: everything else replays, but the #[Retry]-decorated test still
        // executes for real instead of being served from its own first-attempt cache.
        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::affectedCount($result['stdout']));
        self::assertSame(0, ReplayAssert::uncachedCount($result['stdout']));
        self::assertSame(1, ReplayAssert::quarantinedCount($result['stdout']));
        self::assertSame(1, ReplayAssert::executedCount($result['stdout']));
    }
}
