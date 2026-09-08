<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

use Manuglopez\Replay\Console\Runner\Warnings;
use Manuglopez\Replay\Coverage\CoverageFormat;
use Manuglopez\Replay\Coverage\Snapshot;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\Support\AtomicFile;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use Throwable;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/CoverageMerger.php
 *
 * SPEC.md §3.2 last paragraph, docs/INTERNALS.md "CoverageMerger": folds the per-test-file
 * coverage snapshots {@see \Manuglopez\Replay\Record\CoverageSnapshots} wrote at record time
 * for every REPLAYED test file back into the `CodeCoverage` this pass's own PHPUnit run
 * produced, so `--coverage-php` does not silently lose the coverage of test files TIA
 * skipped re-running. Only `.php` coverage is supported — HTML/Clover/etc. reports are
 * produced by the user from the merged file afterwards, same as from any other
 * `--coverage-php` output (this is a narrower mechanism than Pest's own CoverageMerger,
 * which keeps one cumulative CodeCoverage cached across runs and strips/re-merges test ids
 * on each pass; this package instead keeps one small immutable snapshot per test file,
 * content-addressed, and merges only the ones this pass actually needs).
 *
 * Reading and writing the `--coverage-php` file itself is delegated to a
 * {@see \Manuglopez\Replay\Coverage\CoverageArchive}, because that format is not stable:
 * php-code-coverage 14 removed `Report\PHP` and replaced it with a `Serialization\Serializer`
 * whose output no earlier version can read, whose own format number has already moved twice,
 * and whose file paths are relative rather than absolute. Everything below happens in the
 * absolute-path, `CodeCoverage`-shaped world the archive presents; nothing here knows which
 * format is on disk.
 *
 * Every failure degrades with a warning instead of throwing (docs/INTERNALS.md): a snapshot
 * this installation cannot read is skipped and counted, and a run coverage file in a format
 * the installed php-code-coverage cannot read is copied through to the user's target
 * unchanged — PHPUnit's own coverage of what actually ran, minus the replayed files, beats no
 * coverage file at all.
 */
final class CoverageMerger
{
    /**
     * @param list<string> $snapshotPaths absolute paths to `<stateDir>/coverage/<k>.cov` files
     */
    public static function merge(string $runCoveragePhp, array $snapshotPaths, string $output): bool
    {
        if (! is_file($runCoveragePhp)) {
            return false;
        }

        if (CoverageFormat::isForeign($runCoveragePhp)) {
            Warnings::warn(sprintf(
                'coverage: %s is in a --coverage-php format the installed php-code-coverage cannot read; '
                . 'copying it to %s unmerged (the coverage of replayed test files is missing from it)',
                $runCoveragePhp,
                $output,
            ));

            return self::copy($runCoveragePhp, $output);
        }

        $archive = CoverageFormat::archive();
        $coverage = $archive->read($runCoveragePhp);

        if ($coverage === null) {
            return false;
        }

        $collectsHitCounts = CoverageFormat::collectsHitCounts($coverage->getData(true));
        $skipped = 0;

        foreach ($snapshotPaths as $path) {
            $snapshot = self::loadSnapshot($path, $collectsHitCounts);

            if ($snapshot === null) {
                $skipped++;

                continue;
            }

            try {
                $coverage->merge($snapshot);
            } catch (Throwable) {
                $skipped++;
            }
        }

        if ($skipped > 0) {
            Warnings::warn(sprintf(
                'coverage: %d replayed test file(s) had no readable coverage snapshot; their lines are missing from %s',
                $skipped,
                $output,
            ));
        }

        return $archive->write($output, $coverage);
    }

    /**
     * Builds and writes an empty `CodeCoverage`, scoped to the configuration's `<source>`
     * directories, in the same on-disk shape the installed php-code-coverage's own
     * `--coverage-php` produces: used when nothing executed this pass (the run list is empty,
     * no PHPUnit process was even launched) so {@see self::merge()} still has a run coverage
     * to fold the snapshots into.
     *
     * Uses {@see NullCoverageDriver} rather than
     * `SebastianBergmann\CodeCoverage\Driver\Selector::forLineCoverage()`: the coverage built
     * here is empty by construction (nothing runs, every real line is merged in afterwards
     * from the per-test-file snapshots), so no real driver is needed — and `Selector`
     * requires pcov/xdebug to be LOADED AND ENABLED in the WRAPPER's own process, which for
     * most users runs as plain `php vendor/bin/phpunit-replay` (the `-d pcov.enabled=1` flag
     * is only ever added to the child PhpunitProcess it launches).
     */
    public static function writeEmptyRun(string $path, ConfigurationReader $reader): bool
    {
        $filter = $reader->emptyCoverageFilter();

        return CoverageFormat::archive()->write($path, new CodeCoverage(new NullCoverageDriver(), $filter));
    }

    /**
     * A snapshot written by {@see \Manuglopez\Replay\Record\CoverageSnapshots}:
     * shape-independent JSON, rebuilt into whatever the installed php-code-coverage
     * understands. null for a missing, truncated, hand-edited or older-format snapshot, which
     * the caller counts and reports rather than failing the run over.
     */
    private static function loadSnapshot(string $path, bool $collectsHitCounts): ?CodeCoverage
    {
        $content = AtomicFile::read($path);

        if ($content === null) {
            return null;
        }

        $snapshot = Snapshot::decode($content);

        if ($snapshot === null) {
            return null;
        }

        try {
            return $snapshot->toCoverage($collectsHitCounts);
        } catch (Throwable) {
            return null;
        }
    }

    /** Atomically, via {@see AtomicFile}: the target is the path the user asked for. */
    private static function copy(string $from, string $to): bool
    {
        $content = AtomicFile::read($from);

        return $content !== null && AtomicFile::write($to, $content);
    }
}
