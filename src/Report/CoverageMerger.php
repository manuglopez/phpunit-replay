<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

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
 * skipped re-running. Only `.php` coverage (a serialized
 * `SebastianBergmann\CodeCoverage\CodeCoverage`) is supported — HTML/Clover/etc. reports are
 * produced by the user from the merged file afterwards, same as from any other
 * `--coverage-php` output (this is a narrower mechanism than Pest's own CoverageMerger,
 * which keeps one cumulative CodeCoverage cached across runs and strips/re-merges test ids
 * on each pass; this package instead keeps one small immutable snapshot per test file,
 * content-addressed, and merges only the ones this pass actually needs).
 */
final class CoverageMerger
{
    /**
     * @param list<string> $snapshotPaths absolute paths to `<stateDir>/coverage/<k>.cov` files
     */
    public static function merge(string $runCoveragePhp, array $snapshotPaths, string $output): bool
    {
        $coverage = self::loadRunCoverage($runCoveragePhp);

        if ($coverage === null) {
            return false;
        }

        foreach ($snapshotPaths as $path) {
            $snapshot = self::loadSnapshot($path);

            if ($snapshot === null) {
                continue;
            }

            try {
                $coverage->merge($snapshot);
            } catch (Throwable) {
                continue;
            }
        }

        return self::write($coverage, $output);
    }

    /**
     * Builds and writes an empty `CodeCoverage`, scoped to the configuration's `<source>`
     * directories, in the same on-disk shape PHPUnit's own `--coverage-php` produces
     * (`SebastianBergmann\CodeCoverage\Report\PHP::process()`): used when nothing executed
     * this pass (the run list is empty, no PHPUnit process was even launched) so
     * {@see self::merge()} still has a run coverage to fold the snapshots into.
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

        return self::write(new CodeCoverage(new NullCoverageDriver(), $filter), $path);
    }

    /** PHPUnit's own `--coverage-php` output: `<?php return unserialize(<<<'...'\n...\n...);`. */
    private static function loadRunCoverage(string $path): ?CodeCoverage
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $value = @include $path;
        } catch (Throwable) {
            return null;
        }

        return $value instanceof CodeCoverage ? $value : null;
    }

    /** A snapshot written by {@see \Manuglopez\Replay\Record\CoverageSnapshots}: raw `serialize()` bytes, never `include`d. */
    private static function loadSnapshot(string $path): ?CodeCoverage
    {
        $content = AtomicFile::read($path);

        if ($content === null) {
            return null;
        }

        try {
            $value = @unserialize($content);
        } catch (Throwable) {
            return null;
        }

        return $value instanceof CodeCoverage ? $value : null;
    }

    private static function write(CodeCoverage $coverage, string $output): bool
    {
        try {
            $coverage->clearCache();
            $serialized = serialize($coverage);
        } catch (Throwable) {
            return false;
        }

        $buffer = "<?php\n"
            . "return unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\n"
            . $serialized . "\n"
            . "END_OF_COVERAGE_SERIALIZATION\n"
            . ");\n";

        return AtomicFile::write($output, $buffer);
    }
}
