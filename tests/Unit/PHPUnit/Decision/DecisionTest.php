<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit\Decision;

use Manuglopez\Replay\PHPUnit\Decision\Decision;
use Manuglopez\Replay\PHPUnit\Decision\ReplayIncomplete;
use Manuglopez\Replay\PHPUnit\Decision\ReplayPass;
use Manuglopez\Replay\PHPUnit\Decision\ReplaySkipped;
use Manuglopez\Replay\PHPUnit\Decision\Run;
use PHPUnit\Framework\TestCase;

final class DecisionTest extends TestCase
{
    /** @return array{status: int, message: string, time: float, assertions: int, file: string} */
    private function cached(int $status = 0): array
    {
        return ['status' => $status, 'message' => 'msg', 'time' => 1.5, 'assertions' => 3, 'file' => 'tests/FooTest.php'];
    }

    public function test_run_is_not_a_replay_and_keeps_its_reason(): void
    {
        $decision = new Run('affected');

        self::assertInstanceOf(Decision::class, $decision);
        self::assertFalse($decision->isReplay());
        self::assertSame('affected', $decision->reason);
    }

    public function test_replay_pass_carries_assertions_riskiness_and_the_cached_result(): void
    {
        $decision = new ReplayPass(3, true, $this->cached(5));

        self::assertTrue($decision->isReplay());
        self::assertSame(3, $decision->assertions);
        self::assertTrue($decision->wasRisky);
        self::assertSame(5, $decision->cached['status']);
    }

    public function test_replay_skipped_carries_the_message(): void
    {
        $decision = new ReplaySkipped('fixture skip', $this->cached(1));

        self::assertTrue($decision->isReplay());
        self::assertSame('fixture skip', $decision->message);
        self::assertSame(1, $decision->cached['status']);
    }

    public function test_replay_incomplete_carries_the_message(): void
    {
        $decision = new ReplayIncomplete('todo', $this->cached(2));

        self::assertTrue($decision->isReplay());
        self::assertSame('todo', $decision->message);
        self::assertSame(2, $decision->cached['status']);
    }
}
