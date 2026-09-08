<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Coverage\CoverageFormat;
use Manuglopez\Replay\Laravel\LaravelIntegration;
use Manuglopez\Replay\PHPUnit\ConfigurationWriter;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end Laravel integration: runs the real `laravel-lite` fixture (a reduced Laravel
 * application with its own composer-installed vendor/, including a real
 * `illuminate/container` — SPEC.md §10, docs/INTERNALS.md "Laravel") through a real
 * `phpunit` subprocess with `ReplayExtension` bootstrapped, and inspects the run partial it
 * writes. Skipped entirely when tests/Fixtures/Projects/laravel-lite/vendor was never
 * installed (see its README.md).
 *
 * The arming hook this test exercises is the ~6-line addition to `ReplayExtension` described
 * in the final report: `if (class_exists(LaravelIntegration::class) &&
 * LaravelIntegration::shouldArm($root)) { registerSubscribers(...LaravelIntegration::subscribers($recorder)); }`.
 */
final class LaravelLiteFixtureTest extends TestCase
{
    /** @var list<FixtureProject> */
    private array $fixtures = [];

    /** @var list<string> */
    private array $stateDirs = [];

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
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        foreach ($this->stateDirs as $stateDir) {
            TempDir::remove($stateDir);
        }

        $this->fixtures = [];
        $this->stateDirs = [];

