<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;

/**
 * A `Driver` that never records anything, used to build a `CodeCoverage` whose data comes from
 * somewhere other than a live coverage extension (SPEC.md §3.2 last paragraph,
 * docs/INTERNALS.md "CoverageMerger"): the EMPTY coverage of a pass where the run list was
 * empty and no PHPUnit process was even launched, the `CodeCoverage` rebuilt around a stored
 * snapshot ({@see \Manuglopez\Replay\Coverage\Snapshot::toCoverage()}), and the one rebuilt
 * around a php-code-coverage 14 `--coverage-php` file, whose serialized form no longer
 * contains a `CodeCoverage` object at all
 * ({@see \Manuglopez\Replay\Coverage\SerializedArchive}).
 *
 * All three would otherwise need `SebastianBergmann\CodeCoverage\Driver\Selector::
 * forLineCoverage()`, which probes the CURRENT process's own runtime
 * (`SebastianBergmann\Environment\Runtime::hasPCOV()` / `hasXdebug()`) for a coverage
 * extension that is both loaded AND enabled right now. That requirement has nothing to do with
 * whether a coverage driver is available at all: the wrapper is a plain CLI script most users
 * invoke as `vendor/bin/phpunit-replay` with a bare `php`, never with `-d pcov.enabled=1` for
 * the wrapper process itself (that flag is only ever added to the CHILD PhpunitProcess it
 * launches). With no run needed and therefore no child process,
 * `Selector::forLineCoverage()` throws in the wrapper and `--coverage-php=…` silently produces
 * no file. Since none of these paths ever collects a line — `start()`/`stop()` never touch a
 * source file, every real line arrives from data that already exists — no driver is needed at
 * all, real or otherwise; this one exists purely to satisfy `CodeCoverage`'s constructor.
 *
 * ## Satisfying four php-code-coverage majors at once
 *
 * `Driver`'s abstract surface changed in php-code-coverage 14: `nameAndVersion()` was abstract
 * up to 13 and is concrete from 14 on, where the two abstract methods are `name()` and
 * `version()` instead. Declaring all three satisfies both contracts — the pair are simply
 * extra methods on 11-13, and overriding a concrete `nameAndVersion()` is legal on 14 — and
 * `nameAndVersion()` is overridden rather than inherited so this driver reports the same
 * string on every major.
 *
 * `stop()` returns `RawCodeCoverageData::fromXdebugWithPathCoverage([])`, the ONLY factory on
 * that class present in all four: `fromXdebugWithoutPathCoverage()` (what this used to call)
 * was removed in 14.3, and its replacement `fromLineCoverage()` was only added there. For an
 * empty array both take the identical `new self([], [])` path.
 *
 * The name and version are constructor parameters so that
 * {@see \Manuglopez\Replay\Coverage\SerializedArchive} can carry the driver information
 * recorded in a `--coverage-php` file forward into the merged file it writes: php-code-coverage
 * 14 reads `CodeCoverage::driverInformation()` at serialization time, and its own
 * `Serialization\Merger` refuses to merge files whose driver information disagrees — so a
 * merged report has to keep naming the driver that actually collected the data.
 */
final class NullCoverageDriver extends Driver
{
    private const DEFAULT_NAME = 'phpunit-replay-null';

    private const DEFAULT_VERSION = '0';

    /** @var non-empty-string */
    private readonly string $name;

    /** @var non-empty-string */
    private readonly string $version;

    /**
     * An empty name or version falls back to the default: php-code-coverage 14 validates both
     * as non-empty when it reads a serialized coverage file back, so a file that recorded
     * neither must not make this package write one it could not read again.
     */
    public function __construct(string $name = self::DEFAULT_NAME, string $version = self::DEFAULT_VERSION)
    {
        $this->name = $name !== '' ? $name : self::DEFAULT_NAME;
        $this->version = $version !== '' ? $version : self::DEFAULT_VERSION;
    }

    /** @return non-empty-string */
    public function name(): string
    {
        return $this->name;
    }

    /** @return non-empty-string */
    public function version(): string
    {
        return $this->version;
    }

    public function nameAndVersion(): string
    {
        return $this->name . ' ' . $this->version;
    }

    public function start(): void
    {
    }

    public function stop(): RawCodeCoverageData
    {
        return RawCodeCoverageData::fromXdebugWithPathCoverage([]);
    }
}
