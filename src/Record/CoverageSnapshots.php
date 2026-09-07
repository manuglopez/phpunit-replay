<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

use Manuglopez\Replay\Cache\ContentHash;
use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Paths;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use Throwable;

/**
 * SPEC.md §3.2 last paragraph, docs/INTERNALS.md "CoverageMerger": at record time, when the
 * user asked PHPUnit for `--coverage-php`, every test file's own slice of PHPUnit's coverage
 * data is serialized independently and written to `<stateDir>/coverage/<k>.cov` — `k` being
 * {@see \Manuglopez\Replay\Cache\ContentHash::of()} of the test file itself, computable from
 * disk alone (unlike `Cache\ContentKey`, which additionally needs a `Graph` for the file's
 * dependencies — not available inside the PHPUnit child process in filtered/wrapper mode).
 * A later pass that replays this file from cache can still find the exact same key by
 * hashing the (by definition, for a replay to be valid, unchanged) file on disk, and fold the
 * snapshot back into its own `--coverage-php` output via {@see \Manuglopez\Replay\Report\CoverageMerger}.
 *
 * The accessor for "what did PHPUnit's own coverage collect, per test" is the same one
 * `Pest\Plugins\Tia\CoverageCollector` uses (© Nuno Maduro, MIT,
 * @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/CoverageCollector.php):
 * `PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->getData()`. Pest's version reads
 * hit test ids through a `lineCoverage()`/`testIds()` index pair (an index-based scheme this
 * installed `phpunit/php-code-coverage` — paired with PHPUnit ^12, not Pest's PHPUnit ^13 —
 * does not have: {@see ProcessedCodeCoverageData::lineCoverage()} here already returns the
 * test id strings directly per line, so no `testIds()` lookup exists or is needed).
 */
final class CoverageSnapshots
{
    public function __construct(private readonly string $stateDir)
    {
    }

    /**
     * Buckets `$results` by test file, then writes one snapshot per file containing only the
     * lines its own tests touched. Returns the map `RunWriter::flush()` persists as
     * `coverage.json` (empty when nothing could be captured — missing coverage, or every
     * candidate test file resolving outside the project root).
     *
     * @param array<string, array{status: int, message: string, time: float, assertions: int, file?: string}> $results
     * @return array<string, string> project-relative test file => snapshot key
     */
    public function capture(array $results, CodeCoverage $coverage, string $projectRoot): array
    {
        $byFile = $this->testIdsByFile($results);

        if ($byFile === []) {
            return [];
        }

        $lineCoverage = $coverage->getData(true)->lineCoverage();
        $tests = $coverage->getTests();

        $out = [];

        foreach ($byFile as $fileAbsolute => $testIds) {
            $key = ContentHash::of($fileAbsolute);

            if ($key === null) {
                continue;
            }

            $snapshot = $this->buildSnapshot($lineCoverage, $tests, $testIds);

            if ($snapshot === null) {
                continue;
            }

            if (! AtomicFile::write($this->path($key), serialize($snapshot))) {
                continue;
            }

            $relative = Paths::relative($projectRoot, $fileAbsolute);

            if ($relative !== null) {
                $out[$relative] = $key;
            }
        }

        return $out;
    }

    /** `<stateDir>/coverage/<k>.cov`. */
    public function path(string $key): string
    {
        return rtrim($this->stateDir, '/') . '/coverage/' . $key . '.cov';
    }

    /**
     * @param array<string, array{file?: string}> $results
     * @return array<string, list<string>> absolute test file => test ids
     */
    private function testIdsByFile(array $results): array
    {
        $out = [];

        foreach ($results as $testId => $result) {
            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '') {
                $out[$file][] = $testId;
            }
        }

        return $out;
    }

    /**
     * Restricts `$lineCoverage` to the lines whose hit test ids intersect `$testIds`,
     * preserving the null ("not executable") marker of every other line untouched so a
     * later merge still tells dead code apart from code nobody happened to hit. A file is
     * only included in the snapshot when at least one of its lines was actually hit by one
     * of `$testIds` — a file this test file merely autoloaded contributes nothing.
     *
     * @param array<string, array<int, null|list<string>>> $lineCoverage
     * @param array<string, array{size: string, status: string, time: float}> $tests
     * @param list<string> $testIds
     */
    private function buildSnapshot(array $lineCoverage, array $tests, array $testIds): ?CodeCoverage
    {
        $wanted = array_fill_keys($testIds, true);
        $restricted = [];

        foreach ($lineCoverage as $file => $lines) {
            $filtered = [];
            $fileHasHit = false;

            foreach ($lines as $line => $ids) {
                if ($ids === null) {
                    $filtered[$line] = null;

                    continue;
                }

                $kept = array_values(array_filter($ids, static fn (string $id): bool => isset($wanted[$id])));
                $filtered[$line] = $kept;

                if ($kept !== []) {
                    $fileHasHit = true;
                }
            }

            if ($fileHasHit) {
                $restricted[$file] = $filtered;
            }
        }

        if ($restricted === []) {
            return null;
        }

        $filter = new Filter();
        $filter->includeFiles(array_keys($restricted));

        try {
            $driver = (new Selector())->forLineCoverage($filter);
        } catch (Throwable) {
            return null;
        }

        $snapshot = new CodeCoverage($driver, $filter);
        $data = new ProcessedCodeCoverageData();
        $data->setLineCoverage($restricted);
        $snapshot->setData($data);
        $snapshot->setTests(array_intersect_key($tests, $wanted));

        return $snapshot;
    }
}
