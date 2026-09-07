<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Coverage;

use Manuglopez\Replay\Report\NullCoverageDriver;
use Manuglopez\Replay\Support\Paths;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Filter;
use Throwable;

/**
 * The `--coverage-php` shape of php-code-coverage 14 and later
 * (`SebastianBergmann\CodeCoverage\Serialization\Serializer`), which replaced the removed
 * `Report\PHP`. Both classes are reached through {@see CoverageFormat} rather than imported,
 * because they do not exist on 11-13 — the reasoning is on that class.
 *
 * Two differences matter to this package:
 *
 * **It is an array, not an object.** `Unserializer::unserialize()` returns
 * `array{buildInformation: …, basePath: string, codeCoverage: ProcessedCodeCoverageData,
 * testResults: array<string, array{size: string, status: string, time: float}>}` — the
 * `CodeCoverage` object itself is gone from the file. Since `CodeCoverage::merge()` is still
 * how coverage is folded together, {@see self::read()} rebuilds a `CodeCoverage` around the
 * `ProcessedCodeCoverageData` and the test results, with a {@see NullCoverageDriver} carrying
 * the original file's recorded driver name and version forward so `driverInformation()` in the
 * file this package writes back still names the driver that actually collected the data
 * (php-code-coverage's own `Serialization\Merger` refuses to merge files whose driver
 * information disagrees).
 *
 * **The paths are relative.** `Serializer` runs `Util\PathReducer` over a clone of the data
 * before writing: the longest common directory prefix is cut off every covered file and stored
 * once as `basePath` (upstream issue #925). Everything in this package — the graph, the
 * snapshots, `Filter`, every assertion in the test suite — speaks absolute paths, and
 * `CodeCoverage::merge()` matches coverage data by file key, so merging an absolute-path
 * snapshot into relative-path run data would silently produce two entries for one file instead
 * of one. {@see self::read()} therefore re-expands every relative key back to
 * `basePath . DIRECTORY_SEPARATOR . <key>` on the way in, and {@see self::write()} lets
 * `Serializer` reduce them again on the way out — so callers never see the relative form and
 * the file on disk stays exactly the shape PHPUnit itself would have written. (`phar://`
 * entries, which `PathReducer` also rewrites, are not reconstructible from `basePath` alone;
 * this package is a Composer library and is never loaded from a PHAR.)
 */
final class SerializedArchive implements CoverageArchive
{
    public function read(string $path): ?CodeCoverage
    {
        $unserializer = CoverageFormat::newUnserializer();

        if ($unserializer === null || $path === '' || ! is_file($path)) {
            return null;
        }

        try {
            $data = CoverageFormat::call($unserializer, 'unserialize', $path);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $processed = $data['codeCoverage'] ?? null;

        if (! $processed instanceof ProcessedCodeCoverageData) {
            return null;
        }

        $basePath = $data['basePath'] ?? null;
        self::toAbsolutePaths($processed, is_string($basePath) ? $basePath : '');

        $tests = $data['testResults'] ?? null;

        $coverage = new CodeCoverage(self::driverFrom($data), new Filter());
        $coverage->setData($processed);
        $coverage->setTests(CoverageFormat::testsFrom(is_array($tests) ? $tests : []));

        return $coverage;
    }

    /**
     * `Serializer::serialize()` writes with `Util\Filesystem::write()`, which is not atomic;
     * a `--coverage-php` target half-written by an interrupted run would be worse than none at
     * all, so it is written to a sibling temp file and renamed over the target, matching
     * {@see \Manuglopez\Replay\Support\AtomicFile}.
     *
     * `excludeUncoveredFiles()` first because `Serializer` reads the data through
     * `CodeCoverage::getData()` (not `getData(true)`), which would otherwise run static
     * analysis over every file still in the `Filter` and add "not executed" entries for them.
     * The pre-14 `Report\PHP` path serialized the object directly and never did that, and
     * whatever uncovered files PHPUnit itself wanted are already in the data this package read
     * back — so excluding them keeps the output identical across majors.
     *
     * Git information is deliberately NOT included: a merged file is coverage collected across
     * several runs at (potentially) several commits, so stamping it with the working tree's
     * current commit would assert something untrue about it.
     */
    public function write(string $path, CodeCoverage $coverage): bool
    {
        $serializer = CoverageFormat::newSerializer();

        if ($serializer === null || $path === '') {
            return false;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            return false;
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            $coverage->excludeUncoveredFiles();
            $coverage->clearCache();

            CoverageFormat::call($serializer, 'serialize', $tmp, $coverage, false);
        } catch (Throwable) {
            @unlink($tmp);

            return false;
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * An empty `$basePath` means the filesystem root: `PathReducer::reduce()` builds the common
     * prefix as `/`, then returns it through `substr($commonPath, 0, -1)`, so coverage spanning
     * two top-level directories (a project under `/home` plus, say, a file under `/usr`) comes
     * back with relative keys and an empty base. Files left absolute because they share no
     * prefix at all (`C:` and `D:` on Windows) are skipped by the `isAbsolute()` guard rather
     * than prefixed twice.
     */
    private static function toAbsolutePaths(ProcessedCodeCoverageData $processed, string $basePath): void
    {
        $prefix = $basePath === ''
            ? DIRECTORY_SEPARATOR
            : rtrim($basePath, '/' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // `coveredFiles()` carries no return annotation on the php-code-coverage paired with
        // PHPUnit 11.5, so its entries are validated rather than assumed to be strings.
        foreach ($processed->coveredFiles() as $file) {
            if (! is_string($file) || $file === '' || Paths::isAbsolute($file)) {
                continue;
            }

            $processed->renameFile($file, $prefix . $file);
        }
    }

    /**
     * @param array<mixed> $data as returned by `Unserializer::unserialize()`
     */
    private static function driverFrom(array $data): NullCoverageDriver
    {
        $build = $data['buildInformation'] ?? null;
        $coverage = is_array($build) ? ($build['phpCodeCoverage'] ?? null) : null;
        $driver = is_array($coverage) ? ($coverage['driverInformation'] ?? null) : null;

        if (! is_array($driver)) {
            return new NullCoverageDriver();
        }

        $name = $driver['name'] ?? null;
        $version = $driver['version'] ?? null;

        return new NullCoverageDriver(
            is_string($name) ? $name : '',
            is_string($version) ? $version : '',
        );
    }
}
