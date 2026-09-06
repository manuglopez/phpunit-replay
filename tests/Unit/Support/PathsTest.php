<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Support;

use Manuglopez\Replay\Support\Paths;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    public function testRelativeConvertsAbsolutePathInsideRoot(): void
    {
        self::assertSame('src/Foo.php', Paths::relative('/project', '/project/src/Foo.php'));
    }

    public function testRelativeConvertsBackslashesToForwardSlashes(): void
    {
        self::assertSame('src/Foo.php', Paths::relative('/project', '/project\\src\\Foo.php'));
    }

    public function testRelativeReturnsNullWhenOutsideRoot(): void
    {
        self::assertNull(Paths::relative('/project', '/elsewhere/Foo.php'));
    }

    public function testRelativeReturnsNullForRootItself(): void
    {
        self::assertNull(Paths::relative('/project', '/project'));
    }

    public function testRelativeReturnsNullUnderVendor(): void
    {
        self::assertNull(Paths::relative('/project', '/project/vendor/pkg/File.php'));
        self::assertNull(Paths::relative('/project', 'vendor/pkg/File.php'));
    }

    public function testRelativeReturnsNullForEmptyPath(): void
    {
        self::assertNull(Paths::relative('/project', ''));
    }

    public function testRelativeReturnsNullForUnknownSentinel(): void
    {
        self::assertNull(Paths::relative('/project', 'unknown'));
    }

    public function testRelativeReturnsNullForEvaldCode(): void
    {
        self::assertNull(Paths::relative('/project', "/project/src/Foo.php(10) : eval()'d code"));
    }

    public function testRelativeNormalisesRelativeInput(): void
    {
        self::assertSame('src/Foo.php', Paths::relative('/project', './src/Foo.php'));
        self::assertSame('src/Foo.php', Paths::relative('/project', 'src\\Foo.php'));
    }

    public function testIsAbsoluteRecognisesUnixPaths(): void
    {
        self::assertTrue(Paths::isAbsolute('/project/src'));
        self::assertFalse(Paths::isAbsolute('src/Foo.php'));
        self::assertFalse(Paths::isAbsolute(''));
    }

    public function testIsAbsoluteRecognisesWindowsDriveLetters(): void
    {
        self::assertTrue(Paths::isAbsolute('C:\\project\\src'));
        self::assertTrue(Paths::isAbsolute('C:/project/src'));
    }

    public function testNormalizeSeparatorsConvertsBackslashes(): void
    {
        self::assertSame('a/b/c', Paths::normalizeSeparators('a\\b\\c'));
    }

    public function testJoinCombinesRootAndRelative(): void
    {
        self::assertSame('/project/src/Foo.php', Paths::join('/project', 'src/Foo.php'));
        self::assertSame('/project/src/Foo.php', Paths::join('/project/', '/src/Foo.php'));
    }

    public function testJoinWithEmptyRelativeReturnsRoot(): void
    {
        self::assertSame('/project', Paths::join('/project/', ''));
    }
}
