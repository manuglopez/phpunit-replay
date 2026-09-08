<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

/**
 * The wrapper's summary line for a full recording pass (`phpunit-replay record`), printed
 * below PHPUnit's own output:
 *
 *   Replay  ● recorded 1240 tests in 42 test files · 318 source files · 3120 edges · graph.json 210 KB · baseline main@a1b2c3d · 4m12s
 *
 * `N excluded (gitignored)` is inserted after `edges` — only when `$excludedEdges > 0` — for
 * a dependency the coverage driver or an explicit `Recorder::linkSource()` reported but
 * `git check-ignore` matched: a compiled Laravel Blade view under `bootstrap/cache/`, for
 * instance. Such a file is dropped rather than recorded, because it can never appear in
 * `Change\ChangedFiles::since()`, so an edge to it could never trigger a rerun — only pollute
 * the content key with something that moves between paratest workers.
 *
 * Built via {@see Summary::recorded()}.
 */
final readonly class RecordSummary
{
    public function __construct(
        public int $tests,
        public int $testFiles,
        public int $sourceFiles,
        public int $edges,
        public int $excludedEdges,
        public int $graphBytes,
        public float $seconds,
        public ?string $branch,
        public ?string $sha,
    ) {
    }

    public function format(bool $colors = false): string
    {
        $segments = [
            sprintf('recorded %d tests in %d test files', $this->tests, $this->testFiles),
            sprintf('%d source files', $this->sourceFiles),
            sprintf('%d edges', $this->edges),
        ];

        // Only shown once something is actually dropped (Report\Summary's own
        // $notCacheable precedent): the overwhelming majority of runs exclude nothing, and
        // a permanent "0 excluded" segment would just be noise on every one of them.
        if ($this->excludedEdges > 0) {
            $segments[] = sprintf('%d excluded (gitignored)', $this->excludedEdges);
        }

        $segments[] = sprintf('graph.json %s', Format::bytes($this->graphBytes));

        $baseline = Summary::baselineSegment($this->branch, $this->sha);

        if ($baseline !== null) {
            $segments[] = $baseline;
        }

        $segments[] = Format::duration($this->seconds);

        return Summary::label($colors) . '  ● ' . implode(' · ', $segments);
    }
}
