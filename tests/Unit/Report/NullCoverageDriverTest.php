<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Report;

use Manuglopez\Replay\Report\NullCoverageDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Driver\Driver;

/**
 * A no-op `Driver` (docs/INTERNALS.md "CoverageMerger"): `CoverageMerger::writeEmptyRun()`
 * uses it instead of `Driver\Selector::forLineCoverage()`, which requires a real coverage
 * extension loaded AND enabled in the CURRENT process — a requirement the wrapper cannot
 * meet for itself when it is invoked as plain `php vendor/bin/phpunit-replay` (only the
 * CHILD PhpunitProcess it launches gets `-d pcov.enabled=1`). Since the coverage built from
 * this driver is empty by construction — nothing runs, every real line is merged in
 * afterwards from stored snapshots — no real driver is needed at all.
 */
final class NullCoverageDriverTest extends TestCase
{
    #[Test]
    public function it_is_a_driver_that_never_touches_a_real_extension(): void
    {
        $driver = new NullCoverageDriver();

        self::assertInstanceOf(Driver::class, $driver);
        self::assertSame('phpunit-replay-null', $driver->nameAndVersion());
        self::assertFalse($driver->isPcov());
        self::assertFalse($driver->isXdebug());
        self::assertFalse($driver->canCollectBranchAndPathCoverage());
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
}
