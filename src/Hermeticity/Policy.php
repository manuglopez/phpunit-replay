<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Hermeticity;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Support\Glob;

/**
 * Whether a given test may be replayed from cache at all (SPEC.md §8): the `#[NotCacheable]`
 * attribute (persisted in the graph's `not_cacheable` section), the `never_cache` glob
 * config, and automatic quarantine. Rule 4 (the optional heuristics) is not implemented:
 * SPEC §8 keeps it advisory only ("no se descachea automáticamente").
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
        return $this->reason($testFileRel, $testId) === null;
    }

    /** `'attribute'` | `'never_cache'` | `'quarantine'` | `null` (cacheable). */
    public function reason(string $testFileRel, string $testId): ?string
    {
        if ($this->graph->isNotCacheable($testFileRel) || $this->graph->isNotCacheable($testId)) {
            return 'attribute';
        }

        if ($this->matchesNeverCache($testFileRel)) {
            return 'never_cache';
        }

        if ($this->quarantine->isQuarantined($testId)) {
            return 'quarantine';
        }

        return null;
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
            if (! $this->cacheable($testFile, $testFile)) {
                $files[$testFile] = true;
            }
        }

        foreach ($resultsByFile as $testFile => $testIds) {
            if (isset($files[$testFile])) {
                continue;
            }

            foreach ($testIds as $testId) {
                if (! $this->cacheable($testFile, $testId)) {
                    $files[$testFile] = true;

                    break;
                }
            }
        }

        $out = array_keys($files);
        sort($out);

        return $out;
    }

    private function matchesNeverCache(string $testFileRel): bool
    {
        foreach ($this->config->neverCache as $pattern) {
            if (Glob::matches($pattern, $testFileRel)) {
                return true;
            }
        }

        return false;
    }
}
