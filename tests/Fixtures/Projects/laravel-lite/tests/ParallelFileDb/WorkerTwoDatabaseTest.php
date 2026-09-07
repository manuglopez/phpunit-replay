<?php

declare(strict_types=1);

namespace Tests\ParallelFileDb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Tests\TestCase;

/** @see WorkerOneDatabaseTest for why this is a separate class in a separate testsuite. */
class WorkerTwoDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_this_workers_database_is_suffixed_with_its_own_token(): void
    {
        $token = ParallelTesting::token();

        if ($token === false) {
            $this->assertTrue(true, 'Not running under --parallel: nothing to prove here.');

            return;
        }

        $this->assertStringContainsString('_test_' . $token, (string) DB::getConfig('database'));
    }
}
