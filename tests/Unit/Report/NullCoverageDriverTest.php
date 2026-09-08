<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Report;

use Manuglopez\Replay\Report\NullCoverageDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;

/**
 * A no-op `Driver` (docs/INTERNALS.md "CoverageMerger"): `CoverageMerger::writeEmptyRun()`
 * uses it instead of `Driver\Selector::forLineCoverage()`, which requires a real coverage
 * extension loaded AND enabled in the CURRENT process — a requirement the wrapper cannot
 * meet for itself when it is invoked as plain `php vendor/bin/phpunit-replay` (only the
 * CHILD PhpunitProcess it launches gets `-d pcov.enabled=1`). Since the coverage built from
 * this driver is empty by construction — nothing runs, every real line is merged in
 * afterwards from stored snapshots — no real driver is needed at all.
 *
 * These assertions are deliberately about what the driver is FOR, not about a fixed list of
 * `Driver` methods: the base class's surface is not stable across the php-code-coverage majors
 * this package supports. This test used to assert `canCollectBranchAndPathCoverage()`,
 * `isPcov()` and `isXdebug()`, all three of which php-code-coverage 14 removed — so it pinned
 * a contract that comes and goes instead of the one that matters, which is that `CodeCoverage`
 * accepts this driver and never asks it for a line.
 */
final class NullCoverageDriverTest extends TestCase
{
    #[Test]
    public function it_is_a_driver_that_never_touches_a_real_extension(): void
    {
        $driver = new NullCoverageDriver();

        self::assertInstanceOf(Driver::class, $driver);
        self::assertSame('phpunit-replay-null', $driver->name());
        self::assertSame('0', $driver->version());
        self::assertSame('phpunit-replay-null 0', $driver->nameAndVersion());
    }

    #[Test]
    public function start_and_stop_never_throw_and_stop_reports_no_coverage(): void
    {
        $driver = new NullCoverageDriver();

        $driver->start();
        $data = $driver->stop();

        self::assertSame([], $data->lineCoverage());
        self::assertSame([], $data->functionCoverage());
    }

    /**
     * The single reason this class exists: building a `CodeCoverage` must not need pcov or
     * xdebug to be enabled in the process doing the building.
     */
    #[Test]
    public function code_coverage_accepts_it_as_its_driver(): void
    {
        $coverage = new CodeCoverage(new NullCoverageDriver(), new Filter());

        self::assertSame([], $coverage->getData(true)->lineCoverage());
        self::assertSame([], $coverage->getTests());
    }

    /**
     * php-code-coverage 14 reads the driver's name and version back out of a `CodeCoverage`
     * when it serializes one, and refuses to merge two files whose driver information
     * disagrees — so `Coverage\SerializedArchive` carries the recorded pair forward through
     * this driver rather than replacing it with its own.
     */
    #[Test]
    public function it_can_carry_another_drivers_recorded_name_and_version(): void
    {
        $driver = new NullCoverageDriver('pcov', '1.0.11');

        self::assertSame('pcov', $driver->name());
        self::assertSame('1.0.11', $driver->version());
        self::assertSame('pcov 1.0.11', $driver->nameAndVersion());
    }

    #[Test]
    public function an_empty_name_or_version_falls_back_to_the_default(): void
    {
        $driver = new NullCoverageDriver('', '');

        self::assertSame('phpunit-replay-null', $driver->name());
        self::assertSame('0', $driver->version());
    }
}
