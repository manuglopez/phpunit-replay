<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 1: with no cached graph at all, the wrapper runs the full suite
 * and records a fresh baseline.
 */
final class Scenario01FirstRunRecordsTest extends TestCase
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

    public function test_first_run_records_a_fresh_baseline(): void
    {
        $result = $this->fixture->replay(['record']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);

        $lastLine = ReplayAssert::lastLine($result['stdout']);
        self::assertStringStartsWith('Replay  ● recorded 35 tests in 7 test files', $lastLine);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph, 'graph.json should exist and decode');

        self::assertTrue($graph->knowsTest('tests/CartTest.php'));
        self::assertContains('src/Money.php', $graph->dependenciesOf('tests/CartTest.php'));

        self::assertTrue($graph->isBaselineComplete('main'));
        self::assertSame($this->fixture->repo->sha(), $graph->recordedSha('main'));
        self::assertCount(35, $graph->results('main'));
    }
}
