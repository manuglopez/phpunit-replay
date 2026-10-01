<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel\Rules;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Laravel\MigrationPaths;
use Manuglopez\Replay\Laravel\Rules\MigrationRule;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * `migrations => 'precise'` (the default): a migration the test database runs selects every
 * database test, one the schema dump already holds selects none; `'conservative'` keeps the
 * table-intersection rule of 0.12 (the tests below that call {@see self::conservative()}).
 */
final class MigrationRuleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('migration-rule');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_name_is_migration(): void
    {
        self::assertSame('Migration', (new MigrationRule())->name());
    }

    public function test_a_migration_creating_a_table_affects_tests_whose_tables_intersect(): void
    {
        $this->write(
            'database/migrations/2024_01_01_000000_create_posts_table.php',
            "<?php\nSchema::create('posts', function (\$table) {});\n",
        );

        $graph = new Graph($this->root);
        $graph->replaceTestTables([
            'tests/PostsTest.php' => ['posts'],
            'tests/UsersTest.php' => ['users'],
        ]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['database/migrations/2024_01_01_000000_create_posts_table.php'], $selection);

        self::conservative()->apply($context);

        self::assertSame(['tests/PostsTest.php'], $selection->testFiles());
        self::assertSame([], $context->remaining);

        $reason = $selection->reasons()['tests/PostsTest.php'][0];
        self::assertSame('Migration', $reason->rule);
        self::assertSame('database/migrations/2024_01_01_000000_create_posts_table.php', $reason->trigger);
        self::assertSame('posts', $reason->detail);
    }

    public function test_a_migration_whose_tables_no_test_records_runs_every_database_test(): void
    {
        $this->write(
            'database/migrations/2024_01_01_000000_create_widgets_table.php',
            "<?php\nSchema::create('widgets', function (\$table) {});\n",
        );

        // No test queries `widgets` yet, and every test that migrates a database runs this
        // migration: one that throws breaks all of them.
        $graph = $this->graphWithTwoTests();
        $graph->replaceTestTables(['tests/PostsTest.php' => ['posts']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['database/migrations/2024_01_01_000000_create_widgets_table.php'], $selection);

        self::conservative()->apply($context);

        self::assertSame(['tests/PostsTest.php'], $selection->testFiles(), 'the database tests, not the one without tables');
        self::assertSame([], $context->remaining);
    }

    /**
     * With nothing to narrow by, a migration runs every test: what the
     * `database/migrations/**` watch default this rule replaces did for it
     * (`Select\WatchDefaults\Laravel`).
     */
    public function test_an_unparseable_migration_runs_every_test(): void
    {
        $this->write(
            'database/migrations/2024_01_01_000000_mystery.php',
            "<?php\nreturn new class { public function up(): void {} };\n",
        );

        $graph = $this->graphWithTwoTests();
        $graph->replaceTestTables(['tests/PostsTest.php' => ['posts']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['database/migrations/2024_01_01_000000_mystery.php'], $selection);

        self::conservative()->apply($context);

        self::assertSame(['tests/OtherTest.php', 'tests/PostsTest.php'], $selection->testFiles());
        self::assertSame('no tables to narrow by', $selection->reasons()['tests/OtherTest.php'][0]->detail);
        self::assertSame([], $context->remaining);
    }

    public function test_a_deleted_migration_runs_every_test(): void
    {
        $graph = $this->graphWithTwoTests();
        $graph->replaceTestTables(['tests/PostsTest.php' => ['posts']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['database/migrations/2024_01_01_000000_deleted.php'], $selection);

        self::conservative()->apply($context);

        self::assertSame(['tests/OtherTest.php', 'tests/PostsTest.php'], $selection->testFiles());
        self::assertSame([], $context->remaining);
    }

    public function test_runs_every_test_when_the_graph_has_no_test_tables(): void
    {
        $this->write(
            'database/migrations/2024_01_01_000000_create_posts_table.php',
            "<?php\nSchema::create('posts', function (\$table) {});\n",
        );

        $graph = $this->graphWithTwoTests();

        $selection = new Selection();
        $context = $this->makeContext($graph, ['database/migrations/2024_01_01_000000_create_posts_table.php'], $selection);

        self::conservative()->apply($context);

        self::assertSame(['tests/OtherTest.php', 'tests/PostsTest.php'], $selection->testFiles());
        self::assertSame([], $context->remaining);
    }

    private function graphWithTwoTests(): Graph
    {
        $graph = new Graph($this->root);
        $graph->markKnownTestFiles(['tests/PostsTest.php', 'tests/OtherTest.php']);

        return $graph;
    }

    public function test_ignores_a_changed_file_outside_the_migrations_directory(): void
    {
        $graph = new Graph($this->root);
        $graph->replaceTestTables(['tests/PostsTest.php' => ['posts']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['app/Models/Post.php'], $selection);

        self::conservative()->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['app/Models/Post.php'], $context->remaining);
    }

    // -- precise (the default) -------------------------------------------------

    public function test_a_pending_migration_selects_every_database_test_and_only_those(): void
    {
        $this->write('database/migrations/2024_01_01_000000_create_posts_table.php', "<?php\nSchema::create('posts', function (\$table) {});\n");
        $graph = $this->databaseGraph();

        $selection = new Selection();
        $context = $this->makeContext($graph, ['database/migrations/2024_01_01_000000_create_posts_table.php'], $selection);
        (new MigrationRule())->apply($context);

        // Every fresh test database runs it, whichever tables it names: one that throws
        // breaks them all. A test file using a database that recorded no table is one of them.
        self::assertSame(['tests/NoTablesTest.php', 'tests/PostsTest.php', 'tests/UsersTest.php'], $selection->testFiles());
        self::assertSame('pending migration: every database test', $selection->reasons()['tests/UsersTest.php'][0]->detail);
        self::assertSame([], $context->remaining);
    }

    public function test_a_migration_squashed_into_the_test_connection_s_dump_selects_nothing(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
        $this->write('database/schema/sqlite-schema.sql', "CREATE TABLE IF NOT EXISTS \"posts\"(\"id\" integer);\nINSERT INTO migrations VALUES(1,'2024_01_01_000000_create_posts_table',1);\n");
        $this->write('database/migrations/2024_01_01_000000_create_posts_table.php', "<?php\nSchema::create('posts', function (\$table) {});\n");
        $this->write('database/migrations/2024_02_01_000000_add_title.php', "<?php\nSchema::table('posts', function (\$table) {});\n");

        $selection = new Selection();
        $context = $this->makeContext($this->databaseGraph(), [
            'database/migrations/2024_01_01_000000_create_posts_table.php',
            'database/migrations/2024_02_01_000000_add_title.php',
        ], $selection);
        (new MigrationRule())->apply($context);

        $reasons = $selection->reasons();
        self::assertSame([], $context->remaining, 'both are the rule\'s');
        self::assertSame('database/migrations/2024_02_01_000000_add_title.php', $reasons['tests/PostsTest.php'][0]->trigger, 'the pending one selects');
        self::assertCount(1, $reasons['tests/PostsTest.php'], 'the squashed one does not');
        self::assertEquals(
            [new \Manuglopez\Replay\Select\Reason('Migration', 'database/migrations/2024_01_01_000000_create_posts_table.php', 'squashed into database/schema/sqlite-schema.sql, not run by tests')],
            $selection->notes(),
        );
    }

    public function test_a_migration_listed_only_in_another_connection_s_dump_is_pending(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
        $this->write('database/schema/mysql-schema.sql', "CREATE TABLE `posts` (`id` int);\nINSERT INTO `migrations` VALUES (1,'2024_01_01_000000_create_posts_table',1);\n");
        $this->write('database/migrations/2024_01_01_000000_create_posts_table.php', "<?php\nSchema::create('posts', function (\$table) {});\n");

        $selection = new Selection();
        (new MigrationRule())->apply($this->makeContext($this->databaseGraph(), ['database/migrations/2024_01_01_000000_create_posts_table.php'], $selection));

        self::assertSame(['tests/NoTablesTest.php', 'tests/PostsTest.php', 'tests/UsersTest.php'], $selection->testFiles(), 'sqlite tests run every migration');
    }

    public function test_a_migration_in_a_configured_path_is_a_migration(): void
    {
        $this->write('database/tenant/2024_01_01_000000_create_shops_table.php', "<?php\nSchema::create('shops', function (\$table) {});\n");

        $selection = new Selection();
        $context = $this->makeContext($this->databaseGraph(), ['database/tenant/2024_01_01_000000_create_shops_table.php'], $selection);
        (new MigrationRule(MigrationPaths::of(['database/migrations', 'database/tenant'])))->apply($context);

        self::assertSame(['tests/NoTablesTest.php', 'tests/PostsTest.php', 'tests/UsersTest.php'], $selection->testFiles());
        self::assertSame([], $context->remaining);

        $selection = new Selection();
        $context = $this->makeContext($this->databaseGraph(), ['database/tenant/2024_01_01_000000_create_shops_table.php'], $selection);
        (new MigrationRule())->apply($context);
        self::assertSame(['database/tenant/2024_01_01_000000_create_shops_table.php'], $context->remaining, 'not configured: not the rule\'s');
    }

    public function test_conservative_attributes_a_migration_in_a_configured_path_by_table(): void
    {
        $this->write('database/tenant/2024_01_01_000000_create_posts_table.php', "<?php\nSchema::table('posts', function (\$table) {});\n");

        $selection = new Selection();
        (new MigrationRule(MigrationPaths::of(['database/migrations', 'database/tenant']), 'conservative'))
            ->apply($this->makeContext($this->databaseGraph(), ['database/tenant/2024_01_01_000000_create_posts_table.php'], $selection));

        self::assertSame(['tests/PostsTest.php'], $selection->testFiles());
    }

    public function test_conservative_never_narrows_by_the_tables_of_a_migration_that_also_names_one_it_cannot_read(): void
    {
        $this->write('database/migrations/2024_01_01_000000_permissions.php', "<?php\nSchema::create('posts', fn (\$t) => null);\nSchema::create(\$tableNames['roles'], fn (\$t) => null);\n");

        $selection = new Selection();
        self::conservative()->apply($this->makeContext($this->databaseGraph(), ['database/migrations/2024_01_01_000000_permissions.php'], $selection));

        self::assertSame(['tests/NoTablesTest.php', 'tests/PostsTest.php', 'tests/UsersTest.php'], $selection->testFiles());
        self::assertSame('tables it cannot name: every database test', $selection->reasons()['tests/UsersTest.php'][0]->detail);
    }

    public function test_precise_still_runs_every_test_while_the_graph_records_no_table(): void
    {
        $this->write('database/migrations/2024_01_01_000000_create_posts_table.php', "<?php\nSchema::create('posts', function (\$table) {});\n");

        $selection = new Selection();
        (new MigrationRule())->apply($this->makeContext($this->graphWithTwoTests(), ['database/migrations/2024_01_01_000000_create_posts_table.php'], $selection));

        self::assertSame(['tests/OtherTest.php', 'tests/PostsTest.php'], $selection->testFiles());
    }

    private function databaseGraph(): Graph
    {
        $graph = new Graph($this->root);
        $graph->markKnownTestFiles(['tests/PostsTest.php', 'tests/UsersTest.php', 'tests/NoTablesTest.php', 'tests/PlainTest.php']);
        $graph->replaceTestTables(['tests/PostsTest.php' => ['posts'], 'tests/UsersTest.php' => ['users']]);
        $graph->replaceUsesDatabase(['tests/NoTablesTest.php'], ['tests/NoTablesTest.php']);

        return $graph;
    }

    private static function conservative(): MigrationRule
    {
        return new MigrationRule(MigrationPaths::default(), 'conservative');
    }

    private function write(string $relative, string $content): void
    {
        TempDir::write($this->root . '/' . $relative, $content);
    }

    /** @param list<string> $remaining */
    private function makeContext(Graph $graph, array $remaining, Selection $selection): Context
    {
        return new Context(
            $graph,
            $this->root,
            new TestPaths(['tests'], [], ['Test.php']),
            new WatchPatterns(),
            $remaining,
            $selection,
        );
    }
}