        parent::tearDown();
    }

    public function test_the_fixture_suite_passes_on_its_own(): void
    {
        $fixture = $this->laravelLite();

        $result = $fixture->phpunit();

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('OK (4 tests', $result['stdout']);
    }

    public function test_record_mode_writes_tables_edges_and_uses_database(): void
    {
        $fixture = $this->laravelLite();
        $root = $fixture->root();

        (new ConfigurationWriter())->withExtensionOnly($root . '/phpunit.xml');

        $stateDir = TempDir::make('laravel-lite-state');
        $this->stateDirs[] = $stateDir;

        $result = $fixture->phpunit(
            ['-c', '.phpunit-replay.xml', '--no-coverage'],
            [
                'PHPUNIT_REPLAY_MODE' => 'record',
                'PHPUNIT_REPLAY_STATE_DIR' => $stateDir,
                'PHPUNIT_REPLAY_RUN_ID' => 't1',
                'PHPUNIT_REPLAY_ROOT' => $root,
            ],
        );

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);

        $partial = RunPartial::load($stateDir . '/runs/t1');
        self::assertNotNull($partial, 'Expected a run partial: ' . $result['stdout'] . $result['stderr']);

        // tables.json
        self::assertArrayHasKey('tests/Feature/PostsIndexTest.php', $partial->tables);
        self::assertContains('posts', $partial->tables['tests/Feature/PostsIndexTest.php']);
        self::assertContains('users', $partial->tables['tests/Feature/PostsIndexTest.php']);

        self::assertArrayHasKey('tests/Feature/UserModelTest.php', $partial->tables);
        self::assertContains('users', $partial->tables['tests/Feature/UserModelTest.php']);
        self::assertNotContains('posts', $partial->tables['tests/Feature/UserModelTest.php']);

        self::assertArrayNotHasKey('tests/Feature/HomePageTest.php', $partial->tables);

        // edges.json
        self::assertContains('resources/views/welcome.blade.php', $partial->edges['tests/Feature/HomePageTest.php']);

        self::assertContains('resources/views/posts/index.blade.php', $partial->edges['tests/Feature/PostsIndexTest.php']);
        self::assertContains('resources/views/posts/_item.blade.php', $partial->edges['tests/Feature/PostsIndexTest.php']);

        // uses_database.json
        self::assertEqualsCanonicalizing(
            [
                'tests/Feature/PostJsonTest.php',
                'tests/Feature/PostsIndexTest.php',
                'tests/Feature/UserModelTest.php',
            ],
            $partial->usesDatabase,
        );
        self::assertNotContains('tests/Feature/HomePageTest.php', $partial->usesDatabase);
    }

    public function test_augment_widens_database_tests_to_every_migration_table(): void
    {
        $fixture = $this->laravelLite();
        $root = $fixture->root();

        (new ConfigurationWriter())->withExtensionOnly($root . '/phpunit.xml');

        $stateDir = TempDir::make('laravel-lite-augment-state');
        $this->stateDirs[] = $stateDir;

        $result = $fixture->phpunit(
            ['-c', '.phpunit-replay.xml', '--no-coverage'],
            [
                'PHPUNIT_REPLAY_MODE' => 'record',
                'PHPUNIT_REPLAY_STATE_DIR' => $stateDir,
                'PHPUNIT_REPLAY_RUN_ID' => 't1',
                'PHPUNIT_REPLAY_ROOT' => $root,
            ],
        );

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);

        $partial = RunPartial::load($stateDir . '/runs/t1');
        self::assertNotNull($partial);

        $augmented = LaravelIntegration::augment($partial, $root);

        foreach ($partial->usesDatabase as $testFile) {
            self::assertContains('comments', $augmented->tables[$testFile], $testFile . ' should be widened to include every migration table');
        }
    }

    /**
     * Neither of the two tests above ever asks PHPUnit for `--coverage-php`, so neither
     * touches `Coverage\CoverageArchive` at all — the whole reason this package's PHPUnit 13
     * port needed a Laravel-fixture proof that `SerializedArchive` (php-code-coverage 14+) and
     * `LegacyArchive` (11-13) are each reached by a REAL Laravel test run, not just by
     * `tests/Unit/Coverage/CoverageFormatTest.php` against a synthetic file. This one asks for
     * it, and inspects the merged file `RunPipeline::finalizeCoveragePhp()` writes.
     *
     * `CoverageFormat::isForeign()` is false, and the marker matches exactly, on every cell
     * `.github/workflows/ci.yml` actually runs — it pins the fixture's own
     * phpunit/php-code-coverage to match THIS process's (either by leaving the fixture's
     * realistic `^12.5.12` default alone on a cell whose own phpunit is also pre-14, or by
     * tracking this cell's exact resolved pair when it is not, see the workflow's header
     * comment). A plain, un-pinned `composer install` in the fixture directory (its own
     * README.md) does not carry that guarantee — e.g. this process on php-code-coverage 14
     * against the fixture's un-pinned 12.5.x default — so that case is skipped rather than
     * failed: there is nothing meaningful to assert about a pairing CI never runs, and a
     * developer's local checkout should not go red over it.
     */
    public function test_coverage_php_round_trips_through_the_installed_coverage_archive(): void
    {
        $fixture = $this->laravelLite();
        $root = $fixture->root();

        $result = $fixture->replay(['record', '--coverage-php=coverage.php']);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);

        $path = $root . '/coverage.php';
        self::assertFileExists($path, $result['stdout'] . $result['stderr']);

        if (CoverageFormat::isForeign($path)) {
            self::markTestSkipped(
                'the laravel-lite fixture\'s own installed php-code-coverage is on the other '
                . 'side of the cc-14 boundary from this process\'s — expected outside the cells '
                . '.github/workflows/ci.yml pins to match (see its header comment).',
            );
        }

        self::assertSame(
            CoverageFormat::ownMarker(),
            CoverageFormat::markerOf($path),
            'coverage.php should carry this installation\'s own --coverage-php marker '
            . '(a SerializedArchive format/version marker, or none at all for LegacyArchive); '
            . 'a mismatch means CoverageMerger degraded to a foreign-format copy-through '
            . 'instead of actually reading and re-writing through the installed CoverageArchive',
        );
    }

    private function laravelLite(): FixtureProject
    {
        $fixture = FixtureProject::laravelLite();
        $this->fixtures[] = $fixture;

        return $fixture;
    }
}
