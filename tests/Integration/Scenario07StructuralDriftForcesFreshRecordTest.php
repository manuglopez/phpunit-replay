<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 7 / §16 acceptance criterion 5: a tracked structural file
 * (`composer.lock`) changing forces a fresh full record, with a clear warning
 * (docs/INTERNALS.md step 6: "structural change (<keys>): recording a fresh baseline").
 */
final class Scenario07StructuralDriftForcesFreshRecordTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_a_tracked_composer_lock_change_forces_a_fresh_record(): void
    {
        $originalLock = $this->fixture->read('composer.lock');
        $this->fixture->write('composer.lock', $originalLock . "\n// bump\n");

        $result = $this->fixture->replay([]);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('structural change (composer_lock)', $result['stderr']);

        $lastLine = ReplayAssert::lastLine($result['stdout']);
        self::assertStringStartsWith('Replay  ● recorded 35 tests in 7 test files', $lastLine);
        self::assertSame(35, self::firstNumber($lastLine));

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        self::assertCount(35, $graph->results('main'));
    }

    private static function firstNumber(string $line): int
    {
        preg_match('/recorded (\d+) tests/', $line, $m);

        return (int) ($m[1] ?? -1);
    }
}
