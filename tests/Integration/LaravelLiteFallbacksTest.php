<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * With the Laravel rules on, `resources/views/**` and `database/migrations/**` are fallback
 * patterns (`Select\WatchDefaults\Laravel`): what the Blade and Migration rules cannot claim
 * still runs every test, as the defaults always did, and what they can claim is theirs.
 * Each change below used to run everything on 0.11.0, and ran nothing when the two defaults
 * were dropped.
 */
final class LaravelLiteFallbacksTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        if (! FixtureProject::laravelLiteAvailable()) {
            self::markTestSkipped('tests/Fixtures/Projects/laravel-lite/vendor is not installed — see its README.md.');
        }

        $this->fixture = FixtureProject::laravelLite();
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->destroy();
        }
    }

    public function test_a_new_template_nothing_references_runs_every_test_then_replays(): void
    {
        // An error page, a vendor pagination override, the target of `view('pages.' . $slug)`:
        // no rendered template names it, so nothing can say who renders it.
        $this->fixture->write('resources/views/errors/404.blade.php', "<h1>Not here</h1>\n");

        $dryRun = $this->fixture->replay(['run', '--dry-run']);
        $this->assertEveryTestFileSelectedBy('Watch', 'resources/views/errors/404.blade.php', $dryRun['stdout']);

        $run = $this->fixture->replay([]);
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertSame(0, ReplayAssert::replayedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));

        $again = $this->fixture->replay([]);
        self::assertSame(0, ReplayAssert::executedCount($again['stdout']), 'stamped with the new template: ' . ReplayAssert::lastLine($again['stdout']));
    }

    public function test_a_non_php_file_under_migrations_runs_every_test(): void
    {
        // A `.sql` file a migration reads: MigrationRule consumes `.php` migrations only.
        $this->fixture->write('database/migrations/seed.sql', "INSERT INTO users VALUES (1);\n");
        $this->fixture->repo->commitAll('a seed file');
        self::assertSame(0, $this->fixture->replay(['record'])['exitCode']);

        $this->fixture->write('database/migrations/seed.sql', "INSERT INTO users VALUES (2);\n");

        $dryRun = $this->fixture->replay(['run', '--dry-run']);
        $this->assertEveryTestFileSelectedBy('Watch', 'database/migrations/seed.sql', $dryRun['stdout']);
    }

    public function test_an_edited_plain_php_view_runs_every_test(): void
    {
        $this->fixture->write('resources/views/legacy.php', "<?php echo 'x';\n");
        $this->fixture->repo->commitAll('a plain php view');
        self::assertSame(0, $this->fixture->replay(['record'])['exitCode']);

        $this->fixture->write('resources/views/legacy.php', "<?php echo 'y';\n");

        $dryRun = $this->fixture->replay(['run', '--dry-run']);
        $this->assertEveryTestFileSelectedBy('Watch', 'resources/views/legacy.php', $dryRun['stdout']);
    }

    public function test_a_migration_for_a_table_no_test_records_runs_every_database_test(): void
    {
        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        $databaseTests = array_keys($graph->testTables());
        sort($databaseTests);
        self::assertNotSame([], $databaseTests, 'precondition: the fixture has database tests');

        $this->fixture->write(
            'database/migrations/2030_01_01_000000_create_widgets_table.php',
            "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('widgets', function (Blueprint \$table) {\n            \$table->id();\n        });\n    }\n};\n",
        );

        $dryRun = $this->fixture->replay(['run', '--dry-run']);

        foreach ($databaseTests as $testFile) {
            self::assertMatchesRegularExpression('#' . preg_quote($testFile, '#') . '\s+← Migration\s+database/migrations/2030_01_01_000000_create_widgets_table\.php#u', $dryRun['stdout'], $dryRun['stdout']);
        }

        $run = $this->fixture->replay([]);
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertGreaterThan(0, ReplayAssert::executedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));
    }

    private function assertEveryTestFileSelectedBy(string $rule, string $trigger, string $stdout): void
    {
        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        foreach ($graph->allTestFiles() as $testFile) {
            if (! str_starts_with($testFile, 'tests/Feature/')) {
                continue;
            }

            self::assertMatchesRegularExpression(
                '#' . preg_quote($testFile, '#') . '\s+← ' . $rule . '\s+' . preg_quote($trigger, '#') . '#u',
                $stdout,
                $stdout,
            );
        }
    }
}
