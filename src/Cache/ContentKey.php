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
 */
final readonly class ContentKey
{
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @param  list<string>  $dependencies relative paths
     */
    public function compute(array $fingerprint, string $testFileRel, array $dependencies): ?string
    {
        $testHash = ContentHash::of($this->absolute($testFileRel));

        if ($testHash === null) {
            return null;
        }

        $parts = [];

        foreach ($dependencies as $dependency) {
            $hash = ContentHash::of($this->absolute($dependency)) ?? '';
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

    private function absolute(string $relative): string
    {
        return rtrim($this->projectRoot, '/') . '/' . $relative;
    }
}
