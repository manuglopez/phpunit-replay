<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel\Rules;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Laravel\Rules\SchemaDumpRule;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\GitRepo;
use PHPUnit\Framework\TestCase;

/**
 * A schema dump is how `RefreshDatabase` builds the test database; no rule claimed one
 * before 0.13, so a change to it ran nothing. It is compared with the version at the base
 * the change set was taken from: by default any real change runs every database test;
 * `schema_dump => 'per-table'` narrows by table.
 */
final class SchemaDumpRuleTest extends TestCase
{
    private const DUMP = 'database/schema/mysql-schema.sql';

    private const ALL_DATABASE_TESTS = ['tests/NoTablesTest.php', 'tests/ParcelsTest.php', 'tests/UnknownTest.php', 'tests/UsersTest.php'];

    private const SCHEMA = <<<'SQL'
        CREATE TABLE `users` (
          `id` bigint unsigned NOT NULL
        ) ENGINE=InnoDB;
        CREATE TABLE `parcels` (
          `id` bigint unsigned NOT NULL
        ) ENGINE=InnoDB;
        CREATE TABLE `tags` (
          `id` bigint unsigned NOT NULL
        ) ENGINE=InnoDB;
        CREATE TABLE `comments` (
          `id` bigint unsigned NOT NULL,
          `user_id` bigint unsigned NOT NULL,
          CONSTRAINT `comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB;
        INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2024_01_01_000000_create_users_table',1);

        SQL;

    private GitRepo $repo;

    private string $base;

    protected function setUp(): void
    {
        $this->repo = GitRepo::init();
        $this->repo->write(self::DUMP, self::SCHEMA);
        $this->base = $this->repo->commitAll('a schema dump');
    }

    protected function tearDown(): void
    {
        $this->repo->destroy();
    }

    // -- conservative (the default) ----------------------------------------------

    public function test_by_default_any_change_selects_every_database_test(): void
    {
        $this->repo->write(self::DUMP, str_replace('`tags`', '`labels`', self::SCHEMA));

        [$selection, $context] = $this->apply($this->graph(), new SchemaDumpRule());

        self::assertSame(self::ALL_DATABASE_TESTS, $selection->testFiles(), 'every database test, PlainTest not');
        self::assertEquals(new Reason('SchemaDump', self::DUMP, 'changed: every database test'), $selection->reasons()['tests/UsersTest.php'][0]);
        self::assertSame([], $context->remaining);
    }

    public function test_comments_and_whitespace_only_select_nothing_in_either_mode(): void
    {
        $this->repo->write(self::DUMP, "-- dumped again\n" . str_replace("\n  `", "\n      `", self::SCHEMA) . "\n\n");

        foreach ([new SchemaDumpRule(), new SchemaDumpRule('per-table')] as $rule) {
            [$selection, $context] = $this->apply($this->graph(), $rule);

            self::assertSame([], $selection->testFiles());
            self::assertSame([], $context->remaining);
            self::assertEquals([new Reason('SchemaDump', self::DUMP, 'comments and whitespace only')], $selection->notes());
        }
    }

    public function test_migration_rows_changing_selects_every_database_test_in_either_mode(): void
    {
        // A pending data migration squashed by regenerating the dump stops running in test
        // databases: a dump holds no data, only the row saying it already ran.
        $this->repo->write(self::DUMP, self::SCHEMA . "INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2024_01_02_000000_seed_admin',1);\n");

        foreach ([new SchemaDumpRule(), new SchemaDumpRule('per-table')] as $rule) {
            [$selection] = $this->apply($this->graph(), $rule);

            self::assertSame(self::ALL_DATABASE_TESTS, $selection->testFiles());
        }
    }

    // -- per-table --------------------------------------------------------------

    public function test_a_changed_table_selects_its_tests_and_the_ones_whose_tables_are_not_all_known(): void
    {
        $this->repo->write(self::DUMP, str_replace("CREATE TABLE `parcels` (\n  `id` bigint unsigned NOT NULL", "CREATE TABLE `parcels` (\n  `id` bigint unsigned NOT NULL,\n  `total` int", self::SCHEMA));

        [$selection] = $this->apply($this->graph(), new SchemaDumpRule('per-table'));

        self::assertSame(['tests/NoTablesTest.php', 'tests/ParcelsTest.php', 'tests/UnknownTest.php'], $selection->testFiles());
        self::assertEquals(new Reason('SchemaDump', self::DUMP, 'parcels'), $selection->reasons()['tests/ParcelsTest.php'][0]);
        self::assertSame('parcels: a database test whose tables are not all known', $selection->reasons()['tests/NoTablesTest.php'][0]->detail);
    }

    public function test_a_change_reaches_the_tables_a_foreign_key_ties_it_to(): void
    {
        // Only the child's block changes; what deleting a user does changes too.
        $this->repo->write(self::DUMP, str_replace(' ON DELETE CASCADE', '', self::SCHEMA));

        [$selection] = $this->apply($this->graph(), new SchemaDumpRule('per-table'));

        self::assertContains('tests/UsersTest.php', $selection->testFiles());
        self::assertNotContains('tests/ParcelsTest.php', $selection->testFiles());
    }

    public function test_a_table_the_database_is_built_with_selects_every_database_test(): void
    {
        $graph = $this->graph();
        $graph->unionTestTables(['tests/ParcelsTest.php' => ['@tags']]);
        $this->repo->write(self::DUMP, str_replace('`tags`', '`tags2`', self::SCHEMA));

        [$selection] = $this->apply($graph, new SchemaDumpRule('per-table'));

        self::assertSame(self::ALL_DATABASE_TESTS, $selection->testFiles());
        self::assertStringContainsString('tables the database is built with (tags)', $selection->reasons()['tests/UsersTest.php'][0]->detail);
    }

    public function test_a_table_no_test_records_selects_only_the_tests_whose_tables_are_not_all_known(): void
    {
        $this->repo->write(self::DUMP, str_replace("CREATE TABLE `tags` (\n  `id` bigint unsigned NOT NULL", "CREATE TABLE `tags` (\n  `id` bigint unsigned NOT NULL,\n  `name` text", self::SCHEMA));

        [$selection] = $this->apply($this->graph(), new SchemaDumpRule('per-table'));

        self::assertSame(['tests/NoTablesTest.php', 'tests/UnknownTest.php'], $selection->testFiles());
    }

    // -- either mode, where it cannot compare ------------------------------------------

    public function test_a_dump_that_does_not_parse_selects_every_database_test(): void
    {
        $this->repo->write(self::DUMP, self::SCHEMA . "CREATE TABLE `broken` (`x` varchar(3) DEFAULT 'oops);\n");

        [$selection] = $this->apply($this->graph(), new SchemaDumpRule('per-table'));

        self::assertSame(self::ALL_DATABASE_TESTS, $selection->testFiles());
        self::assertSame('cannot be read: every database test', $selection->reasons()['tests/ParcelsTest.php'][0]->detail);
    }

    public function test_no_earlier_version_to_compare_selects_every_database_test(): void
    {
        $this->repo->write('database/schema/sqlite-schema.sql', "CREATE TABLE \"a\" (x int);\n");

        [$selection] = $this->apply($this->graph(), new SchemaDumpRule('per-table'), 'database/schema/sqlite-schema.sql');
        self::assertSame(self::ALL_DATABASE_TESTS, $selection->testFiles());
        self::assertSame('no earlier version to compare: every database test', $selection->reasons()['tests/ParcelsTest.php'][0]->detail);

        $this->repo->write(self::DUMP, str_replace('`tags`', '`labels`', self::SCHEMA));
        [$selection] = $this->apply($this->graph(), new SchemaDumpRule('per-table'), self::DUMP, base: null);
        self::assertSame(self::ALL_DATABASE_TESTS, $selection->testFiles(), 'a change set with no base');
    }

    public function test_a_deleted_dump_selects_every_database_test(): void
    {
        $this->repo->delete(self::DUMP);

        [$selection] = $this->apply($this->graph(), new SchemaDumpRule('per-table'));

        self::assertSame(self::ALL_DATABASE_TESTS, $selection->testFiles());
    }

    public function test_a_graph_with_no_tables_runs_every_test(): void
    {
        $this->repo->write(self::DUMP, str_replace('`tags`', '`labels`', self::SCHEMA));
        $graph = new Graph($this->repo->root);
        $graph->markKnownTestFiles(['tests/PlainTest.php', 'tests/ParcelsTest.php']);

        [$selection] = $this->apply($graph, new SchemaDumpRule());

        self::assertSame(['tests/ParcelsTest.php', 'tests/PlainTest.php'], $selection->testFiles());
    }

    public function test_a_path_that_is_not_a_dump_is_left_alone(): void
    {
        $this->repo->write('database/schema/notes.sql', "x\n");

        [$selection, $context] = $this->apply($this->graph(), new SchemaDumpRule(), 'database/schema/notes.sql');

        self::assertSame([], $selection->testFiles());
        self::assertSame(['database/schema/notes.sql'], $context->remaining);
    }

    private function graph(): Graph
    {
        $graph = new Graph($this->repo->root);
        $graph->markKnownTestFiles(['tests/UsersTest.php', 'tests/ParcelsTest.php', 'tests/NoTablesTest.php', 'tests/UnknownTest.php', 'tests/PlainTest.php']);
        $graph->replaceTestTables([
            'tests/UsersTest.php' => ['users'],
            'tests/ParcelsTest.php' => ['parcels'],
            'tests/UnknownTest.php' => ['users', '*'],
        ]);
        $graph->replaceUsesDatabase(['tests/NoTablesTest.php'], ['tests/NoTablesTest.php']);

        return $graph;
    }

    /** @return array{Selection, Context} */
    private function apply(Graph $graph, SchemaDumpRule $rule, string $changed = self::DUMP, ?string $base = 'BASE'): array
    {
        $selection = new Selection();
        $context = new Context(
            $graph,
            $this->repo->root,
            new TestPaths(['tests'], [], ['Test.php']),
            new WatchPatterns(),
            [$changed],
            $selection,
            $base === 'BASE' ? $this->base : $base,
        );

        $rule->apply($context);

        return [$selection, $context];
    }
}
