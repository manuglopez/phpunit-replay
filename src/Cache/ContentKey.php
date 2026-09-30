<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

/**
 * Content-addressed key for a test file. SPEC.md §4.3.
 *
 * k = xxh128(
 *     canonicalStructural(fingerprint) .
 *     canonicalResultEnvironment(fingerprint) .
 *     ContentHash(testFile) .
 *     implode('', sorted(map(dependencies, rel => rel . ':' . ContentHash(rel))))
 * )
 *
 * A dependency whose file is missing on disk still changes the key: it contributes
 * "rel:" (empty hash) rather than being skipped, so a deleted dependency is visible.
 *
 * The second segment is what stops a shared cache from serving a result across a PHP minor
 * or an OS. `Fingerprint::environmentalDrift()` only ever discards the results a machine
 * recorded itself, and nothing re-checks the environment of a result adopted from a remote
 * cache (`Console\Runner\RunPipeline::replayFromRemote()` weighs the key, the object's
 * existence and whether it holds a status that forces a re-run, and nothing more), so the
 * part of the environment that can change an outcome has to sit in the address rather than in
 * a check. Which part that is — and why the coverage driver and the coverage format are not —
 * is argued on `Fingerprint::canonicalResultEnvironment()`. Both fingerprint segments are
 * canonical JSON objects and therefore self-delimiting: the concatenation addresses exactly
 * one (project, environment) pair without a separator or a truncated digest of either half.
 *
 * @phpstan-import-type TestResultArray from Graph
 */
final readonly class ContentKey
{
    /**
     * `$hashes` is the pass's shared memo ({@see FileHashes}); without one every call reads
     * the files again. Either way the key is the same function of the same bytes.
     */
    public function __construct(private string $projectRoot, private ?FileHashes $hashes = null)
    {
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @param  list<string>  $dependencies relative paths
     */
    public function compute(array $fingerprint, string $testFileRel, array $dependencies): ?string
    {
        $testHash = $this->hash($testFileRel);

        if ($testHash === null) {
            return null;
        }

        $parts = [];

        foreach ($dependencies as $dependency) {
            $hash = $this->hash($dependency) ?? '';
            $parts[] = $dependency . ':' . $hash;
        }

        sort($parts);

        $material = Fingerprint::canonicalStructural($fingerprint)
            . Fingerprint::canonicalResultEnvironment($fingerprint)
            . $testHash
            . implode('', $parts);

        return hash('xxh128', $material);
    }

    public function forTestFile(Graph $graph, string $testFileRel): ?string
    {
        return $this->compute($graph->fingerprint(), $testFileRel, $graph->dependenciesOf($testFileRel));
    }

    /**
     * What a remote object under `$key` may hold for `$testFile`: only the results recorded
     * under that very key (`GraphUpdater` stamps each executed result with the key it ran
     * at). An object vouches for exactly the content its key addresses, so a cached result
     * recorded on other content — an edit never run since, a layer recorded before a revert,
     * or an id an incomplete pass left behind (a renamed or removed method `pruneStaleResults`
     * never got to drop) — must not be published as a verdict on content it never ran
     * against. Shared by every publisher: `push`, and both post-run push loops.
     *
     * The same holds for the other half of a result's stamp, the non-edge input digest
     * (`Select\NonEdgeInputs`): an object carries one, and only results recorded under it go
     * in. A result without one — recorded before stamps existed, or when the digest could not
     * be computed — is published by nobody: nothing could validate it on the other side.
     *
     * @param  array<string, TestResultArray>  $results
     * @return array<string, TestResultArray>
     */
    public static function resultsRecordedAt(array $results, string $testFile, string $key, string $digest): array
    {
        $recorded = [];

        foreach ($results as $testId => $result) {
            if (($result['file'] ?? null) === $testFile && ($result['key'] ?? null) === $key && ($result['digest'] ?? null) === $digest) {
                $recorded[$testId] = $result;
            }
        }

        return $recorded;
    }

    private function hash(string $relative): ?string
    {
        return $this->hashes !== null
            ? $this->hashes->of($relative)
            : ContentHash::of(rtrim($this->projectRoot, '/') . '/' . $relative);
    }
}
