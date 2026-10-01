<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Select\NonEdgeInputs;

/**
 * When a branch graph may go to the remote (SPEC.md §9): only from a working tree that is
 * clean of anything a result could depend on.
 *
 * Objects are always publishable: an object is addressed by the content key of what actually
 * ran and carries its non-edge input digest, so it describes itself whatever the tree was. A
 * graph does not. Another machine adopts it as a baseline at the graph's sha, and its
 * results, recorded on a dirty tree, are a statement about content that sha does not have.
 * Stamps (`Select\StampAudit`) already stop such a result from being served, but the graph
 * would still be the baseline every machine starts from and re-executes against, so it is
 * not published at all.
 *
 * Dirty means: a changed path — tracked (modified, staged, added, deleted, renamed) or
 * untracked — that a result could depend on ({@see NonEdgeInputs::covers()}: a test file, a
 * file of the graph's universe, a path a non-edge scope would hold). Not dirty: a path git
 * ignores (`ChangedFiles::workingTreeStatus()`, as for every diff), a path nothing reads (a
 * README, a lock file of another toolchain), and the structural files
 * (`Fingerprint::structuralPaths()`), which the graph's own fingerprint records as they are on
 * disk, so a machine without the same edit rejects the graph rather than trusting it. A CI
 * step that rewrites `phpunit.xml` therefore publishes. Fails closed: when git cannot say, the
 * tree is treated as dirty.
 */
final class GraphPublication
{
    /**
     * @return list<string>|null the paths that make the tree dirty, `[]` when it is clean, null
     *   when git could not tell
     */
    public static function dirtyPaths(ChangedFiles $changedFiles, NonEdgeInputs $inputs): ?array
    {
        $entries = $changedFiles->workingTreeStatus();

        if ($entries === null) {
            return null;
        }

        $structural = array_fill_keys(Fingerprint::structuralPaths(), true);
        $dirty = [];

        foreach ($entries as $entry) {
            $path = $entry['path'];

            if (! isset($structural[$path]) && $inputs->covers($path)) {
                $dirty[$path] = true;
            }
        }

        $paths = array_map(strval(...), array_keys($dirty));
        sort($paths);

        return $paths;
    }

    /**
     * The one line said instead of publishing, or null when the graph may go: `$what` names
     * it (`the main baseline`, `the branch graph`).
     */
    public static function refusal(ChangedFiles $changedFiles, NonEdgeInputs $inputs, string $what): ?string
    {
        $dirty = self::dirtyPaths($changedFiles, $inputs);

        if ($dirty === []) {
            return null;
        }

        if ($dirty === null) {
            return sprintf('%s was not published: git could not tell whether the working tree is clean', $what);
        }

        $shown = implode(', ', array_slice($dirty, 0, 3));
        $more = count($dirty) > 3 ? sprintf(' and %d more', count($dirty) - 3) : '';

        return sprintf('%s was not published: the working tree is dirty (%s%s)', $what, $shown, $more);
    }
}
