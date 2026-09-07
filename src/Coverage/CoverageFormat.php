<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Coverage;

use ReflectionClass;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Version;
use Throwable;

/**
 * Runtime capability detection for the installed `phpunit/php-code-coverage`, and the single
 * place that decides which {@see CoverageArchive} reads and writes `--coverage-php` files.
 *
 * The three things that move underneath this package:
 *
 * 1. **How a `--coverage-php` file is written.** Up to php-code-coverage 13 it was
 *    `SebastianBergmann\CodeCoverage\Report\PHP`: `<?php return unserialize(<<<'…')` around a
 *    serialized `CodeCoverage` object. 14.0 removed that class and replaced it with
 *    `Serialization\Serializer`, which writes a serialized ARRAY (build information, a base
 *    path, a `ProcessedCodeCoverageData` and the test results) behind a first-line format
 *    marker. The two are mutually unreadable.
 * 2. **How that file says which format it is.** The 14 line has already used two first-line
 *    markers and three format identities: 14.0 and 14.1 stamped their own EXACT version
 *    (`// phpunit/php-code-coverage version 14.0.2`) and refused any other; 14.2 replaced that
 *    with a format NUMBER (`// … serialization format 1`); 14.3 moved the number to 3. Each of
 *    those refuses to read the others, so this will move again.
 * 3. **The shape of the coverage data itself** ({@see LineHits}).
 *
 * Nothing here trusts a version constraint for any of that: the marker is read off the file
 * ({@see self::markerOf()}) and compared with the one this installation writes
 * ({@see self::ownMarker()}), and the capabilities are read off the installed classes.
 *
 * ## Why the 14-only surface is reached by name
 *
 * `Serialization\Serializer` and `Serialization\Unserializer` do not exist on php-code-coverage
 * 11, 12 or 13, and `ProcessedCodeCoverageData::testIds()` / `setTestIds()` /
 * `collectsHitCounts()` do not exist before 14.3. Naming any of them directly makes PHPStan
 * report an unknown class or an undefined method in whichever cell does not have it, and
 * neither a guard nor a stub file fixes that: PHPStan resolves a literal `class_exists()` /
 * `method_exists()` itself and then calls one of the two branches dead, and `stubFiles` can
 * only refine the PHPDoc of symbols that already exist — it can neither introduce a missing
 * class nor add a missing method (both verified against phpstan 2.x here).
 *
 * So the optional surface is reached through {@see self::instantiate()},
 * {@see self::constantValue()}, {@see self::call()} and {@see self::supports()}, which take the
 * class and method names as plain, non-literal `string` parameters. That is the same device —
 * and the same reasoning — already documented at length on
 * {@see \Manuglopez\Replay\PHPUnit\ConfigurationReader}, which dispatches PHPUnit's own
 * version-dependent configuration accessors this way. The cost is that these particular calls
 * are unchecked; keeping every one of them behind this one class is what bounds the damage,
 * and `tests/Unit/Coverage` exercises them against whichever version is installed.
 *
 * @phpstan-import-type TestType from CodeCoverage
 */
final class CoverageFormat
{
    /**
     * The on-disk shape of THIS package's own `<stateDir>/coverage/<k>.cov` snapshots
     * ({@see Snapshot}). 1 was a raw `serialize()` of a php-code-coverage `CodeCoverage`
     * object, and was therefore only readable by the major that wrote it; 2 is the
     * shape-independent JSON {@see Snapshot} writes. Part of {@see self::id()}, so a bump
     * invalidates every cached result recorded under the previous shape.
     */
    public const SNAPSHOT_FORMAT = 2;

    /** php-code-coverage >= 14 only. Never imported — see the class docblock. */
    private const SERIALIZER = 'SebastianBergmann\CodeCoverage\Serialization\Serializer';

    /** php-code-coverage >= 14 only. Never imported — see the class docblock. */
    private const UNSERIALIZER = 'SebastianBergmann\CodeCoverage\Serialization\Unserializer';

    private const MARKER_FORMAT = 'fmt';

    private const MARKER_VERSION = 'ver';

    private const UNKNOWN_MAJOR = '?';

    /** Which reader/writer pair matches the installed php-code-coverage. */
    public static function archive(): CoverageArchive
    {
        return self::hasClass(self::SERIALIZER) && self::hasClass(self::UNSERIALIZER)
            ? new SerializedArchive()
            : new LegacyArchive();
    }

    /** A `Serialization\Serializer`, or null on php-code-coverage 11-13, which has none. */
    public static function newSerializer(): ?object
    {
        return self::instantiate(self::SERIALIZER);
    }

    /** A `Serialization\Unserializer`, or null on php-code-coverage 11-13, which has none. */
    public static function newUnserializer(): ?object
    {
        return self::instantiate(self::UNSERIALIZER);
    }

