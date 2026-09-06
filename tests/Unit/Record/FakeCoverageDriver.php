<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\CoverageDriver;

/**
 * A CoverageDriver test double that returns a queued coverage snapshot on each stop(),
 * so RecorderTest can assert its file-level reduction heuristic and its handling of
 * consecutive/nested beginTest() calls without a real coverage extension.
 */
final class FakeCoverageDriver implements CoverageDriver
{
    private int $index = 0;

    public int $startCalls = 0;

    public int $stopCalls = 0;

    /** @param list<array<string, array<int, int>>> $snapshots one entry consumed per stop() call */
    public function __construct(private readonly array $snapshots)
    {
    }

    public static function available(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function start(): void
    {
        $this->startCalls++;
    }

    /** @return array<string, array<int, int>> */
    public function stop(): array
    {
        $this->stopCalls++;
        $snapshot = $this->snapshots[$this->index] ?? [];
        $this->index++;

        return $snapshot;
    }
}
