<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Change\LastRunTree;
use Manuglopez\Replay\Console\Runner\Warnings;

/**
 * The write side of a finished pass (SPEC.md §7.3): publish the baseline when the pass
 * was complete and the environment allows it, then always persist the graph. Extracted
 * from `Console\Runner\RunPipeline::persistAfterRun()` so the in-process extension
 * commits exactly the same way the wrapper does.
 */
final class BaselineWriter
{
    public function __construct(
        private readonly GraphStore $store,
        private readonly Git $git,
        private readonly ChangedFiles $changedFiles,
    ) {
    }

    /**
     * A complete pass on a real branch records the sha, marks the baseline complete,
     * prunes branches git no longer knows and snapshots the dirty working tree. In CI
     * that last step is skipped unless explicitly allowed, so a CI run never publishes a
     * baseline by accident. The graph itself is always saved.
     *
     * @return bool whether the graph was written to disk
     */
    public function commit(Graph $graph, GraphUpdater $updater, RunContext $ctx, bool $complete): bool
    {
        if ($complete && $ctx->persist) {
            if (! $ctx->ciMode || $ctx->allowCiBaseline) {
                $updater->finalizeBaseline($ctx->branch, $ctx->head, $this->git->branchNames());

                $dirty = $this->changedFiles->since($ctx->head) ?? [];
                (new LastRunTree($ctx->branch, $ctx->head, $this->changedFiles->snapshotTree($dirty), time()))
                    ->save($ctx->stateDir);
            } else {
                Warnings::warn('CI detected: results saved locally but the baseline was not published (pass --allow-ci-baseline to override)');
            }
        }

        return $this->store->save($graph);
    }
}
