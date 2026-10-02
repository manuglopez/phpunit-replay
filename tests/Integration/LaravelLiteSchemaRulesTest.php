<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Select\NonEdgeInputs;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * 0.13 on the real `laravel-lite` application: the tables a test touches in `setUp()` are
 * recorded, each test file keeps the tables it recorded (no union of every migration's),
 * the schema dump the test database is built from selects by table, a migration the dump
 * already holds selects nothing, one it does not hold selects every database test, a
 * migration in a configured path is a migration, and a new console command in a new
 * subdirectory selects the tests of the commands next to it.
 */
final class LaravelLiteSchemaRulesTest extends TestCase
{
    private const DUMP = 'database/schema/sqlite-schema.sql';

    private const DATABASE_TESTS = [
        'tests/Feature/LedgerTest.php',
        'tests/Feature/PostJsonTest.php',
        'tests/Feature/PostsIndexTest.php',
        'tests/Feature/UserModelTest.php',
    ];

    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS "migrations"(
          "id" integer primary key autoincrement not null,
          "migration" varchar not null,
          "batch" integer not null
        );
        CREATE TABLE IF NOT EXISTS "users"(
          "id" integer primary key autoincrement not null,
          "name" varchar not null,
          "email" varchar not null,
          "email_verified_at" datetime,
          "password" varchar not null,
          "remember_token" varchar,
          "created_at" datetime,
          "updated_at" datetime
        );
        CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
        CREATE TABLE IF NOT EXISTS "posts"(
          "id" integer primary key autoincrement not null,
          "user_id" integer not null,
          "title" varchar not null,
          "body" text not null,
          "created_at" datetime,
          "updated_at" datetime,
          foreign key("user_id") references "users"("id") on delete cascade
        );
        CREATE TABLE IF NOT EXISTS "comments"(
          "id" integer primary key autoincrement not null,
          "post_id" integer not null,
          "user_id" integer not null,
          "body" text not null,
          "created_at" datetime,
          "updated_at" datetime,
          foreign key("post_id") references "posts"("id") on delete cascade,
          foreign key("user_id") references "users"("id") on delete cascade
        );
        CREATE TABLE IF NOT EXISTS "ledgers"(
          "id" integer primary key autoincrement not null,
          "amount" integer not null
        );
        INSERT INTO migrations VALUES(1,'2024_01_01_000000_create_users_table',1);
        INSERT INTO migrations VALUES(2,'2024_01_02_000000_create_posts_table',1);
        INSERT INTO migrations VALUES(3,'2024_01_03_000000_create_comments_table',1);

        SQL;

    private const LEDGER_TEST = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Feature;

        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\DB;
        use Illuminate\Support\Facades\Schema;
        use Tests\TestCase;

        class LedgerTest extends TestCase
        {
            protected function setUp(): void
            {
                parent::setUp();

                // Its own table, built and filled in setUp() only: no database trait.
                Schema::create('ledgers', function (Blueprint $table) {
                    $table->id();
                    $table->integer('amount');
                });
                DB::table('ledgers')->insert(['amount' => 5]);
            }

            public function test_the_set_up_row_is_there(): void
            {
                $this->assertTrue(true);
            }
        }

        PHP;

    /** @var list<FixtureProject> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        if (! FixtureProject::laravelLiteAvailable()) {
            self::markTestSkipped('tests/Fixtures/Projects/laravel-lite/vendor is not installed — see its README.md.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }
    }

    public function test_a_table_touched_only_in_set_up_is_recorded_and_tables_stay_raw(): void
    {
        $fixture = $this->recorded();
        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);

        self::assertSame(['ledgers'], $graph->testTables()['tests/Feature/LedgerTest.php'] ?? null, 'queried in setUp() only');
        self::assertSame(['users'], $graph->testTables()['tests/Feature/UserModelTest.php'] ?? null, 'what it queried, not every migration table');
        self::assertSame(['tests/Feature/PostJsonTest.php', 'tests/Feature/PostsIndexTest.php', 'tests/Feature/UserModelTest.php'], $graph->usesDatabase());
    }

    public function test_tables_a_trait_s_set_up_or_a_seeder_touches_are_recorded(): void
    {
        // Laravel runs every testing trait's setUp (RefreshDatabase and its seeder included)
        // before its afterApplicationCreated callbacks: the trackers must be armed earlier.
        $fixture = $this->fixtures[] = FixtureProject::laravelLite();
        $fixture->write('tests/Support/SeedsAPost.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Tests\\Support;\n\nuse Illuminate\\Support\\Facades\\DB;\n\ntrait SeedsAPost\n{\n    protected function setUpSeedsAPost(): void\n    {\n        \$id = DB::table('users')->insertGetId(['name' => 'x', 'email' => 'x@x', 'password' => 'p']);\n        DB::table('posts')->insert(['user_id' => \$id, 'title' => 't', 'body' => 'b']);\n    }\n}\n");
        $fixture->write('tests/Feature/TraitSetUpTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Tests\\Feature;\n\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Tests\\Support\\SeedsAPost;\nuse Tests\\TestCase;\n\nclass TraitSetUpTest extends TestCase\n{\n    use RefreshDatabase;\n    use SeedsAPost;\n\n    public function test_nothing_queried_here(): void\n    {\n        \$this->assertTrue(true);\n    }\n}\n");
        $fixture->write('database/seeders/AdminSeeder.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\nuse Illuminate\\Support\\Facades\\DB;\n\nclass AdminSeeder extends Seeder\n{\n    public function run(): void\n    {\n        DB::table('users')->insert(['name' => 'admin', 'email' => 'admin@x', 'password' => 'p']);\n    }\n}\n");
        // Laravel's documented place for a seeder: the base test case, so whichever database
        // test of the process migrates first also seeds.
        $fixture->write('tests/TestCase.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Tests;\n\nuse Database\\Seeders\\AdminSeeder;\nuse Illuminate\\Foundation\\Testing\\TestCase as BaseTestCase;\n\nabstract class TestCase extends BaseTestCase\n{\n    protected \$seeder = AdminSeeder::class;\n}\n");
        $fixture->repo->commitAll('a trait setUp and a seeder');
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);
        self::assertSame(['posts', 'users'], $graph->testTables()['tests/Feature/TraitSetUpTest.php'] ?? null, 'written by setUpSeedsAPost()');
        // RefreshDatabase migrates and seeds once per process, in whichever test comes first:
        // every later database test runs on those rows, so they are every database test's.
        self::assertContains('users', $graph->bootstrapTables(), 'written by the seeder RefreshDatabase runs');
    }

    public function test_the_wrapper_and_the_in_process_extension_record_the_same_tables_and_database_tests(): void
    {
        $wrapper = $this->recorded();
        $wrapperGraph = ReplayAssert::loadGraph($wrapper);

        $inProcess = $this->fixtures[] = FixtureProject::laravelLite();
        $inProcess->write('tests/Feature/LedgerTest.php', self::LEDGER_TEST);
        $inProcess->write('phpunit.inprocess.xml', str_replace(
            '<testsuites>',
            "<extensions>\n        <bootstrap class=\"Manuglopez\\Replay\\PHPUnit\\ReplayExtension\">\n            <parameter name=\"mode\" value=\"record\"/>\n        </bootstrap>\n    </extensions>\n    <testsuites>",
            $inProcess->read('phpunit.xml'),
        ));
        $inProcess->repo->commitAll('in-process configuration');
        $run = $inProcess->phpunitInProcess(['-c', 'phpunit.inprocess.xml']);
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        $inProcessGraph = ReplayAssert::loadGraph($inProcess);

        self::assertNotNull($wrapperGraph);
        self::assertNotNull($inProcessGraph);
        self::assertSame($wrapperGraph->testTables(), $inProcessGraph->testTables());
        self::assertSame($wrapperGraph->usesDatabase(), $inProcessGraph->usesDatabase());
    }

    public function test_by_default_a_dump_change_selects_every_database_test_and_whitespace_nothing(): void
    {
        $fixture = $this->recorded(withDump: true);

        $fixture->write(self::DUMP, "-- dumped again\n" . str_replace("\n  \"", "\n    \"", self::SCHEMA));
        self::assertSame([], $this->plan($fixture), 'comments and whitespace only');

        $fixture->write(self::DUMP, str_replace('"amount" integer not null', '"amount" integer not null, "note" varchar', self::SCHEMA));
        $plan = $this->plan($fixture);
        self::assertSame(self::DATABASE_TESTS, array_keys($plan));
        self::assertSame('SchemaDump ' . self::DUMP . ' (changed: every database test)', $plan['tests/Feature/UserModelTest.php']);
    }

    public function test_regenerating_the_dump_after_a_data_migration_selects_every_database_test(): void
    {
        // The dump stores no data: once its rows list the data migration, no test database
        // runs it, and a test reading what it inserted fails under plain PHPUnit.
        $fixture = $this->recorded(withDump: true, config: "<?php\n\nreturn ['schema_dump' => 'per-table'];\n");

        $fixture->write(self::DUMP, self::SCHEMA . "INSERT INTO migrations VALUES(4,'2024_01_05_000000_seed_admin',1);\n");
        $plan = $this->plan($fixture);

        self::assertSame(self::DATABASE_TESTS, array_keys($plan));
        self::assertStringContainsString('migration rows changed', $plan['tests/Feature/LedgerTest.php']);
    }

    public function test_a_dump_change_touching_one_table_selects_only_that_table_s_tests(): void
    {
        $fixture = $this->recorded(withDump: true, config: "<?php\n\nreturn ['schema_dump' => 'per-table'];\n");
        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);
        $before = $this->digests($fixture, $graph);

        $fixture->write(self::DUMP, str_replace('"amount" integer not null', '"amount" integer not null, "note" varchar', self::SCHEMA));
        self::assertSame(['tests/Feature/LedgerTest.php' => 'SchemaDump ' . self::DUMP . ' (ledgers)'], $this->plan($fixture));

        $fixture->write(self::DUMP, str_replace('"title" varchar not null', '"title" varchar not null, "subtitle" varchar', self::SCHEMA));
        $plan = $this->plan($fixture);
        // posts.user_id references users: deleting a user cascades to posts, so the users
        // tests are reached too. LedgerTest's table is tied to nothing.
        self::assertSame(['tests/Feature/PostJsonTest.php', 'tests/Feature/PostsIndexTest.php', 'tests/Feature/UserModelTest.php'], array_keys($plan));
        self::assertSame('SchemaDump ' . self::DUMP . ' (posts, users)', $plan['tests/Feature/PostJsonTest.php']);
        self::assertSame('SchemaDump ' . self::DUMP . ' (users)', $plan['tests/Feature/UserModelTest.php']);

        // The digest moves for exactly the tests the rule selects.
        $after = $this->digests($fixture, $graph);
        self::assertSame(array_keys($plan), array_keys(array_diff_assoc($after, $before)));

        // And the run is green on the new schema, then replays.
        $run = $fixture->replay([]);
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($fixture->replay([])['stdout']));
    }

    public function test_an_unparseable_dump_selects_every_database_test(): void
    {
        $fixture = $this->recorded(withDump: true);

        $fixture->write(self::DUMP, self::SCHEMA . "CREATE TABLE \"broken\" (\"x\" varchar DEFAULT 'never closed);\n");

        $plan = $this->plan($fixture);
        self::assertSame(self::DATABASE_TESTS, array_keys($plan), 'every database test, HomePageTest not');
        self::assertSame('SchemaDump ' . self::DUMP . ' (cannot be read: every database test)', $plan['tests/Feature/UserModelTest.php']);
    }

    public function test_a_squashed_migration_selects_nothing_and_a_pending_one_every_database_test(): void
    {
        $fixture = $this->recorded(withDump: true);

        $fixture->write('database/migrations/2024_01_02_000000_create_posts_table.php', str_replace("\$table->string('title');", "\$table->string('title', 120);", $fixture->read('database/migrations/2024_01_02_000000_create_posts_table.php')));
        $dryRun = $fixture->replay(['run', '--dry-run']);
        self::assertSame([], $this->planOf($dryRun['stdout']), $dryRun['stdout']);
        self::assertStringContainsString('database/migrations/2024_01_02_000000_create_posts_table.php ← Migration squashed into ' . self::DUMP . ', not run by tests: selects nothing', $dryRun['stdout']);
        $fixture->repo->git('checkout', '--', 'database/migrations');

        $fixture->write('database/migrations/2030_01_01_000000_add_note_to_ledgers.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n    }\n};\n");
        $plan = $this->plan($fixture);
        self::assertSame(self::DATABASE_TESTS, array_keys($plan));
        self::assertSame('Migration database/migrations/2030_01_01_000000_add_note_to_ledgers.php (pending migration: every database test)', $plan['tests/Feature/LedgerTest.php']);
    }

    public function test_a_migration_in_a_configured_path_is_attributed_by_table_when_conservative(): void
    {
        $fixture = $this->recorded(config: "<?php\n\nreturn ['migration_paths' => ['database/tenant'], 'migrations' => 'conservative'];\n");

        $fixture->write('database/tenant/2030_01_01_000000_add_note_to_ledgers.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nSchema::table('ledgers', function (\$table) {\n    \$table->string('note')->nullable();\n});\n");

        self::assertSame(['tests/Feature/LedgerTest.php' => 'Migration database/tenant/2030_01_01_000000_add_note_to_ledgers.php (ledgers)'], $this->plan($fixture));
    }

    public function test_a_new_command_in_a_new_subdirectory_selects_the_commands_tests(): void
    {
        $fixture = $this->fixtures[] = FixtureProject::laravelLite();
        $fixture->write('app/Console/Commands/PruneUsers.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Console\\Commands;\n\nfinal class PruneUsers\n{\n    public function handle(): int\n    {\n        return 0;\n    }\n}\n");
        $fixture->write('tests/Feature/PruneUsersTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Tests\\Feature;\n\nuse App\\Console\\Commands\\PruneUsers;\nuse Tests\\TestCase;\n\nclass PruneUsersTest extends TestCase\n{\n    public function test_it_succeeds(): void\n    {\n        \$this->assertSame(0, (new PruneUsers())->handle());\n    }\n}\n");
        $fixture->repo->commitAll('a command and its test');
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);
        $before = $this->digests($fixture, $graph);

        $fixture->write('app/Console/Commands/Reports/SendReport.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Console\\Commands\\Reports;\n\nfinal class SendReport\n{\n}\n");

        self::assertSame(['tests/Feature/PruneUsersTest.php' => 'Sibling  app/Console/Commands/Reports/SendReport.php (app/Console/Commands/**)'], $this->plan($fixture));
        self::assertSame(['tests/Feature/PruneUsersTest.php'], array_keys(array_diff_assoc($this->digests($fixture, $graph), $before)));
    }

    /** A recorded copy of the fixture, with `LedgerTest` and, when asked, a schema dump. */
    private function recorded(bool $withDump = false, ?string $config = null): FixtureProject
    {
        $fixture = $this->fixtures[] = FixtureProject::laravelLite();
        $fixture->write('tests/Feature/LedgerTest.php', self::LEDGER_TEST);

        if ($withDump) {
            $fixture->write(self::DUMP, self::SCHEMA);
        }

        if ($config !== null) {
            $fixture->write('phpunit-replay.php', $config);
        }

        $fixture->repo->commitAll('a test using its own table');
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        return $fixture;
    }

    /** @return array<string, string> test file => `<rule> <trigger> (<detail>)`, from `run --dry-run` */
    private function plan(FixtureProject $fixture): array
    {
        $dryRun = $fixture->replay(['run', '--dry-run']);
        self::assertSame(0, $dryRun['exitCode'], $dryRun['stdout'] . $dryRun['stderr']);

        return $this->planOf($dryRun['stdout']);
    }

    /** @return array<string, string> */
    private function planOf(string $stdout): array
    {
        preg_match_all('#^(tests/\S+)\s+← (.+?)\s*$#mu', $stdout, $m, PREG_SET_ORDER);
        $plan = [];

        foreach ($m as $line) {
            $plan[$line[1]] = $line[2];
        }

        ksort($plan);

        return $plan;
    }

    /** @return array<string, ?string> every recorded test file's non-edge input digest on the current tree */
    private function digests(FixtureProject $fixture, Graph $graph): array
    {
        $root = $fixture->root();
        $inputs = NonEdgeInputs::forProject($graph, $root, Config::load($root), new TestPaths(['tests/Feature'], [], ['Test.php']), new FileHashes($root), new Git($root));
        $out = [];

        foreach ($graph->allTestFiles() as $testFile) {
            $out[$testFile] = $inputs->digestFor($testFile);
        }

        ksort($out);

        return $out;
    }
}
