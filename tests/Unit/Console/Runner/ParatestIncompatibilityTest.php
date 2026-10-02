<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Console\Runner;

use Manuglopez\Replay\Console\Runner\ParatestIncompatibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParatestIncompatibilityTest extends TestCase
{
    private const FATAL = 'PHP Fatal error:  Uncaught ArgumentCountError: Too few arguments to function '
        . 'PHPUnit\TextUI\Configuration\PhpHandler::__construct(), 0 passed in /x/paratest/SuiteLoader.php';

    #[Test]
    public function words_the_incompatibility_with_both_versions(): void
    {
        self::assertSame(
            'ParaTest v7.25.0 cannot run on PHPUnit 13.4.0 (an upstream incompatibility, not a test failure). '
            . 'Run without --parallel, or pin phpunit/phpunit below 13.4.0 until ParaTest supports it.',
            ParatestIncompatibility::message(255, self::FATAL, 'v7.25.0', '13.4.0'),
        );
    }

    #[Test]
    public function needs_exit_255_the_error_class_and_a_phpunit_internal(): void
    {
        self::assertNull(ParatestIncompatibility::message(1, self::FATAL, 'v7.25.0', '13.4.0'));
        self::assertNull(ParatestIncompatibility::message(255, 'Fatal error: something else', 'v7.25.0', '13.4.0'));
        self::assertNull(ParatestIncompatibility::message(255, 'ArgumentCountError in App\Foo::__construct()', 'v7.25.0', '13.4.0'));
    }
}
