<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §8 rule 1: `#[NotCacheable]` on a class marks the whole test file unsafe to
 * replay; on a method, just that `Class::method` id. Either way the marked test always
 * executes for real, even when nothing changed, and `status` reports it.
 */
final class NotCacheableScenarioTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
        $this->fixture->applyVariant('NotCacheableTest.fixture.php', 'tests/NotCacheableTest.php');
        $this->fixture->applyVariant('PartiallyCacheableTest.fixture.php', 'tests/PartiallyCacheableTest.php');
        $this->fixture->repo->commitAll('add not-cacheable fixtures');
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_not_cacheable_tests_always_execute_and_are_reported_by_status(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        self::assertTrue($graph->isNotCacheable('tests/NotCacheableTest.php'));
        self::assertTrue($graph->isNotCacheable('App\Tests\PartiallyCacheableTest::testUsesTheClock'));
        self::assertFalse($graph->isNotCacheable('App\Tests\PartiallyCacheableTest::testIsOrdinaryAndCacheable'));

        // Nothing changed: NotCacheableTest.php (class-level, 2 tests) and
        // PartiallyCacheableTest.php (method-level on one of its 2 tests — the whole
        // file belongs in the run list) still execute for real; everything else replays.
        // quarantined counts tests, not files (docs/INTERNALS.md "Summary counters"): all
        // 4 executed tests belong to the two non-cacheable files.
        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::affectedCount($result['stdout']));
        self::assertSame(0, ReplayAssert::uncachedCount($result['stdout']));
        self::assertSame(4, ReplayAssert::quarantinedCount($result['stdout']));
        self::assertSame(4, ReplayAssert::executedCount($result['stdout']));

        $status = $this->fixture->replay(['status']);
        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertMatchesRegularExpression(
            '/not cacheable: [1-9]\d* \(\d+ files, \d+ ids\)/',
            $status['stdout'],
        );
    }
}
