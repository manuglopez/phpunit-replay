<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Closure;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\ResultCollector;
use Manuglopez\Replay\Record\RunWriter;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

final readonly class FlushOnExecutionFinished implements ExecutionFinishedSubscriber
{
    /**
     * @param Closure(): array<string, mixed> $meta produces the meta.json payload (driver, php, os, mode,
     *                                                startedAt, finishedAt, fingerprint, ...) at flush time
     */
    public function __construct(
        private RunWriter $runWriter,
        private Recorder $recorder,
        private ResultCollector $collector,
        private Closure $meta,
    ) {
    }

    public function notify(ExecutionFinished $event): void
    {
        $this->runWriter->flush($this->recorder, $this->collector, ($this->meta)());
    }
}