    /**
     * The `--coverage-php` serialization format the installed php-code-coverage writes AND is
     * willing to read, or null when it still writes the pre-14 `Report\PHP` shape (which
     * carries no format marker at all).
     */
    public static function serializationFormat(): ?int
    {
        $format = self::constantValue(self::SERIALIZER, 'SERIALIZATION_FORMAT');

        return is_int($format) ? $format : null;
    }

    /**
     * The serialization format NUMBER the `--coverage-php` file at `$path` declares on its
     * first line, or null when it declares none: a pre-14 file (no marker at all), a 14.0/14.1
     * file (which stamps its exact version instead), or anything unreadable.
     */
    public static function declaredFormat(string $path): ?int
    {
        $marker = self::markerOf($path);

        return $marker !== null && str_starts_with($marker, self::MARKER_FORMAT)
            ? (int) substr($marker, strlen(self::MARKER_FORMAT))
            : null;
    }

    /**
     * Whether the `--coverage-php` file at `$path` is in a format the installed
     * php-code-coverage cannot read: a pre-14 file under 14+, a 14+ file under 13 or older, a
     * 14.0/14.1 file under a 14.2+ (or the reverse), or a format-numbered file whose number is
     * not the one this installation reads.
     *
     * Comparing the file's own first-line marker with the marker this installation writes
     * answers all of those with one equality, and does not have to be taught a list of
     * versions. Callers degrade on it rather than throwing
     * ({@see \Manuglopez\Replay\Report\CoverageMerger::merge()}).
     */
    public static function isForeign(string $path): bool
    {
        return self::markerOf($path) !== self::ownMarker();
    }

    /**
     * The `--coverage-php` first-line marker of `$path`, normalised to `fmt<n>` or
     * `ver<version>`, or null when the file carries none (the pre-14 `Report\PHP` shape, an
     * empty file, or one that cannot be opened — none of which any 14 can read either).
     */
    public static function markerOf(string $path): ?string
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        $firstLine = fgets($handle, 512);
        fclose($handle);

        if ($firstLine === false) {
            return null;
        }

        $firstLine = trim($firstLine);

        if (preg_match('#^<\?php // phpunit/php-code-coverage serialization format (\d+)$#', $firstLine, $matches) === 1) {
            return self::MARKER_FORMAT . $matches[1];
        }

        if (preg_match('#^<\?php // phpunit/php-code-coverage version (.+)$#', $firstLine, $matches) === 1) {
            return self::MARKER_VERSION . $matches[1];
        }

