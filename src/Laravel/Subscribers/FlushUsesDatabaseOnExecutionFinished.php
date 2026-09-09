<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Subscribers;

use Manuglopez\Replay\Laravel\UsesDatabaseCollector;
use Manuglopez\Replay\Record\RunWriter;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/**
 * Writes `<runDir>/uses_database.json` from the {@see UsesDatabaseCollector} filled by
 * `ArmLaravelTrackersOnPrepared` over the course of the run. Deliberately independent of
 * `FlushOnExecutionFinished`/`RunWriter::flush()` (SPEC.md §10, docs/INTERNALS.md "Laravel"):
 * the Laravel integration is optional and must not change the signature the core recording
 * path already relies on.
 */
final readonly class FlushUsesDatabaseOnExecutionFinished implements ExecutionFinishedSubscriber
{
    public function __construct(
        private RunWriter $runWriter,
        private UsesDatabaseCollector $usesDatabase,
    ) {
    }

    public function notify(ExecutionFinished $event): void
    {
        $this->runWriter->writeUsesDatabase($this->usesDatabase->all());
    }
}
