<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Decision;

/**
 * What the in-process replay engine decided about one test: execute it for real
 * ({@see Run}) or satisfy it from the cached baseline ({@see ReplayPass},
 * {@see ReplaySkipped}, {@see ReplayIncomplete}). SPEC.md §6.2.
 */
abstract readonly class Decision
{
    abstract public function isReplay(): bool;
}
