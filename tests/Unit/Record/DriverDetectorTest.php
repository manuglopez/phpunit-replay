<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\DriverDetector;
use Manuglopez\Replay\Record\PcovDriver;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Record\XdebugDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DriverDetectorTest extends TestCase
{
    #[Test]
    public function it_prefers_pcov_when_available(): void
    {
        if (! PcovDriver::available()) {
            self::markTestSkipped('pcov not available; rerun with -d pcov.enabled=1.');
        }

        $driver = DriverDetector::detect(new SourceScope([], []));

        self::assertInstanceOf(PcovDriver::class, $driver);
        self::assertSame('pcov', DriverDetector::availableName());
    }

    #[Test]
    public function it_falls_back_to_xdebug_when_pcov_is_unavailable_and_xdebug_is(): void
    {
        if (PcovDriver::available() || ! XdebugDriver::available()) {
            self::markTestSkipped('requires pcov unavailable and xdebug available; not this environment.');
        }

        $driver = DriverDetector::detect(new SourceScope([], []));

        self::assertInstanceOf(XdebugDriver::class, $driver);
        self::assertSame('xdebug', DriverDetector::availableName());
    }

    #[Test]
    public function it_returns_null_when_no_driver_is_available(): void
    {
        if (PcovDriver::available() || XdebugDriver::available()) {
            self::markTestSkipped('a coverage driver is available in this environment.');
        }

        self::assertNull(DriverDetector::detect(new SourceScope([], [])));
        self::assertNull(DriverDetector::availableName());
    }

    #[Test]
    public function loaded_extension_reports_pcov_when_ext_pcov_is_loaded_regardless_of_ini(): void
    {
        if (! extension_loaded('pcov')) {
            self::markTestSkipped('ext-pcov not loaded.');
        }

        self::assertSame('pcov', DriverDetector::loadedExtension());
    }

    #[Test]
    public function loaded_extension_is_null_when_neither_extension_is_loaded(): void
    {
        if (extension_loaded('pcov') || extension_loaded('xdebug')) {
            self::markTestSkipped('a coverage extension is loaded in this environment.');
        }

        self::assertNull(DriverDetector::loadedExtension());
    }

    #[Test]
    public function available_name_matches_what_detect_would_return(): void
    {
        $driver = DriverDetector::detect(new SourceScope([], []));

        self::assertSame($driver?->name(), DriverDetector::availableName());
    }
}
