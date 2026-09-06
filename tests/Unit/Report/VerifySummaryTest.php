<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Report;

use Manuglopez\Replay\Report\VerifySummary;
use PHPUnit\Framework\TestCase;

final class VerifySummaryTest extends TestCase
{
    public function test_a_clean_verify_prints_a_check_mark(): void
    {
        $summary = new VerifySummary(
            tests: 1240,
            wouldReplay: 1198,
            divergences: 0,
            lifetimeDivergences: 2,
            lifetimeRuns: 143,
            success: true,
        );

        self::assertSame(
            'Verify  ✓ 1240 tests · 1198 would replay · 0 divergences (lifetime: 2 in 143 runs)',
            $summary->format(),
        );
    }

    public function test_a_divergence_prints_a_cross(): void
    {
        $summary = new VerifySummary(
            tests: 40,
            wouldReplay: 38,
            divergences: 1,
            lifetimeDivergences: 3,
            lifetimeRuns: 10,
            success: false,
        );

        self::assertSame(
            'Verify  ✗ 40 tests · 38 would replay · 1 divergences (lifetime: 3 in 10 runs)',
            $summary->format(),
        );
    }

    public function test_colors_wrap_the_label_and_symbol(): void
    {
        $summary = new VerifySummary(10, 10, 0, 0, 1, true);

        $formatted = $summary->format(colors: true);

        self::assertStringContainsString("\e[1mVerify\e[0m", $formatted);
        self::assertStringContainsString("\e[32m✓\e[0m", $formatted);
    }
}
