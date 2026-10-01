<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache\Remote;

use Closure;
use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphPublication;
use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Select\NonEdgeInputs;

/**
 * Everything the stamp decides about the remote, in one place: what a pass may take from it
 * and what it may give it. Shared by the wrapper (`Console\Runner\RunPipeline`), the
 * in-process extension (`PHPUnit\ReplayState`) and `push`, which used to carry three copies.
 *
 * - **Serving** ({@see self::served()}): a remote object stands in for running a test file
 *   only with the results it holds under the file's current content key AND its current
 *   non-edge digest (`ObjectStore::resultsFor()`); an object written before digests existed
 *   proves only the key, and serves only a file selected for reasons the key covers.
 * - **Objects** ({@see self::publishObjects()}): only results stamped with the current key and
 *   digest, published under both, whatever the working tree (they describe what ran).
 * - **The graph** ({@see self::publishGraph()}): only from a clean working tree
 *   (`Cache\GraphPublication`), and only the branch's results whose stamp is the current
 *   tree's — a dirty run's results, left in the layer after the edit was discarded, would
 *   otherwise travel with a graph published from the clean tree.
 *
 * @phpstan-import-type TestResultArray from Graph
 */
final class Exchange
{
    /** @var array<string, array{key: ?string, digest: ?string}> */
    private array $current = [];

    public function __construct(
        private readonly ObjectStore $objects,
        private readonly Graph $graph,
        private readonly ContentKey $contentKey,
        private readonly NonEdgeInputs $inputs,
    ) {
    }

    /**
     * The results a remote object proves for `$file` on the current tree, stamped with what it
     * proves (the key, and the digest when the object answered by digest), or null.
     *
     * @param Closure(array<string, TestResultArray>): bool $holdsARerun a status SPEC.md §6.2
     *        re-runs whatever the cache says: such an object is no use
     * @return array<string, TestResultArray>|null
     */
    public function served(string $file, bool $coveredByContentKey, Closure $holdsARerun): ?array
    {
        ['key' => $key, 'digest' => $digest] = $this->current($file);

        if ($key === null) {
            return null;
        }

        $object = $this->objects->object($key, $digest);
        $results = $object === null ? null : ObjectStore::resultsFor($object, $digest, $coveredByContentKey);

        if ($object === null || $results === null || $holdsARerun($results)) {
            return null;
        }

        $proven = $digest !== null && ObjectStore::holds($object, $digest) ? $digest : null;
        $stamped = [];

        foreach ($results as $testId => $result) {
            unset($result['digest']);
            $result['file'] = $file;
            $result['key'] = $key;

            // An object from before digests proves no digest: the result carries none, and
            // the next pass's Select\StampAudit runs its file once.
            if ($proven !== null) {
                $result['digest'] = $proven;
            }

            $stamped[$testId] = $result;
        }

        return $stamped;
    }

    /**
     * One object per test file of `$files` holding a result stamped with the file's current
     * key and digest, taken from `$results`.
     *
     * @param list<string> $files
     * @param array<string, TestResultArray> $results
     */
    public function publishObjects(array $files, array $results): void
    {
        foreach ($files as $file) {
            if ($this->graph->isNotCacheable($file)) {
                continue;
            }

            ['key' => $key, 'digest' => $digest] = $this->current($file);

            if ($key === null || $digest === null) {
                continue;
            }

            $recorded = ContentKey::resultsRecordedAt($results, $file, $key, $digest);

            if ($recorded !== []) {
                $this->objects->putObject($key, $file, $recorded, $digest);
            }
        }
    }

    /**
     * @param string $what names the graph in the one line said instead of publishing it
     * @return array{refusal: ?string, published: bool}
     */
    public function publishGraph(string $branch, ChangedFiles $changedFiles, string $what): array
    {
        $refusal = GraphPublication::refusal($changedFiles, $this->inputs, $what);

        if ($refusal !== null) {
            return ['refusal' => $refusal, 'published' => false];
        }

        $body = $this->graph->encode(fn (string $testId, array $result): bool => $this->currentlyStamped($result), $branch);

        return ['refusal' => null, 'published' => $body !== null && $this->objects->putGraph($branch, $body)];
    }

    /** @param TestResultArray $result */
    private function currentlyStamped(array $result): bool
    {
        $file = $result['file'] ?? null;

        if (! is_string($file) || $file === '') {
            return false;
        }

        $current = $this->current($file);

        return $current['key'] !== null
            && $current['digest'] !== null
            && ($result['key'] ?? null) === $current['key']
            && ($result['digest'] ?? null) === $current['digest'];
    }

    /** @return array{key: ?string, digest: ?string} */
    private function current(string $file): array
    {
        return $this->current[$file] ??= [
            'key' => $this->contentKey->forTestFile($this->graph, $file),
            'digest' => $this->inputs->digestFor($file),
        ];
    }
}
