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
            unverified: 0,
            lifetimeDivergences: 2,
            lifetimeRuns: 143,
            success: true,
        );

        self::assertSame(
            'Verify  ✓ 1240 tests · 1198 would replay · 0 divergences · 0 unverified (lifetime: 2 in 143 runs)',
            $summary->format(),
        );
    }

    public function test_a_divergence_prints_a_cross(): void
    {
        $summary = new VerifySummary(
            tests: 40,
            wouldReplay: 38,
            divergences: 1,
            unverified: 0,
            lifetimeDivergences: 3,
            lifetimeRuns: 10,
            success: false,
        );

        self::assertSame(
            'Verify  ✗ 40 tests · 38 would replay · 1 divergences · 0 unverified (lifetime: 3 in 10 runs)',
            $summary->format(),
        );
    }

    /**
     * A pass that would have replayed the whole suite but could only vouch for part of it
     * still prints `✓`: `unverified` reports what this pass could not check, which is a
     * statement about its own evidence rather than a failure (SPEC.md §12.2). The figures
     * are the ones a real 9056-test suite produced on a first pass over a fresh graph.
     */
    public function test_unverified_is_reported_without_failing_the_pass(): void
    {
        $summary = new VerifySummary(
            tests: 9056,
            wouldReplay: 9056,
            divergences: 0,
            unverified: 1032,
            lifetimeDivergences: 0,
            lifetimeRuns: 7,
            success: true,
        );

        self::assertSame(
            'Verify  ✓ 9056 tests · 9056 would replay · 0 divergences · 1032 unverified (lifetime: 0 in 7 runs)',
            $summary->format(),
        );
    }

    public function test_colors_wrap_the_label_and_symbol(): void
    {
        $summary = new VerifySummary(10, 10, 0, 0, 0, 1, true);

        $formatted = $summary->format(colors: true);

        self::assertStringContainsString("\e[1mVerify\e[0m", $formatted);
        self::assertStringContainsString("\e[32m✓\e[0m", $formatted);
    }
}
