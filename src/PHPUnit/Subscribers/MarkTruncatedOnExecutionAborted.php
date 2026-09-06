<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\RunWriter;
use PHPUnit\Event\TestRunner\ExecutionAborted;
use PHPUnit\Event\TestRunner\ExecutionAbortedSubscriber;

final readonly class MarkTruncatedOnExecutionAborted implements ExecutionAbortedSubscriber
{
    public function __construct(private RunWriter $runWriter)
    {
    }

    public function notify(ExecutionAborted $event): void
    {
        $this->runWriter->markTruncated();
    }
}
