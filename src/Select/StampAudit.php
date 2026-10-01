<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use Closure;
use Manuglopez\Replay\Cache\Graph;

/**
 * Serves a cached result only where it proves it ran on the inputs the current tree has
 * (docs/INTERNALS.md "Result layers").
 *
 * {@see LayerAudit} makes every layer valid for the tree on one premise: that a layer's
 * results were recorded on the tree at the layer's sha. Three ways of writing results break
 * it, and nothing used to notice: a run on a dirty working tree (read back on another
 * branch, or published with `remote_push: all` and adopted by another machine), a CI run
 * without `--allow-ci-baseline` and an incomplete run (both write results without moving the
 * sha, so a revert to that sha serves them). Every result is therefore stamped, when it is
 * recorded, with the content key of its test file (`Cache\ContentKey`) and its non-edge input
 * digest ({@see NonEdgeInputs}), and this audit, run after {@see LayerAudit} and before the run
 * list is built, checks every result any layer would still serve against both, recomputed on
 * the current tree. The key covers the test file and every file it executed; the digest,
 * every file a non-edge rule would select it for. A result that does not match either — or
 * carries no stamp at all, like every result written before stamps existed — is not served.
 *
 * An invalid result is masked for this pass, by test id and in every layer, the branch's own
 * included (`Graph::withholdTestIds()`): the id is served from the next layer holding a
 * valid result for it, and a file with an id no layer can serve executes
 * ({@see RunListBuilder}'s stale bucket). Nothing is deleted. A fresh result written over a
 * masked one is served at once, and one the pass does not replace — an incomplete pass, a
 * method since renamed — keeps failing this audit on every later pass, so its file keeps
 * executing until a complete pass replaces or prunes it. Deleting it instead (as
 * {@see LayerAudit} does with its own finds) would lose the test id: after an interrupted
 * re-run a file would then hold results for only some of its tests, and the rest would be
 * neither executed nor replayed. A file {@see LayerAudit} already stopped serving keeps that
 * reason; a file both audits drop is explained once.
 *
 * This is a check on top of {@see LayerAudit}, not a replacement: the layer audit asks
 * whether the change set since a layer's sha touches a file, which also catches what a stamp
 * cannot (a result stamped on a tree the layer's diff moved away from is equally caught by
 * both); the stamp asks whether the result ran where the layer says it did.
 *
 * @phpstan-import-type Stale from LayerAudit
 */
final readonly class StampAudit
{
    public const RULE = 'StaleResult';

    public const KEY_CHANGED = 'content key changed';

    public const INPUTS_CHANGED = 'non-edge inputs changed';

    public const UNSTAMPED = 'unstamped';

    /**
     * @param Closure(string): ?string $key the test file's content key on the current tree
     * @param Closure(string): ?string $digest its non-edge input digest ({@see NonEdgeInputs::digestFor()})
     */
    public function __construct(
        private Graph $graph,
        private Closure $key,
        private Closure $digest,
    ) {
    }

    /**
     * @param Stale $stale what {@see LayerAudit::apply()} already stopped serving
     * @return Stale that, plus every test file one of whose results this audit masked, with
     *   the reason of the first layer that did and every id masked for it
     */
    public function apply(string $branch, array $stale = []): array
    {
        /** @var array<string, array{key: ?string, digest: ?string}> $current */
        $current = [];

        foreach ($this->graph->layersOf($branch) as $layer) {
            $byFile = [];

            foreach ($this->graph->servableResults($layer) as $testId => $result) {
                $file = $result['file'] ?? null;

                if (is_string($file) && $file !== '') {
                    $byFile[$file][$testId] = $result;
                }
            }

            if ($byFile === []) {
                continue;
            }

            $label = null;
            $masked = [];

            foreach ($byFile as $file => $results) {
                $file = (string) $file;
                $current[$file] ??= ['key' => ($this->key)($file), 'digest' => ($this->digest)($file)];
                $why = null;
                $ids = [];

                foreach ($results as $testId => $result) {
                    $mismatch = self::mismatch($result, $current[$file]['key'], $current[$file]['digest']);

                    if ($mismatch !== null) {
                        $ids[] = (string) $testId;
                        $why = self::worse($why, $mismatch);
                    }
                }

                if ($why === null) {
                    continue;
                }

                $label ??= $this->label($layer);
                array_push($masked, ...$ids);
                $stale[$file] ??= ['reason' => new Reason(self::RULE, $why, $label), 'ids' => []];
                $stale[$file]['ids'] = array_values(array_unique([...$stale[$file]['ids'], ...$ids]));
            }

            $this->graph->withholdTestIds($layer, $masked);
        }

        ksort($stale);

        return $stale;
    }

    /**
     * Why one result may not be served, or null when it carries the current key and digest.
     *
     * @param array{key?: string, digest?: string} $result
     */
    public static function mismatch(array $result, ?string $key, ?string $digest): ?string
    {
        $recordedKey = $result['key'] ?? null;
        $recordedDigest = $result['digest'] ?? null;

        if ($recordedKey === null) {
            return self::UNSTAMPED;
        }

        if ($key === null || $recordedKey !== $key) {
            return self::KEY_CHANGED;
        }

        if ($recordedDigest === null) {
            return self::UNSTAMPED;
        }

        return $digest === null || $recordedDigest !== $digest ? self::INPUTS_CHANGED : null;
    }

    /**
     * The one reason a file is shown with when its results fail for several: a moved key
     * says the most (the content itself changed), then changed inputs, then a missing stamp.
     */
    private static function worse(?string $current, string $candidate): string
    {
        $rank = [self::KEY_CHANGED => 3, self::INPUTS_CHANGED => 2, self::UNSTAMPED => 1];

        return $current === null || $rank[$candidate] > $rank[$current] ? $candidate : $current;
    }

    private function label(string $layer): string
    {
        $sha = $this->graph->ownRecordedSha($layer);

        return $sha === null || $sha === '' ? $layer : $layer . '@' . substr($sha, 0, 7);
    }
}
