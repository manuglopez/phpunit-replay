<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

/**
 * `--dry-run`'s own summary line, printed after the `--explain` table (SPEC.md §11).
 * Unlike {@see Summary}, nothing has actually executed yet, so there is no per-test
 * result to classify: `$affected`/`$uncached`/`$quarantined` count test FILES (the same
 * buckets `--explain` lists), not tests — docs/INTERNALS.md "Summary counters".
 *
 *   Replay  12 test files would run (9 affected, 2 uncached, 1 quarantined), 342 tests would replay
 */
final readonly class DryRunSummary
{
    public function __construct(
        public int $testFiles,
        public int $affected,
        public int $uncached,
        public int $quarantined,
        public int $replayed,
    ) {
    }

    public function format(bool $colors = false): string
    {
        return sprintf(
            '%s  %d test files would run (%d affected, %d uncached, %d quarantined), %d tests would replay',
            Summary::label($colors),
            $this->testFiles,
            $this->affected,
            $this->uncached,
            $this->quarantined,
            $this->replayed,
        );
    }
}
