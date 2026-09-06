<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record\Fixtures;

/**
 * Exercised (never asserted against) by PcovDriverTest to produce real, non-synthetic
 * coverage data for a file living outside src/.
 */
final class CoverageSubject
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
