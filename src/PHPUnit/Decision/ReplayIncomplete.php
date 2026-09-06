<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Decision;

use Manuglopez\Replay\Cache\Graph;

/**
 * Replay an incomplete result: `markTestIncomplete($message)` without running the body.
 *
 * @phpstan-import-type TestResultArray from Graph
 */
final readonly class ReplayIncomplete extends Decision
{
    /** @param TestResultArray $cached */
    public function __construct(
        public string $message,
        public array $cached,
    ) {
    }

    public function isReplay(): bool
    {
        return true;
    }
}
