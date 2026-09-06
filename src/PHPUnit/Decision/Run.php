<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Decision;

/** Execute the test for real. `$reason` is what put it in the run list. */
final readonly class Run extends Decision
{
    /** @param string $reason affected|uncached|rerun|quarantined|not-cacheable|depends-provider|no-baseline */
    public function __construct(public string $reason)
    {
    }

    public function isReplay(): bool
    {
        return false;
    }
}
