<?php

declare(strict_types=1);

namespace App\Tests;

use App\Greeter;
use PHPUnit\Framework\TestCase;

final class GreeterTest extends TestCase
{
    public function testGreetsByTimeOfDay(): void
    {
        $greeter = new Greeter();

        self::assertSame('Good morning, Ana!', $greeter->greet('Ana', 'morning'));
        self::assertSame('Good afternoon, Ana!', $greeter->greet('Ana', 'afternoon'));
        self::assertSame('Good evening, Ana!', $greeter->greet('Ana', 'evening'));
        self::assertSame('Hello, Ana!', $greeter->greet('Ana', 'night'));
    }

    public function testBlankNameFallsBackToStranger(): void
    {
        $greeter = new Greeter();

        self::assertSame('Hello, stranger!', $greeter->greet('   '));
        self::assertSame('Hello, stranger!', $greeter->greet(''));
    }

    public function testShoutUppercasesTheGreeting(): void
    {
        $greeter = new Greeter();

        self::assertSame('HELLO, ANA!', $greeter->shout('Ana'));
    }

    public function testFailsWhenFixtureFailEnvIsSet(): void
    {
        $shouldFail = getenv('FIXTURE_FAIL') === '1';

        self::assertFalse($shouldFail, 'FIXTURE_FAIL was set: forcing a failure for the replay fixture.');
    }
}
