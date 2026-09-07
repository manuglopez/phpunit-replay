<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Closure;
use Manuglopez\Replay\Record\NotCacheableCollector;
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
     * @param (Closure(): array<string, string>)|null $coverageSnapshots produces the
     *        `coverage.json` payload (project-relative test file => coverage snapshot key,
     *        Record\CoverageSnapshots, SPEC.md §3.2 last paragraph) at flush time; null when
     *        coverage snapshot capture is not active this run.
     */
    public function __construct(
        private RunWriter $runWriter,
        private Recorder $recorder,
        private ResultCollector $collector,
        private Closure $meta,
        private ?NotCacheableCollector $notCacheable = null,
        private ?Closure $coverageSnapshots = null,
    ) {
    }

    public function notify(ExecutionFinished $event): void
    {
        $coverage = $this->coverageSnapshots !== null ? ($this->coverageSnapshots)() : null;

        $this->runWriter->flush($this->recorder, $this->collector, ($this->meta)(), $this->notCacheable, $coverage);
    }
}
