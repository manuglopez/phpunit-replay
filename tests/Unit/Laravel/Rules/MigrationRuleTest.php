<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel\Rules;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Laravel\Rules\MigrationRule;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

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

        (new MigrationRule())->apply($context);

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

        (new MigrationRule())->apply($context);

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

        (new MigrationRule())->apply($context);

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

        (new MigrationRule())->apply($context);

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

        (new MigrationRule())->apply($context);

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

        (new MigrationRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['app/Models/Post.php'], $context->remaining);
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
