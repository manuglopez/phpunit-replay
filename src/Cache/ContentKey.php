<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

/**
 * Content-addressed key for a test file. SPEC.md §4.3.
 *
 * k = xxh128(
 *     canonicalStructural(fingerprint) .
 *     ContentHash(testFile) .
 *     implode('', sorted(map(dependencies, rel => rel . ':' . ContentHash(rel))))
 * )
 *
 * A dependency whose file is missing on disk still changes the key: it contributes
 * "rel:" (empty hash) rather than being skipped, so a deleted dependency is visible.
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

        $material = Fingerprint::canonicalStructural($fingerprint) . $testHash . implode('', $parts);

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
