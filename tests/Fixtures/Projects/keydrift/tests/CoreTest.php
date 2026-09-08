<?php

declare(strict_types=1);

namespace App\Tests;

use App\Core;
use App\Extra;
use PHPUnit\Framework\TestCase;

/**
 * The drifting half of the fixture. With FIXTURE_EXTRA_EDGE=1 the second test also
 * executes App\Extra, so coverage attributes one more source file to this test file and
 * `Graph::unionEdges()` grows its dependency set — which changes this file's content key
 * while leaving every byte on disk exactly where it was.
 *
 * Note the `use App\Extra` above is compile-time aliasing only: it neither autoloads nor
 * executes anything, so with the variable unset this file's edges stay at src/Core.php.
 */
final class CoreTest extends TestCase
{
    public function testDoublesASmallNumber(): void
    {
        self::assertSame(4, (new Core())->double(2));
    }

    public function testDoublesABiggerNumber(): void
    {
        self::assertSame(6, (new Core())->double(3));

        if (getenv('FIXTURE_EXTRA_EDGE') === '1') {
            self::assertSame(9, (new Extra())->triple(3));
        }
    }
}
