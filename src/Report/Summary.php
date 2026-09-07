<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

/**
 * The wrapper's own summary line, printed below PHPUnit's own output (SPEC §11):
 *
 *   Replay  ✓ 38 executed (31 affected, 7 uncached) · 1202 replayed (14 from remote) · 2 quarantined · baseline main@a1b2c3d · saved 4m12s
 *
 * Every counter here counts individual TESTS, never test files: `executed` is the number
 * of tests PHPUnit actually ran this pass, `affected`/`uncached`/`quarantined` classify
 * each of those by its file's primary reason in the run list (so `executed === affected +
 * uncached + quarantined` always holds — docs/INTERNALS.md "Summary counters"), and
 * `replayed` is the number of cached test results served without running them. This
 * matches `PHPUnit\ReplayState::counters()` (in-process mode, SPEC.md §6), which already
 * counted tests this way; `Console\Runner\RunPipeline::classifyExecuted()` is what makes
 * the wrapper agree with it. `--dry-run`, which has nothing executed yet to classify,
 * prints {@see DryRunSummary} instead — a deliberately different shape, over test files.
 *
 * `notCacheable` is a distinct counter for tests made non-cacheable by a `#[NotCacheable]`
 * attribute or a `never_cache` glob (as opposed to automatic quarantine after a flip):
 * today's callers still fold both into `quarantined` (see the TODO on
 * `Select\RunList::primaryReasonFor()`), so it defaults to 0 and is only printed — `· N not
 * cacheable` — once something actually passes a positive count.
 */
final readonly class Summary
{
    public function __construct(
        public int $executed,
        public int $affected,
        public int $uncached,
        public int $replayed,
        public int $replayedRemote,
        public int $quarantined,
        public ?string $baselineBranch,
        public ?string $baselineSha,
        public float $savedSeconds,
        public bool $success,
        public int $notCacheable = 0,
    ) {
    }

    public static function recorded(
        int $tests,
        int $testFiles,
        int $sourceFiles,
        int $edges,
        int $graphBytes,
        float $seconds,
        ?string $branch,
        ?string $sha,
    ): RecordSummary {
        return new RecordSummary($tests, $testFiles, $sourceFiles, $edges, $graphBytes, $seconds, $branch, $sha);
    }

    public function format(bool $colors = false): string
    {
        $symbol = $this->success ? '✓' : '✗';

        if ($colors) {
            $symbol = $this->success
                ? "\e[32m{$symbol}\e[0m"
                : "\e[31m{$symbol}\e[0m";
        }

        $segments = [
            sprintf('%d executed (%d affected, %d uncached)', $this->executed, $this->affected, $this->uncached),
            $this->replayedSegment(),
            sprintf('%d quarantined', $this->quarantined),
        ];

        if ($this->notCacheable > 0) {
            $segments[] = sprintf('%d not cacheable', $this->notCacheable);
        }

        $baseline = $this->baselineSegment($this->baselineBranch, $this->baselineSha);

        if ($baseline !== null) {
            $segments[] = $baseline;
        }

        if ($this->savedSeconds >= 1.0) {
            $segments[] = 'saved ' . Format::duration($this->savedSeconds);
        }

        return self::label($colors) . '  ' . $symbol . ' ' . implode(' · ', $segments);
    }

    private function replayedSegment(): string
    {
        $segment = sprintf('%d replayed', $this->replayed);

        if ($this->replayedRemote > 0) {
            $segment .= sprintf(' (%d from remote)', $this->replayedRemote);
        }

        return $segment;
    }

    /** @internal shared with RecordSummary */
    public static function baselineSegment(?string $branch, ?string $sha): ?string
    {
        if ($branch === null) {
            return null;
        }

        $segment = 'baseline ' . $branch;

        if ($sha !== null) {
            $segment .= '@' . substr($sha, 0, 7);
        }

        return $segment;
    }

    /** @internal shared with RecordSummary */
    public static function label(bool $colors): string
    {
        return $colors ? "\e[1mReplay\e[0m" : 'Replay';
    }
}
