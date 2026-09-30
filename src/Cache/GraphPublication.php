<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Select\NonEdgeInputs;

/**
 * When a branch graph may go to the remote (SPEC.md §9): only from a clean working tree.
 *
 * Objects are always publishable: an object is addressed by the content key of what actually
 * ran and carries its non-edge input digest, so it describes itself whatever the tree was. A
 * graph does not. Another machine adopts it as a baseline at the graph's sha, and its
 * results, recorded on a dirty tree, are a statement about content that sha does not have.
 * Stamps (`Select\StampAudit`) already stop such a result from being served, but the graph
 * would still be the baseline every machine starts from and re-executes against, so it is
 * not published at all.
 *
 * Clean means: no tracked file modified, staged, added, deleted or renamed, and no untracked
 * file that anything a result depends on could include — a test file, a file of the graph's
 * universe, or a member of a non-edge scope ({@see NonEdgeInputs::covers()}). An untracked
 * note nobody reads leaves the tree clean. Fails closed: when git cannot say, the tree is
 * treated as dirty.
 */
final class GraphPublication
{
    /**
     * @return list<string>|null the paths that make the tree dirty, `[]` when it is clean, null
     *   when git could not tell
     */
    public static function dirtyPaths(Git $git, NonEdgeInputs $inputs): ?array
    {
        $entries = $git->statusEntries();

        if ($entries === null) {
            return null;
        }

        $dirty = [];

        foreach ($entries as $entry) {
            if ($entry['status'] === '!!') {
                continue;
            }

            if ($entry['status'] !== '??' || $inputs->covers($entry['path'])) {
                $dirty[$entry['path']] = true;
            }
        }

        $paths = array_keys($dirty);
        sort($paths);

        return $paths;
    }

    /**
     * The one line said instead of publishing, or null when the graph may go: `$what` names
     * it (`the main baseline`, `the branch graph`).
     */
    public static function refusal(Git $git, NonEdgeInputs $inputs, string $what): ?string
    {
        $dirty = self::dirtyPaths($git, $inputs);

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
