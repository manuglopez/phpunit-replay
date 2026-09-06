<?php

declare(strict_types=1);

namespace App\Tests;

use App\Greeter;
use Manuglopez\Replay\Attributes\NotCacheable;
use PHPUnit\Framework\TestCase;

/**
 * Fixture for the hermeticity "not cacheable" scenarios: the whole class is marked
 * unsafe to replay, so both tests always execute for real even when nothing changed.
 */
#[NotCacheable('talks to the clock')]
final class NotCacheableTest extends TestCase
{
    public function testGreetsInTheMorning(): void
    {
        $greeter = new Greeter();

        self::assertSame('Good morning, Ana!', $greeter->greet('Ana', 'morning'));
    }

    public function testShoutUppercasesTheGreeting(): void
    {
        $greeter = new Greeter();

        self::assertSame('HELLO, ANA!', $greeter->shout('Ana'));
    }
}
