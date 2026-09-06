<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Laravel\MigrationTables;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class MigrationTablesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('migration-tables');

        require_once __DIR__ . '/Fixtures/DatabaseTestingTraits.php';
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        parent::tearDown();
    }

    public function test_tables_of_returns_empty_list_when_there_is_no_migrations_directory(): void
    {
        self::assertSame([], MigrationTables::tablesOf($this->root));
    }

    public function test_tables_of_unions_tables_across_every_migration(): void
    {
        TempDir::write(
            $this->root . '/database/migrations/2024_01_01_000000_create_users_table.php',
            "<?php\nSchema::create('users', function (\$table) {});\n",
        );
        TempDir::write(
            $this->root . '/database/migrations/2024_01_02_000000_create_posts_table.php',
            "<?php\nSchema::create('posts', function (\$table) {});\nSchema::table('users', function (\$table) {});\n",
        );
        TempDir::write(
            $this->root . '/database/migrations/2024_01_03_000000_not_a_migration.txt',
            "Schema::create('ignored_extension', function (\$table) {});\n",
        );

        self::assertSame(['posts', 'users'], MigrationTables::tablesOf($this->root));
    }

    public function test_tables_of_walks_nested_migration_directories(): void
    {
        TempDir::write(
            $this->root . '/database/migrations/tenant/2024_01_01_000000_create_accounts_table.php',
            "<?php\nSchema::create('accounts', function (\$table) {});\n",
        );

        self::assertSame(['accounts'], MigrationTables::tablesOf($this->root));
    }

    public function test_uses_database_is_false_for_a_class_without_any_database_trait(): void
    {
        self::assertFalse(MigrationTables::usesDatabase(Fixtures\PlainTestCase::class));
    }

    public function test_uses_database_is_true_for_refresh_database(): void
    {
        self::assertTrue(MigrationTables::usesDatabase(Fixtures\RefreshDatabaseTestCase::class));
    }

    public function test_uses_database_is_true_for_database_migrations(): void
    {
        self::assertTrue(MigrationTables::usesDatabase(Fixtures\DatabaseMigrationsTestCase::class));
    }

    public function test_uses_database_is_true_for_database_transactions(): void
    {
        self::assertTrue(MigrationTables::usesDatabase(Fixtures\DatabaseTransactionsTestCase::class));
    }

    public function test_uses_database_is_true_for_a_subclass_of_a_class_using_the_trait(): void
    {
        self::assertTrue(MigrationTables::usesDatabase(Fixtures\ChildOfRefreshDatabaseTestCase::class));
    }

    public function test_uses_database_is_false_for_an_unloaded_class(): void
    {
        self::assertFalse(MigrationTables::usesDatabase('Manuglopez\\Replay\\Tests\\Unit\\Laravel\\Fixtures\\NeverDeclared'));
    }
}
