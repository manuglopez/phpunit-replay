<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

use Manuglopez\Replay\Cache\ContentHash;
use Manuglopez\Replay\Coverage\LineHits;
use Manuglopez\Replay\Coverage\Snapshot;
use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Paths;
use SebastianBergmann\CodeCoverage\CodeCoverage;

/**
 * SPEC.md §3.2 last paragraph, docs/INTERNALS.md "CoverageMerger": at record time, when the
 * user asked PHPUnit for `--coverage-php`, every test file's own slice of PHPUnit's coverage
 * data is written to `<stateDir>/coverage/<k>.cov` — `k` being
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
 * `PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->getData()`.
 *
 * Version tolerance: what that accessor RETURNS is not the same across the php-code-coverage
 * majors this package supports (11, 12, 13, 14), and neither is the on-disk form a snapshot
 * can safely take. Neither concern is handled here — {@see LineHits} normalises the per-line
 * hit maps (11-14.2 store the test ids inline, 14.3 interns them behind an index table), and
 * {@see Snapshot} owns the file shape, which is deliberately no longer a serialized
 * php-code-coverage object. Both are documented on those classes.
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

        $data = $coverage->getData(true);
        $lineCoverage = $data->lineCoverage();
        $testIds = LineHits::testIds($data);
        $tests = $coverage->getTests();

        $out = [];

        foreach ($byFile as $fileAbsolute => $wantedIds) {
            $key = ContentHash::of($fileAbsolute);

            if ($key === null) {
                continue;
            }

            $encoded = Snapshot::restrict($lineCoverage, $testIds, $tests, $wantedIds)?->encode();

            if ($encoded === null || ! AtomicFile::write($this->path($key), $encoded)) {
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
}
