<?php

declare(strict_types=1);

namespace App\Tests;

use Manuglopez\Replay\PHPUnit\Replayable;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for the in-process fixture: the replay trait plus a deliberately
 * "expensive" setUp() guarded by isReplaying(). The guard writes the number of times the
 * expensive half actually ran to <root>/.setup-count, so the integration test can prove
 * a replayed test never paid for it.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use Replayable;

    private static int $expensiveSetUps = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->isReplaying()) {
            return;
        }

        self::$expensiveSetUps++;

        file_put_contents(dirname(__DIR__) . '/.setup-count', (string) self::$expensiveSetUps);
    }
}
