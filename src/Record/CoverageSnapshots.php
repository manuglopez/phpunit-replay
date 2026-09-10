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
 *
 * Collection ({@see self::collect()}, docs/proposals/remote-layout.md §5): a snapshot's key
 * is a DIFFERENT space from {@see \Manuglopez\Replay\Cache\Remote\ObjectStore}'s mirror
 * (`Cache\ContentKey`, stored in `graph.json`) — it is never stored anywhere, so there is no
 * set to read; {@see self::addressableKeys()} instead reproduces it fresh, per known test
 * file, exactly as {@see self::capture()} did originally.
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
        return $this->root() . '/' . $key . '.cov';
    }

    /**
     * The snapshot key of every test file `$testFiles` names, recomputed fresh from disk —
     * NOT {@see \Manuglopez\Replay\Cache\ContentKey}, which is a different key space keyed
     * into `graph.json` itself (this class's own docblock): a snapshot's key is never stored
     * anywhere, so the only way to know which keys are still live is to hash the test files
     * on disk exactly as {@see self::capture()} did, and exactly as
     * {@see \Manuglopez\Replay\Console\Runner\RunPipeline::finalizeCoveragePhp()} already does
     * to find a snapshot back. A file whose content changed hashes to a different key than
     * whatever snapshot it used to have — that old snapshot can never be addressed again,
     * permanently, not merely until the next record — and a file that no longer exists
     * contributes no key at all ({@see \Manuglopez\Replay\Cache\ContentHash::of()} returns
     * null for it), which is exactly the same "provably unreachable" verdict.
     *
     * @param list<string> $testFiles project-relative
     * @return list<string>
     */
    public static function addressableKeys(array $testFiles, string $projectRoot): array
    {
        $keys = [];

        foreach ($testFiles as $relative) {
            $key = ContentHash::of(Paths::join($projectRoot, $relative));

            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * The snapshot store's state against `$reachable`, without changing anything on disk —
     * what {@see self::collect()} would do (docs/proposals/remote-layout.md §5).
     *
     * @param array<string, true> $reachable snapshot keys {@see self::addressableKeys()} produced
     * @return array{snapshots: int, reachable: int, reclaimableBytes: int}
     */
    public function stats(array $reachable): array
    {
        $reachableCount = 0;
        $reclaimable = 0;
        $entries = $this->scan($reachable);

        foreach ($entries as $entry) {
            if ($entry['reachable']) {
                $reachableCount++;
            } else {
                $reclaimable += $entry['bytes'];
            }
        }

        return [
            'snapshots' => count($entries),
            'reachable' => $reachableCount,
            'reclaimableBytes' => $reclaimable,
        ];
    }

    /**
     * Unlinks every snapshot `$reachable` no longer addresses. Unlike the remote object
     * mirror ({@see \Manuglopez\Replay\Cache\Remote\ObjectStore::collectMirror()}), a snapshot
     * carries no "already published" marker to preserve — it is never published anywhere,
     * only ever read back locally — so there is no reason to truncate rather than unlink.
     *
     * @param array<string, true> $reachable snapshot keys {@see self::addressableKeys()} produced
     * @return array{snapshots: int, reachable: int, evicted: int, reclaimedBytes: int}
     */
    public function collect(array $reachable): array
    {
        $reachableCount = 0;
        $evicted = 0;
        $reclaimed = 0;
        $entries = $this->scan($reachable);

        foreach ($entries as $entry) {
            if ($entry['reachable']) {
                $reachableCount++;

                continue;
            }

            if (@unlink($entry['path'])) {
                $evicted++;
                $reclaimed += $entry['bytes'];
            }
        }

        return [
            'snapshots' => count($entries),
            'reachable' => $reachableCount,
            'evicted' => $evicted,
            'reclaimedBytes' => $reclaimed,
        ];
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
     * Every snapshot currently on disk, classified against `$reachable`.
     *
     * @param array<string, true> $reachable
     * @return list<array{key: string, path: string, bytes: int, reachable: bool}>
     */
    private function scan(array $reachable): array
    {
        $entries = [];

        foreach (glob($this->root() . '/*.cov') ?: [] as $path) {
            $key = basename($path, '.cov');
            $bytes = @filesize($path);

            $entries[] = [
                'key' => $key,
                'path' => $path,
                'bytes' => $bytes !== false ? $bytes : 0,
                'reachable' => isset($reachable[$key]),
            ];
        }

        return $entries;
    }

    /** `<stateDir>/coverage` — the one flat directory {@see self::path()} keys into. */
    private function root(): string
    {
        return rtrim($this->stateDir, '/') . '/coverage';
    }
}
