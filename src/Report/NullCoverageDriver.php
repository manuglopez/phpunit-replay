<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;

/**
 * A `Driver` that never records anything, used to build an EMPTY `CodeCoverage` (SPEC.md
 * §3.2 last paragraph, docs/INTERNALS.md "CoverageMerger") when the run list is empty and no
 * PHPUnit process was even launched.
 *
 * {@see CoverageMerger::writeEmptyRun()} used to obtain a driver via
 * `SebastianBergmann\CodeCoverage\Driver\Selector::forLineCoverage()`, which probes the
 * CURRENT process's own runtime (`SebastianBergmann\Environment\Runtime::hasPCOV()` /
 * `hasXdebug()`) for a coverage extension that is both loaded AND enabled right now. That
 * requirement has nothing to do with whether a coverage driver is available at all: the
 * wrapper is a plain CLI script most users invoke as `vendor/bin/phpunit-replay` with a bare
 * `php`, never with `-d pcov.enabled=1` for the wrapper process itself (that flag is only
 * ever added to the CHILD PhpunitProcess it launches). With no run needed and therefore no
 * child process, `Selector::forLineCoverage()` throws in the wrapper and
 * `--coverage-php=…` silently produces no file. Since the coverage this path writes is
 * empty by construction anyway (`start()`/`stop()` never touch a source file — every real
 * line gets merged in afterwards from the per-test-file snapshots), no driver is needed at
 * all, real or otherwise; this one exists purely to satisfy `CodeCoverage`'s constructor.
 */
final class NullCoverageDriver extends Driver
{
    public function nameAndVersion(): string
    {
        return 'phpunit-replay-null';
    }

    public function start(): void
    {
    }

    public function stop(): RawCodeCoverageData
    {
        return RawCodeCoverageData::fromXdebugWithoutPathCoverage([]);
    }
}
