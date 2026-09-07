<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof of `Manuglopez\Replay\Laravel\ParallelIsolation` (SPEC.md §13, the
 * `--parallel`/Laravel database isolation bug fix): drives the REAL `bin/phpunit-replay`
 * wrapper — `record --parallel=2` — against the `laravel-lite` fixture's second, narrow
 * `phpunit.parallel-file-db.xml` configuration (`tests/ParallelFileDb`, a FILE-based sqlite
 * database, unlike the default fixture's `:memory:` one — see that file's own docblock for
 * why `:memory:` can never demonstrate this).
 *
 * Two test CLASSES (`WorkerOneDatabaseTest`/`WorkerTwoDatabaseTest`) so `--parallel=2`
 * actually engages two worker processes, each asserting `DB::getConfig('database')` is
 * suffixed with its own token — which only happens when phpunit-replay correctly threads
 * BOTH `--runner=\Illuminate\Testing\ParallelRunner` and `LARAVEL_PARALLEL_TESTING=1` through
 * to Paratest. This test additionally confirms from OUTSIDE the subprocess that the two
 * resulting sqlite files are genuinely separate files on disk (`..._test_1`/`..._test_2`),
 * not just two processes independently agreeing on the same path.
 *
 * Skipped entirely when tests/Fixtures/Projects/laravel-lite/vendor was never installed
 * (see its README.md) — same guard as tests/Integration/LaravelLiteFixtureTest.php.
 */
final class LaravelLiteParallelDatabaseTest extends TestCase
{
    private ?FixtureProject $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FixtureProject::laravelLiteAvailable()) {
            self::markTestSkipped(
                'tests/Fixtures/Projects/laravel-lite/vendor is not installed — see its README.md.',
            );
        }
    }

    protected function tearDown(): void
    {
        $this->fixture?->destroy();
        $this->fixture = null;

        parent::tearDown();
    }

    public function test_parallel_record_gives_each_worker_its_own_suffixed_database_file(): void
    {
        $this->fixture = FixtureProject::laravelLite();
        $root = $this->fixture->root();

        $result = $this->fixture->replay(['record', '--parallel=2', '--', '-c', 'phpunit.parallel-file-db.xml']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('OK (2 tests', $result['stdout']);

        self::assertFileExists($root . '/database/parallel-isolation.sqlite_test_1');
        self::assertFileExists($root . '/database/parallel-isolation.sqlite_test_2');

        // The UNSUFFIXED base name must never appear: that would mean at least one worker
        // fell back to the shared, unsuffixed database — the exact bug this fix closes.
        self::assertFileDoesNotExist($root . '/database/parallel-isolation.sqlite');
    }

    public function test_plain_parallel_without_the_wrapper_reproduces_the_original_bug(): void
    {
        // Control case, run directly against vendor/bin/paratest (bypassing the wrapper
        // entirely, exactly like the user's original bug report): without our fix, both
        // workers share the SAME unsuffixed database file, and both fail the suffix
        // assertion the same way `Tests\ParallelFileDb\WorkerOneDatabaseTest`/
        // `WorkerTwoDatabaseTest` make explicit.
        $this->fixture = FixtureProject::laravelLite();
        $root = $this->fixture->root();

        $result = $this->fixture->paratest(['-c', 'phpunit.parallel-file-db.xml', '--processes', '2']);

        // Without isolation, both workers hit the SAME unsuffixed sqlite file concurrently:
        // depending on timing, that surfaces as either a failed suffix assertion in one or
        // both workers, or an outright migration race ("no such table: migrations") — the
        // same nondeterministic collision MySQL shows as a deadlock. Either way it is never
        // "OK", and never produces per-token files.
        self::assertNotSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('OK (2 tests', $result['stdout']);
        self::assertStringContainsString('Tests: 2', $result['stdout']);

        self::assertFileExists($root . '/database/parallel-isolation.sqlite');
        self::assertFileDoesNotExist($root . '/database/parallel-isolation.sqlite_test_1');
        self::assertFileDoesNotExist($root . '/database/parallel-isolation.sqlite_test_2');
    }

    /**
     * `Manuglopez\Replay\Laravel\ParallelIsolation::applicationResolvable()` (the "degrade,
     * never explode" check): with `bootstrap/app.php` removed, `Illuminate\Testing\Concerns\RunsInParallel::createApplication()`
     * would throw `RuntimeException('Parallel Runner unable to resolve application.')` in
     * Paratest's own TOP-LEVEL process — confirmed by hand: `--runner` forced anyway on this
     * exact fixture crashes with exit code 1 and ZERO tests ever attempted (no PHPUnit
     * summary at all, just that exception). The wrapper must never do that: it should warn
     * and fall back to running Paratest without the `--runner` injection, so PHPUnit still
     * gets to run all 4 tests (each individually erroring, since `bootstrap/app.php` is also
     * what every ordinary test's own `createApplication()` needs — unrelated to this fix,
     * just a side effect of this contrived setup) instead of the whole process crashing
     * before a single test starts.
     */
    public function test_degrades_with_a_warning_instead_of_crashing_when_the_laravel_application_is_unresolvable(): void
    {
        $this->fixture = FixtureProject::laravelLite();
        $this->fixture->delete('bootstrap/app.php');

        $result = $this->fixture->replay(['record', '--parallel=2']);

        self::assertStringContainsString(
            'phpunit-replay: Laravel detected but no bootstrap/app.php or Tests\\CreatesApplication was found',
            $result['stderr'],
        );
        self::assertStringNotContainsString('Parallel Runner unable to resolve application', $result['stdout']);
        self::assertStringContainsString('Tests: 4', $result['stdout']);
    }
}
