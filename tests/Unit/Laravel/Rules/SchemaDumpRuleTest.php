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
 * before 0.13, so a change to it ran nothing. It is diffed against the base the change set
 * was taken from, table by table.
 */
final class SchemaDumpRuleTest extends TestCase
{
    private const DUMP = 'database/schema/mysql-schema.sql';

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

    public function test_a_changed_table_selects_the_tests_recording_it_and_the_database_tests_with_no_table(): void
    {
        $this->repo->write(self::DUMP, str_replace("CREATE TABLE `parcels` (\n  `id` bigint unsigned NOT NULL", "CREATE TABLE `parcels` (\n  `id` bigint unsigned NOT NULL,\n  `total` int", self::SCHEMA));

        [$selection, $context] = $this->apply($this->graph());

        self::assertSame(['tests/NoTablesTest.php', 'tests/ParcelsTest.php'], $selection->testFiles());
        self::assertEquals(new Reason('SchemaDump', self::DUMP, 'parcels'), $selection->reasons()['tests/ParcelsTest.php'][0]);
        self::assertSame('parcels: a database test with no recorded table', $selection->reasons()['tests/NoTablesTest.php'][0]->detail);
        self::assertSame([], $context->remaining);
    }

    public function test_a_changed_table_no_test_records_selects_every_database_test(): void
    {
        $this->repo->write(self::DUMP, str_replace("CREATE TABLE `tags` (\n  `id` bigint unsigned NOT NULL", "CREATE TABLE `tags` (\n  `id` bigint unsigned NOT NULL,\n  `name` text", self::SCHEMA));

        [$selection] = $this->apply($this->graph());

        self::assertSame(['tests/NoTablesTest.php', 'tests/ParcelsTest.php', 'tests/UsersTest.php'], $selection->testFiles());
        self::assertSame('tables no test records (tags): every database test', $selection->reasons()['tests/UsersTest.php'][0]->detail);
    }

    public function test_only_migration_rows_changing_selects_nothing(): void
    {
        $this->repo->write(self::DUMP, self::SCHEMA . "INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2024_01_02_000000_x',1);\n");

        [$selection, $context] = $this->apply($this->graph());

        self::assertSame([], $selection->testFiles());
        self::assertSame([], $context->remaining, 'still the rule\'s: nothing else may claim it');
        self::assertEquals([new Reason('SchemaDump', self::DUMP, 'only migration rows changed, no table')], $selection->notes());
    }

    public function test_a_dump_that_does_not_parse_selects_every_database_test(): void
    {
        $this->repo->write(self::DUMP, self::SCHEMA . "CREATE TABLE `broken` (`x` varchar(3) DEFAULT 'oops);\n");

        [$selection] = $this->apply($this->graph());

        self::assertSame(['tests/NoTablesTest.php', 'tests/ParcelsTest.php', 'tests/UsersTest.php'], $selection->testFiles());
        self::assertSame('cannot be read: every database test', $selection->reasons()['tests/ParcelsTest.php'][0]->detail);
    }

    public function test_no_earlier_version_to_compare_selects_every_database_test(): void
    {
        $this->repo->write('database/schema/sqlite-schema.sql', "CREATE TABLE \"a\" (x int);\n");

        [$selection] = $this->apply($this->graph(), 'database/schema/sqlite-schema.sql');
        self::assertSame(['tests/NoTablesTest.php', 'tests/ParcelsTest.php', 'tests/UsersTest.php'], $selection->testFiles());
        self::assertSame('no earlier version to compare: every database test', $selection->reasons()['tests/ParcelsTest.php'][0]->detail);

        // A change set with no base (a caller that has none) is the same.
        $this->repo->write(self::DUMP, str_replace('`tags`', '`labels`', self::SCHEMA));
        [$selection] = $this->apply($this->graph(), self::DUMP, base: null);
        self::assertSame(['tests/NoTablesTest.php', 'tests/ParcelsTest.php', 'tests/UsersTest.php'], $selection->testFiles());
    }

    public function test_a_deleted_dump_removes_every_table_it_held(): void
    {
        $this->repo->delete(self::DUMP);

        [$selection] = $this->apply($this->graph());

        self::assertSame(['tests/NoTablesTest.php', 'tests/ParcelsTest.php', 'tests/UsersTest.php'], $selection->testFiles());
    }

    public function test_a_graph_with_no_tables_runs_every_test(): void
    {
        $this->repo->write(self::DUMP, str_replace('`tags`', '`labels`', self::SCHEMA));
        $graph = new Graph($this->repo->root);
        $graph->markKnownTestFiles(['tests/PlainTest.php', 'tests/ParcelsTest.php']);

        [$selection] = $this->apply($graph);

        self::assertSame(['tests/ParcelsTest.php', 'tests/PlainTest.php'], $selection->testFiles());
    }

    public function test_a_path_that_is_not_a_dump_is_left_alone(): void
    {
        $this->repo->write('database/schema/notes.sql', "x\n");

        [$selection, $context] = $this->apply($this->graph(), 'database/schema/notes.sql');

        self::assertSame([], $selection->testFiles());
        self::assertSame(['database/schema/notes.sql'], $context->remaining);
    }

    private function graph(): Graph
    {
        $graph = new Graph($this->repo->root);
        $graph->markKnownTestFiles(['tests/UsersTest.php', 'tests/ParcelsTest.php', 'tests/NoTablesTest.php', 'tests/PlainTest.php']);
        $graph->replaceTestTables(['tests/UsersTest.php' => ['users'], 'tests/ParcelsTest.php' => ['parcels', 'users']]);
        $graph->replaceUsesDatabase(['tests/NoTablesTest.php'], ['tests/NoTablesTest.php']);

        return $graph;
    }

    /** @return array{Selection, Context} */
    private function apply(Graph $graph, string $changed = self::DUMP, ?string $base = 'BASE'): array
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

        (new SchemaDumpRule())->apply($context);

        return [$selection, $context];
    }
}
