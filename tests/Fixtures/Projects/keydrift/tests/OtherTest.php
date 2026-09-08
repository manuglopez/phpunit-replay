<?php

declare(strict_types=1);

namespace App\Tests;

use App\Other;
use PHPUnit\Framework\TestCase;

/** The stable half of the fixture: its edges are src/Other.php on every pass. */
final class OtherTest extends TestCase
{
    public function testHalvesAnEvenNumber(): void
    {
        self::assertSame(2, (new Other())->half(4));
    }

    public function testHalvesAnOddNumberDownwards(): void
    {
        self::assertSame(1, (new Other())->half(3));
    }
}
