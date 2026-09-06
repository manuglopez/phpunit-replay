<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\PHPUnit\ReplayState;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/**
 * In-process replay writes the graph itself instead of leaving a run partial behind for
 * the wrapper to pick up, so this replaces `FlushOnExecutionFinished` (SPEC.md §6.3).
 */
final readonly class PersistInProcessOnExecutionFinished implements ExecutionFinishedSubscriber
{
    public function notify(ExecutionFinished $event): void
    {
        ReplayState::persistInProcess();
    }
}
