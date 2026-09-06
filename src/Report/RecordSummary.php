<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

/**
 * The wrapper's summary line for a full recording pass (`phpunit-replay record`), printed
 * below PHPUnit's own output:
 *
 *   Replay  ● recorded 1240 tests in 42 test files · 318 source files · 3120 edges · graph.json 210 KB · baseline main@a1b2c3d · 4m12s
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
            sprintf('graph.json %s', Format::bytes($this->graphBytes)),
        ];

        $baseline = Summary::baselineSegment($this->branch, $this->sha);

        if ($baseline !== null) {
            $segments[] = $baseline;
        }

        $segments[] = Format::duration($this->seconds);

        return Summary::label($colors) . '  ● ' . implode(' · ', $segments);
    }
}
