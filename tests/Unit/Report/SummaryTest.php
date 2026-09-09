<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Report;

use Manuglopez\Replay\Report\Summary;
use PHPUnit\Framework\TestCase;

final class SummaryTest extends TestCase
{
    public function testFormatMatchesTheSpecExample(): void
    {
        $summary = $this->summary();

        self::assertSame(
            'Replay  ✓ 38 executed (31 affected, 7 uncached) · 1202 replayed (14 from remote) · 2 quarantined · baseline main@a1b2c3d · saved 4m12s',
            $summary->format(),
        );
    }

    public function testFormatShowsACrossWhenNotSuccessful(): void
    {
        $summary = $this->summary(success: false);

        self::assertStringStartsWith('Replay  ✗ ', $summary->format());
    }

    public function testFormatOmitsFromRemoteWhenReplayedRemoteIsZero(): void
    {
        $summary = $this->summary(replayedRemote: 0);

        self::assertStringContainsString('1202 replayed ·', $summary->format());
        self::assertStringNotContainsString('from remote', $summary->format());
    }

    public function testFormatOmitsBaselineWhenBranchIsNull(): void
    {
        $summary = $this->summary(baselineBranch: null, baselineSha: null);

        self::assertStringNotContainsString('baseline', $summary->format());
    }

    public function testFormatOmitsShaButKeepsBranchWhenShaIsNull(): void
    {
        $summary = $this->summary(baselineSha: null);

        self::assertStringContainsString('· baseline main ·', $summary->format());
        self::assertStringNotContainsString('main@', $summary->format());
    }

    public function testFormatOmitsSavedWhenBelowOneSecond(): void
    {
        $summary = $this->summary(savedSeconds: 0.4);

        self::assertStringNotContainsString('saved', $summary->format());
    }

    public function testFormatOmitsSavedWhenZero(): void
    {
        $summary = $this->summary(savedSeconds: 0.0);

        self::assertStringNotContainsString('saved', $summary->format());
    }

    public function testFormatPrintsZeroExecutedCounts(): void
    {
        $summary = $this->summary(executed: 0, affected: 0, uncached: 0);

        self::assertStringContainsString('0 executed (0 affected, 0 uncached)', $summary->format());
    }

    public function testFormatOmitsNotCacheableWhenZero(): void
    {
        self::assertStringNotContainsString('not cacheable', $this->summary()->format());
        self::assertStringNotContainsString('not cacheable', $this->summary(notCacheable: 0)->format());
    }

    /** A caller built before `notCacheable` existed (e.g. a positional constructor call omitting it entirely) still compiles and formats the same way: BC via the trailing default. */
    public function testConstructorOmittingNotCacheableDefaultsToZeroAndIsBackwardCompatible(): void
    {
        $summary = new Summary(38, 31, 7, 1202, 14, 2, 'main', 'a1b2c3d4e5f6', 252.0, true);

        self::assertSame(0, $summary->notCacheable);
        self::assertStringNotContainsString('not cacheable', $summary->format());
    }

    public function testFormatShowsNotCacheableOnlyWhenPositive(): void
    {
        $summary = $this->summary(notCacheable: 3);

        self::assertStringContainsString('2 quarantined · 3 not cacheable · baseline', $summary->format());
    }

    public function testFormatUsesSubMinuteSecondsBelowOneMinute(): void
    {
        $summary = $this->summary(savedSeconds: 12.0);

        self::assertStringContainsString('saved 12s', $summary->format());
    }

    public function testFormatUsesMinutesAndSecondsBelowOneHour(): void
    {
        $summary = $this->summary(savedSeconds: 252.0);

        self::assertStringContainsString('saved 4m12s', $summary->format());
    }

    public function testFormatUsesHoursAndMinutesFromOneHourUp(): void
    {
        $summary = $this->summary(savedSeconds: 3840.0);

        self::assertStringContainsString('saved 1h04m', $summary->format());
    }

    public function testFormatWithColorsWrapsOnlyTheCheckAndTheWordReplay(): void
    {
        $summary = $this->summary();

        self::assertSame(
            "\e[1mReplay\e[0m  \e[32m✓\e[0m 38 executed (31 affected, 7 uncached)"
            . ' · 1202 replayed (14 from remote) · 2 quarantined · baseline main@a1b2c3d · saved 4m12s',
            $summary->format(colors: true),
        );
    }

    public function testFormatWithColorsWrapsTheCrossInRedWhenNotSuccessful(): void
    {
        $summary = $this->summary(success: false);

        self::assertStringStartsWith("\e[1mReplay\e[0m  \e[31m✗\e[0m ", $summary->format(colors: true));
    }

    public function testRecordedFormatMatchesTheSpecExample(): void
    {
        $recordSummary = Summary::recorded(
            tests: 1240,
            testFiles: 42,
            sourceFiles: 318,
            edges: 3120,
            excludedEdges: 0,
            graphBytes: 215040,
            seconds: 252.0,
            branch: 'main',
            sha: 'a1b2c3d4e5f6',
        );

        self::assertSame(
            'Replay  ● recorded 1240 tests in 42 test files · 318 source files · 3120 edges'
            . ' · graph.json 210 KB · baseline main@a1b2c3d · 4m12s',
            $recordSummary->format(),
        );
    }

    public function testRecordedFormatShowsMegabytesWithOneDecimalAboveOneMebibyte(): void
    {
        $recordSummary = Summary::recorded(
            tests: 1,
            testFiles: 1,
            sourceFiles: 1,
            edges: 1,
            excludedEdges: 0,
            graphBytes: 2202009,
            seconds: 1.0,
            branch: null,
            sha: null,
        );

        self::assertStringContainsString('graph.json 2.1 MB', $recordSummary->format());
        self::assertStringNotContainsString('baseline', $recordSummary->format());
    }

    /**
     * Visibility for the no-edges-to-ignored-files fix: a run that dropped some
     * git-ignored dependency (a compiled Laravel Blade view, typically) says so on the
     * summary line, right after the edges count.
     */
    public function testRecordedFormatShowsExcludedEdgesOnlyWhenPositive(): void
    {
        $withExclusions = Summary::recorded(
            tests: 10,
            testFiles: 2,
            sourceFiles: 5,
            edges: 20,
            excludedEdges: 3,
            graphBytes: 1024,
            seconds: 1.0,
            branch: null,
            sha: null,
        );

        self::assertStringContainsString('20 edges · 3 excluded (gitignored) · graph.json', $withExclusions->format());

        $withoutExclusions = Summary::recorded(
            tests: 10,
            testFiles: 2,
            sourceFiles: 5,
            edges: 20,
            excludedEdges: 0,
            graphBytes: 1024,
            seconds: 1.0,
            branch: null,
            sha: null,
        );

        self::assertStringNotContainsString('excluded', $withoutExclusions->format());
    }

    private function summary(
        int $executed = 38,
        int $affected = 31,
        int $uncached = 7,
        int $replayed = 1202,
        int $replayedRemote = 14,
        int $quarantined = 2,
        ?string $baselineBranch = 'main',
        ?string $baselineSha = 'a1b2c3d4e5f6',
        float $savedSeconds = 252.0,
        bool $success = true,
        int $notCacheable = 0,
    ): Summary {
        return new Summary(
            executed: $executed,
            affected: $affected,
            uncached: $uncached,
            replayed: $replayed,
            replayedRemote: $replayedRemote,
            quarantined: $quarantined,
            baselineBranch: $baselineBranch,
            baselineSha: $baselineSha,
            savedSeconds: $savedSeconds,
            success: $success,
            notCacheable: $notCacheable,
        );
    }
}