        return null;
    }

    /**
     * The marker the installed php-code-coverage writes AND is willing to read back: the
     * format number from 14.2 on, its own exact version on 14.0/14.1 (which is what their
     * `Unserializer` compares), and null on 11-13, whose `Report\PHP` wrote no marker at all.
     */
    public static function ownMarker(): ?string
    {
        $format = self::serializationFormat();

        if ($format !== null) {
            return self::MARKER_FORMAT . $format;
        }

        return self::hasClass(self::SERIALIZER) ? self::MARKER_VERSION . self::version() : null;
    }

    /**
     * The cache-invalidation token for `Cache\Fingerprint`'s `environmental` bucket: results
     * recorded while one coverage format was installed cannot be replayed under another, and
     * without this nothing noticed. The php-code-coverage MAJOR (not the exact version — a
     * patch release must not throw away a cache), the serialization format number, and this
     * package's own snapshot shape are exactly the three boundaries across which a cached
     * result stops being readable. `ser` stands for the 14.0/14.1 pair, which has a
     * `Serialization\Serializer` but no format number of its own; `php` for the pre-14
     * `Report\PHP`. The exact 14.0/14.1 version is deliberately NOT part of the token even
     * though those two refuse to read each other's files: what this token guards is the
     * readability of the SNAPSHOTS, which {@see Snapshot} keeps version-neutral, and a run's
     * own `--coverage-php` file is always written and read inside one run.
     */
    public static function id(): string
    {
        $format = self::serializationFormat();

        return sprintf(
            'cc%s/%s/snap%d',
            self::major(),
            $format !== null
                ? self::MARKER_FORMAT . $format
                : (self::hasClass(self::SERIALIZER) ? 'ser' : 'php'),
            self::SNAPSHOT_FORMAT,
        );
    }

    /**
     * Whether the installed php-code-coverage interns test ids: 14.3 replaced the per-line
     * `list<test id>` with an `array<test index, hit count>` plus a `testIds()` index table
     * ({@see LineHits}). Detected off the class, since nothing in the version number says so.
     */
    public static function usesTestIndexes(): bool
    {
        return self::supports(ProcessedCodeCoverageData::class, 'testIds');
    }

    /**
     * A `ProcessedCodeCoverageData` that agrees with `$collectsHitCounts` where the installed
     * php-code-coverage records hit counts at all.
     *
     * 14.3 added the constructor parameter and merges the flag with `&&`, so a snapshot built
     * with the default `false` would silently downgrade a whole xdebug run's exact per-line
     * execution counts to "executed at least once". Instantiated through reflection because
     * every earlier major's constructor takes no arguments.
     */
    public static function newProcessedData(bool $collectsHitCounts): ProcessedCodeCoverageData
    {
        $reflection = new ReflectionClass(ProcessedCodeCoverageData::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new ProcessedCodeCoverageData();
        }

        return $reflection->newInstance($collectsHitCounts);
    }

    /**
     * Installs a line-coverage map produced by {@see LineHits::toLineCoverage()}, plus the
     * index => id table it comes with (null where the installed php-code-coverage has none).
     *
     * Both setters go through the name-based dispatch above. `setTestIds()` has to, because it
     * only exists from 14.3 on; `setLineCoverage()` does because its PARAMETER TYPE is
     * version-dependent in exactly the way {@see LineHits} describes — `list<test id>` per line
     * up to 14.2, `array<test index, hit count>` from 14.3, and no annotation at all on the
     * version paired with PHPUnit 11.5 — so no single value this package builds is assignable
     * to it in every cell, however correct it is at runtime.
     *
     * @param array<string, array<int, mixed>> $lineCoverage in the installed representation
     * @param array<int, non-empty-string>|null $testIds
     */
    public static function installLineCoverage(ProcessedCodeCoverageData $data, array $lineCoverage, ?array $testIds): void
    {
        self::call($data, 'setLineCoverage', $lineCoverage);

        if ($testIds !== null) {
            self::call($data, 'setTestIds', $testIds);
        }
    }

    /**
     * Whether `$data` carries exact per-line execution counts. False on every major before
     * 14.3, and false rather than fatal for a `ProcessedCodeCoverageData` restored from
     * another major's serialized file, whose typed `$collectsHitCounts` property is then never
     * initialised (reading it raises an `Error`).
     */
    public static function collectsHitCounts(ProcessedCodeCoverageData $data): bool
    {
        try {
            return self::call($data, 'collectsHitCounts') === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The `CodeCoverage::getTests()` / `setTests()` entry shape is version-dependent — the
     * php-code-coverage paired with PHPUnit 11.5 has no `time` key, every later one does — so
     * no literal this package could build would satisfy `setTests()` in every supported cell.
     * Entries are never destructured here (only stored, filtered and handed back), so a map
     * restored from a snapshot or from a serialized `--coverage-php` file is validated for
     * what actually matters at runtime — a non-empty string id mapping to an array — and
     * asserted into the installed shape for analysis.
     *
     * A snapshot written under a different php-code-coverage major is never read back:
     * {@see self::id()} is part of `Cache\Fingerprint`'s `environmental` bucket, so crossing
     * that boundary clears the cached results first.
     *
     * @param array<mixed> $tests
     * @return array<non-empty-string, TestType>
     */
    public static function testsFrom(array $tests): array
    {
        $out = [];

        foreach ($tests as $id => $entry) {
            if (! is_string($id) || $id === '' || ! is_array($entry)) {
                continue;
            }

            /** @var TestType $entry */
            $out[$id] = $entry;
        }

        return $out;
    }

    /**
     * Calls `$method` on `$object` when it has one, otherwise returns null. The method name
     * arrives as a plain `string` on purpose — see the class docblock.
     */
    public static function call(object $object, string $method, mixed ...$arguments): mixed
    {
        if (! method_exists($object, $method)) {
            return null;
        }

        return $object->{$method}(...$arguments);
    }

    /** `method_exists()` behind a non-literal parameter — see the class docblock. */
    private static function supports(object|string $objectOrClass, string $method): bool
    {
        return method_exists($objectOrClass, $method);
    }

    /** `class_exists()` behind a non-literal parameter — see the class docblock. */
    private static function hasClass(string $class): bool
    {
        return class_exists($class);
    }

    private static function instantiate(string $class): ?object
    {
        if (! self::hasClass($class)) {
            return null;
        }

        try {
            return new $class();
        } catch (Throwable) {
            return null;
        }
    }

    private static function constantValue(string $class, string $name): mixed
    {
        $qualified = $class . '::' . $name;

        return defined($qualified) ? constant($qualified) : null;
    }

    private static function major(): string
    {
        if (preg_match('/^(\d+)/', self::version(), $matches) !== 1) {
            return self::UNKNOWN_MAJOR;
        }

        return $matches[1];
    }

    private static function version(): string
    {
        try {
            return Version::id();
        } catch (Throwable) {
            return self::UNKNOWN_MAJOR;
        }
    }
}
