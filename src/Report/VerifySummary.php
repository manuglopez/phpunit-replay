<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

/**
 * `phpunit-replay verify`'s own summary line (SPEC.md §12.2):
 *
 *   Verify  ✓ 1240 tests · 1198 would replay · 0 divergences (lifetime: 2 in 143 runs)
 *
 * `✗` when this run found a divergence, or PHPUnit itself failed.
 */
final readonly class VerifySummary
{
    public function __construct(
        public int $tests,
        public int $wouldReplay,
        public int $divergences,
        public int $lifetimeDivergences,
        public int $lifetimeRuns,
        public bool $success,
    ) {
    }

    public function format(bool $colors = false): string
    {
        $symbol = $this->success ? '✓' : '✗';

        if ($colors) {
            $symbol = $this->success
                ? "\e[32m{$symbol}\e[0m"
                : "\e[31m{$symbol}\e[0m";
        }

        $label = $colors ? "\e[1mVerify\e[0m" : 'Verify';

        return sprintf(
            '%s  %s %d tests · %d would replay · %d divergences (lifetime: %d in %d runs)',
            $label,
            $symbol,
            $this->tests,
            $this->wouldReplay,
            $this->divergences,
            $this->lifetimeDivergences,
            $this->lifetimeRuns,
        );
    }
}
