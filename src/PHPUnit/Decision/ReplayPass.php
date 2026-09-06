<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Decision;

use Manuglopez\Replay\Cache\Graph;

/**
 * Replay a passing (or notice/deprecation/risky/warning) result: inject the recorded
 * assertion count instead of running the body. A test recorded as risky keeps its
 * riskiness naturally — `$wasRisky` tells the trait not to suppress it with
 * `expectNotToPerformAssertions()`.
 *
 * @phpstan-import-type TestResultArray from Graph
 */
final readonly class ReplayPass extends Decision
{
    /** @param TestResultArray $cached */
    public function __construct(
        public int $assertions,
        public bool $wasRisky,
        public array $cached,
    ) {
    }

    public function isReplay(): bool
    {
        return true;
    }
}
