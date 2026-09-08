<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

/**
 * `phpunit-replay verify`'s own summary line (SPEC.md §12.2):
 *
 *   Verify  ✓ 1240 tests · 1198 would replay · 0 divergences · 0 unverified (lifetime: 2 in 143 runs)
 *
 * `✗` when this run found a divergence, or PHPUnit itself failed.
 *
 * What each figure means, precisely — the three after `tests` answer different questions
 * and are deliberately measured over different populations:
 *
 * - `$tests`: how many tests this pass executed for real.
 * - `$wouldReplay`: how many of those a `run` on this same tree would have served from
 *   cache instead of executing. Decided by {@see \Manuglopez\Replay\Select\ReplaySet} —
 *   the same run-list code `run` itself decides with — against the state as it was before
 *   this pass touched anything. It is a property of the tree, so two identical passes over
 *   an unchanged tree report the same number.
 * - `$divergences`: results whose status *class* differs from the cached one recorded
 *   against the same content key. Deliberately **broader** than `$wouldReplay`: it flags a
 *   mismatch even for a test `run` would have re-executed anyway, which can only
 *   over-report one, never miss one.
 * - `$unverified`: of the `$wouldReplay` tests, how many this pass could not check, because
 *   it observed a different dependency set than the cached result was recorded against (the
 *   content keys no longer match, so the divergence comparison above skips them). `run`
 *   would still have served those cached results — it never compares keys — so this is the
 *   part of `$wouldReplay` the pass is not vouching for. It falls to 0 once the graph's
 *   edges have settled, and a non-zero value is a reason to run `verify` again rather than
 *   to distrust the fast lane outright.
 */
final readonly class VerifySummary
{
    public function __construct(
        public int $tests,
        public int $wouldReplay,
        public int $divergences,
        public int $unverified,
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
            '%s  %s %d tests · %d would replay · %d divergences · %d unverified (lifetime: %d in %d runs)',
            $label,
            $symbol,
            $this->tests,
            $this->wouldReplay,
            $this->divergences,
            $this->unverified,
            $this->lifetimeDivergences,
            $this->lifetimeRuns,
        );
    }
}
