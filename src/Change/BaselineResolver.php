<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Change;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Config;

/**
 * Picks the baseline a pass should diff against (DECISIONS.md D-039, docs/INTERNALS.md
 * "Nearest baseline"). A git-flow project has more than one long-lived branch, and the
 * useful baseline for a feature branch cut from `develop` is `develop`, not `main`:
 * diffing against `main` would select every test touched by everything `develop` has
 * merged since the last release.
 *
 * Candidates are the branch itself (its own baseline, if it has one) followed by
 * `baseline_branches` in order of preference (or the single `default_branch` shorthand).
 * A candidate counts when its recorded sha is an ancestor of HEAD; the winner is the one
 * with the fewest files between it and HEAD, ties going to the earlier candidate — and
 * the branch's own baseline wins every tie, since a baseline recorded on this very branch
 * needs no cross-branch result fallback at all.
 *
 * Shas come from the local graph first and from `graph/<project-key>/<branch>.json` on the
 * remote second, so a machine that has never recorded `develop` can still inherit its
 * baseline. Nothing here throws: no candidate resolving simply returns null, which the
 * caller reads as "record a fresh baseline".
 */
final readonly class BaselineResolver
{
    public function __construct(
        private Git $git,
        private Graph $graph,
        private ?ObjectStore $remote,
        private Config $config,
        /** What the caller auto-detected as the default branch; used when nothing is configured. */
        private ?string $defaultBranch = null,
    ) {
    }

    /**
     * @return array{branch: string, sha: string, source: 'own'|'local'|'remote', distance: int}|null
     */
    public function resolve(string $currentBranch, string $head): ?array
    {
        $best = null;

        foreach ($this->candidates($currentBranch) as $branch) {
            $found = $this->shaFor($branch);

            if ($found === null) {
                continue;
            }

            [$sha, $source] = $found;

            if (! $this->git->isAncestor($sha, $head)) {
                continue;
            }

            $distance = $this->distance($sha, $head);

            if ($distance === null) {
                continue;
            }

            $candidate = [
                'branch' => $branch,
                'sha' => $sha,
                'source' => $branch === $currentBranch ? 'own' : $source,
                'distance' => $distance,
            ];

            // The branch's own baseline is always the first candidate, so "own wins on a
            // tie" is the same rule as "the earlier candidate wins a tie".
            if ($best === null || $distance < $best['distance']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * The branch's own baseline first, then the configured candidates in order of
     * preference, deduplicated.
     *
     * @return list<string>
     */
    public function candidates(string $currentBranch): array
    {
        $fallback = $this->defaultBranch ?? $this->git->defaultBranch() ?? 'main';
        $candidates = [$currentBranch, ...$this->config->baselineCandidates($fallback)];

        $seen = [];

        foreach ($candidates as $branch) {
            if ($branch !== '') {
                $seen[$branch] = true;
            }
        }

        return array_keys($seen);
    }

    /** @return array{0: string, 1: 'local'|'remote'}|null */
    private function shaFor(string $branch): ?array
    {
        $local = $this->graph->ownRecordedSha($branch);

        if ($local !== null && $local !== '') {
            return [$local, 'local'];
        }

        if ($this->remote === null) {
            return null;
        }

        $remoteGraph = $this->remote->graphOf($branch, $this->graph->projectRoot());
        $sha = $remoteGraph?->ownRecordedSha($branch);

        return ($sha === null || $sha === '') ? null : [$sha, 'remote'];
    }

    /** Files between `$sha` and `$head`; null when git could not answer. */
    private function distance(string $sha, string $head): ?int
    {
        $output = $this->git->raw(['diff', '--name-only', $sha . '..' . $head]);

        if ($output === null) {
            return null;
        }

        $lines = preg_split('/\R/', trim($output), flags: PREG_SPLIT_NO_EMPTY);

        return $lines === false ? null : count($lines);
    }

    /**
     * `baseline develop@abc1234 (nearest, 3 files away)` — the `status`/`--explain` line
     * (docs/INTERNALS.md "Nearest baseline"). Null when the resolved baseline is the
     * branch's own, which the summary line already reports.
     *
     * @param array{branch: string, sha: string, source: string, distance: int} $baseline
     */
    public static function describe(array $baseline, string $currentBranch): ?string
    {
        if ($baseline['branch'] === $currentBranch) {
            return null;
        }

        return sprintf(
            'baseline %s@%s (nearest, %d file%s away)',
            $baseline['branch'],
            substr($baseline['sha'], 0, 7),
            $baseline['distance'],
            $baseline['distance'] === 1 ? '' : 's',
        );
    }
}
