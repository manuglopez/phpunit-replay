<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use Closure;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Change\LastRunTree;

/**
 * Makes every result layer a pass can read valid for the tree it is about to serve them on
 * (docs/INTERNALS.md "Result layers").
 *
 * A result recorded in layer L at sha s may be served for test file T only if T is
 * unaffected by the change set between s and the current tree — the same question the pass
 * asks of the baseline it resolved, whose sha IS the diff base. `Graph::results()` layers
 * the branch's own results over its fallbacks, and nothing used to ask that question of any
 * layer but the resolved one. The false green it let through: a branch runs (its layer
 * holds passes recorded on its own change), reverts that change and merges a branch that
 * breaks the same code; that branch's baseline is now 0 files away and wins, its diff is
 * empty, and the branch's own stale passes override the failures underneath them.
 *
 * Walking the layers highest priority first:
 *
 * - the layer whose sha is the diff base is TRUSTED as its sha's tree: the pass's own diff
 *   is exactly this rule applied to it, provided its results really were recorded on that
 *   tree. That premise is not audited here; {@see StampAudit}, which runs next, checks every
 *   result against the inputs it was stamped with, and is what closes its exceptions —
 *   results written into a layer without its sha moving (a CI pass without
 *   `--allow-ci-baseline`, an incomplete pass) and results recorded on a dirty working tree
 *   (then read on another branch, or published with `remote_push: all` and adopted
 *   elsewhere);
 * - a layer BELOW it needs no check of its own (`Graph::fallbackChain()` argues why, on the
 *   same premise: the base layer is a delta its own passes wrote, so anything it lacks was
 *   served from below as valid on its tree, and the diff says nothing the test depends on
 *   moved since);
 * - any other layer — above the base (the branch's own, when a nearer baseline won, or
 *   when its sha is not an ancestor any more), or below a base this graph holds no layer
 *   for (a sha the resolver read off the remote) — gets its own change set, and whatever
 *   the rule chain selects from it is invalid. A layer with no sha, or one git does not
 *   have (a shallow clone), cannot be checked and serves nothing: a miss, never a stale
 *   hit.
 *
 * The branch's own layer is FORGOTTEN (the pass is about to re-base it onto this tree, so
 * an invalid entry must not survive into the baseline it records); any other branch's layer
 * is only WITHHELD for this pass, because it is still valid on its own branch. Cost: one
 * diff per audited layer, and none at all in the common case where the branch's own
 * baseline is the one that won.
 *
 * The own layer's change set honours the last-run snapshot (`Change\LastRunTree`) the same
 * way a pass diffing from the own baseline does, when that snapshot describes the own
 * layer's sha: it is what the own layer's results were recorded against when the working
 * tree was dirty. The same trust-the-sha premise applies to an audited layer: its own diff
 * is taken from its sha, so it inherits the exceptions above.
 *
 * @phpstan-type Stale array<string, array{reason: Reason, ids: list<string>}>
 */
final readonly class LayerAudit
{
    /**
     * @param Closure(list<string>, ?string): Selection $select the pass's own rule chain, given
     *        a change set and the sha it was diffed from
     *        ({@see RunListBuilder::select()})
     */
    public function __construct(
        private Graph $graph,
        private ChangedFiles $changedFiles,
        private Closure $select,
    ) {
    }

    /**
     * @param string $diffBase the sha the pass's change set is taken from
     * @return Stale every test file some layer stopped serving, with the first such layer's
     *   reason and the test ids it held for that file
     */
    public function apply(string $branch, string $diffBase, ?LastRunTree $lastRun = null): array
    {
        $stale = [];
        $baseReached = false;

        foreach ($this->graph->layersOf($branch) as $layer) {
            $sha = $this->graph->ownRecordedSha($layer);

            if ($sha !== null && $sha === $diffBase) {
                $baseReached = true;

                continue;
            }

            if ($baseReached) {
                continue;
            }

            $idsByFile = self::idsByFile($this->graph->ownResults($layer));

            if ($idsByFile === []) {
                continue;
            }

            [$invalid, $whole] = $this->invalidFiles($layer, $sha, array_keys($idsByFile), $lastRun);

            if ($invalid === []) {
                continue;
            }

            if ($layer === $branch) {
                $whole ? $this->graph->clearResults($layer) : $this->graph->forgetResults($layer, array_keys($invalid));
            } else {
                $this->graph->withholdResults($layer, $whole ? null : array_keys($invalid));
            }

            foreach ($invalid as $file => $reason) {
                $stale[$file] ??= ['reason' => $reason, 'ids' => []];
                $stale[$file]['ids'] = array_values(array_unique([...$stale[$file]['ids'], ...$idsByFile[$file]]));
            }
        }

        ksort($stale);

        return $stale;
    }

    /**
     * @param list<string> $files the test files this layer holds results for
     * @return array{0: array<string, Reason>, 1: bool} the ones it may not serve on the
     *   current tree, and whether that is the whole layer (it could not be checked at all)
     */
    private function invalidFiles(string $layer, ?string $sha, array $files, ?LastRunTree $lastRun): array
    {
        if ($sha === null || $sha === '') {
            return [self::all($files, new Reason('StaleLayer', $layer, 'no recorded sha')), true];
        }

        $label = $layer . '@' . substr($sha, 0, 7);
        $changed = $this->changedFiles->since($sha, requireAncestor: false);

        if ($changed === null) {
            return [self::all($files, new Reason('StaleLayer', $label, 'sha not available')), true];
        }

        // Only the snapshot of THIS layer at THIS sha says what its results were recorded on
        // (in practice the own layer: the snapshot is always the branch's that last ran).
        if ($lastRun !== null && $lastRun->describes($layer, $sha)) {
            $changed = $lastRun->filterUnchanged($changed, $this->changedFiles);
        }

        if ($changed === []) {
            return [[], false];
        }

        $selection = ($this->select)($changed, $sha);
        $reasons = $selection->reasons();
        $invalid = [];

        foreach ($files as $file) {
            $first = $reasons[$file][0] ?? null;

            if ($first !== null) {
                $invalid[$file] = new Reason('StaleLayer', $first->trigger, $label);
            }
        }

        return [$invalid, false];
    }

    /**
     * @param list<string> $files
     * @return array<string, Reason>
     */
    private static function all(array $files, Reason $reason): array
    {
        return array_fill_keys($files, $reason);
    }

    /**
     * @param array<string, array{file?: string}> $results
     * @return array<string, list<string>>
     */
    private static function idsByFile(array $results): array
    {
        $byFile = [];

        foreach ($results as $testId => $result) {
            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '') {
                $byFile[$file][] = $testId;
            }
        }

        return $byFile;
    }
}
