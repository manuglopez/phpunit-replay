<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Record\XdebugDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class XdebugDriverTest extends TestCase
{
    #[Test]
    public function it_reports_unavailable_when_the_extension_is_not_loaded(): void
    {
        if (extension_loaded('xdebug')) {
            self::markTestSkipped('ext-xdebug is loaded in this environment; nothing to assert here.');
        }

        self::assertFalse(XdebugDriver::available());
    }

    #[Test]
    public function name_is_xdebug(): void
    {
        $driver = new XdebugDriver(new SourceScope([], []));

        self::assertSame('xdebug', $driver->name());
    }
}
