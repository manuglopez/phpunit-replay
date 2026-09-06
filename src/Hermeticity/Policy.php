<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Hermeticity;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;

/**
 * Whether a given test may be replayed from cache at all (SPEC.md §8).
 *
 * Skeleton: only rule 1 (the `#[NotCacheable]` attribute, persisted in the graph's
 * `not_cacheable` section) is honoured. The `never_cache` globs (rule 2), the
 * automatic quarantine (rule 3) and the optional heuristics (rule 4) are wired into
 * the constructor but not consulted yet; they belong to the hermeticity work.
 */
final class Policy
{
    public function __construct(
        private readonly Graph $graph,
        private readonly Config $config,
        private readonly Quarantine $quarantine,
        private readonly string $projectRoot,
    ) {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function quarantine(): Quarantine
    {
        return $this->quarantine;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /** @param string $testId `Class::method`, or `Class::method#dataSetName` for a data set. */
    public function cacheable(string $testFileRel, string $testId): bool
    {
        return ! $this->graph->isNotCacheable($testFileRel) && ! $this->graph->isNotCacheable($testId);
    }

    /** `'attribute'` when the graph marks the file or the test id as non-cacheable, else null. */
    public function reason(string $testFileRel, string $testId): ?string
    {
        return $this->cacheable($testFileRel, $testId) ? null : 'attribute';
    }

    /**
     * Test files that contain at least one non-cacheable test, and therefore always
     * belong in the run list.
     *
     * @param list<string> $allTestFiles project-relative test files
     * @param array<string, list<string>> $resultsByFile project-relative test file => test ids recorded for it
     * @return list<string> project-relative, sorted
     */
    public function nonCacheableFiles(array $allTestFiles, array $resultsByFile): array
    {
        $files = [];

        foreach ($allTestFiles as $testFile) {
            if ($this->graph->isNotCacheable($testFile)) {
                $files[$testFile] = true;
            }
        }

        foreach ($resultsByFile as $testFile => $testIds) {
            if (isset($files[$testFile])) {
                continue;
            }

            foreach ($testIds as $testId) {
                if ($this->graph->isNotCacheable($testId)) {
                    $files[$testFile] = true;

                    break;
                }
            }
        }

        $out = array_keys($files);
        sort($out);

        return $out;
    }
}
