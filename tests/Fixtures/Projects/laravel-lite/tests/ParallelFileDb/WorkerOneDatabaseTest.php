<?php

declare(strict_types=1);

namespace Tests\ParallelFileDb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Tests\TestCase;

/**
 * Two test CLASSES (not two methods of one class — Paratest's default splitting is per
 * class/file), so a `--parallel=2` run actually engages two separate worker processes, each
 * getting its own token. Deliberately in a SEPARATE testsuite
 * (phpunit.parallel-file-db.xml, `tests/ParallelFileDb`) from the default `tests/Feature`
 * one: unlike `tests/Feature` (sqlite `:memory:`, isolated by construction — see
 * tests/Fixtures/Projects/laravel-lite/README.md), this one uses a FILE-based sqlite
 * database (`DB_DATABASE=database/parallel-isolation.sqlite`), the one configuration where
 * Laravel's own per-worker suffixing (`Illuminate\Testing\Concerns\TestDatabases::testDatabase()`
 * — skipped entirely for `:memory:`) is actually observable: `DB::getConfig('database')`
 * really does become a different file per worker only when phpunit-replay's `--parallel`
 * wiring (`Manuglopez\Replay\Laravel\ParallelIsolation`) is doing its job. See
 * tests/Integration/LaravelLiteParallelDatabaseTest.php in the package itself for the
 * wrapper-driven proof (asserts BOTH suffixed files end up on disk after a real
 * `record --parallel=2` run).
 */
class WorkerOneDatabaseTest extends TestCase
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
