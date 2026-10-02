<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Laravel\TestSchemaDump;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The schema dump the test database is built from, and the migrations it already holds:
 * only the dump of the connection the tests use is loaded (`migrate` reads
 * `database/schema/{connection}-schema.dump`, then `.sql`).
 */
final class TestSchemaDumpTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('test-schema-dump');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_the_connection_comes_from_the_phpunit_configuration_first(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="APP_ENV" value="testing"/><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
        $this->write('.env.testing', "DB_CONNECTION=pgsql\n");
        $this->write('.env', "DB_CONNECTION=mysql\n");

        self::assertSame('sqlite', TestSchemaDump::connection($this->root, null, []));
    }

    public function test_the_configuration_the_graph_was_recorded_with_wins_over_the_default_names(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
        $this->write('ci/phpunit.mariadb.xml', '<phpunit><php><server name="DB_CONNECTION" value="mariadb"/></php></phpunit>');

        self::assertSame('mariadb', TestSchemaDump::connection($this->root, 'ci/phpunit.mariadb.xml', []));
    }

    public function test_then_the_env_files_then_the_config_default(): void
    {
        $this->write('.env', "APP_NAME=x\nDB_CONNECTION=\"mysql\" # main\n");
        self::assertSame('mysql', TestSchemaDump::connection($this->root, null, []));

        $this->write('.env.testing', "DB_CONNECTION=pgsql\n");
        self::assertSame('pgsql', TestSchemaDump::connection($this->root, null, ['APP_ENV' => 'testing']), 'APP_ENV=testing loads .env.testing instead');

        TempDir::remove($this->root);
        $this->root = TempDir::make('test-schema-dump');
        $this->write('config/database.php', "<?php\nreturn ['default' => env('DB_CONNECTION', 'mariadb')];\n");
        self::assertSame('mariadb', TestSchemaDump::connection($this->root, null, []));

        TempDir::remove($this->root);
        $this->root = TempDir::make('test-schema-dump');
        self::assertNull(TestSchemaDump::connection($this->root, null, []), 'unknown: nothing is presumed squashed');
    }

    public function test_with_an_app_env_file_only_that_file_is_loaded(): void
    {
        // Laravel loads .env.testing INSTEAD of .env when APP_ENV=testing and it exists.
        $this->write('phpunit.xml', '<phpunit><php><env name="APP_ENV" value="testing"/></php></phpunit>');
        $this->write('.env.testing', "APP_ENV=testing\nDB_DATABASE=:memory:\n");
        $this->write('.env', "DB_CONNECTION=mysql\n");
        $this->write('config/database.php', "<?php return ['default' => env('DB_CONNECTION', 'sqlite')];");

        self::assertSame('sqlite', TestSchemaDump::connection($this->root, null, []));
    }

    public function test_the_process_environment_beats_a_phpunit_env_that_is_not_forced(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="mysql"/></php></phpunit>');
        $this->write('.env', "DB_CONNECTION=mysql\n");

        self::assertSame('sqlite', TestSchemaDump::connection($this->root, null, ['DB_CONNECTION' => 'sqlite']));
        self::assertSame('mysql', TestSchemaDump::connection($this->root, null, []));
    }

    public function test_a_forced_phpunit_env_wins_and_a_conflicting_process_value_is_ambiguous(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite" force="true"/></php></phpunit>');

        self::assertSame('sqlite', TestSchemaDump::connection($this->root, null, []));
        self::assertNull(TestSchemaDump::connection($this->root, null, ['DB_CONNECTION' => 'mysql']), 'Laravel reads $_SERVER first, which still holds the process value');
    }

    public function test_what_the_package_cannot_see_squashes_nothing(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
        $this->write('database/schema/sqlite-schema.sql', "CREATE TABLE \"users\"(\"id\" integer);\nINSERT INTO migrations VALUES(1,'2024_01_01_000000_create_users_table',1);\n");
        self::assertNotNull(TestSchemaDump::squashed($this->root, null, []));

        $this->write('tests/Feature/OtherDatabaseTest.php', "<?php\nfunction migrateFreshUsing() { return ['--database' => 'tenant']; }\n");
        self::assertNull(TestSchemaDump::squashed($this->root, null, []), 'migrates another connection');

        TempDir::remove($this->root . '/tests');
        $this->write('tests/Feature/SchemaPathTest.php', "<?php\nfunction migrateFreshUsing() { return ['--schema-path' => 'x.sql']; }\n");
        self::assertNull(TestSchemaDump::squashed($this->root, null, []), 'loads another dump');

        TempDir::remove($this->root . '/tests');
        $this->write('bootstrap/app.php', "<?php\n\$app->useDatabasePath(__DIR__ . '/../db');\n");
        self::assertNull(TestSchemaDump::squashed($this->root, null, []), 'database_path() is elsewhere');

        TempDir::remove($this->root . '/bootstrap');
        $this->write('.env', "DB_CONNECTION=\${OTHER}\n");
        $this->write('phpunit.xml', '<phpunit/>');
        self::assertNull(TestSchemaDump::connection($this->root, null, []), 'an interpolated value');
    }

    public function test_sql_server_has_no_dump(): void
    {
        // Laravel's migrate never loads a schema dump on a SqlServerConnection.
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlsrv"/></php></phpunit>');
        $this->write('database/schema/sqlsrv-schema.sql', "CREATE TABLE a (x int);\n");
        self::assertNull(TestSchemaDump::path($this->root, null, []));

        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="reports"/></php></phpunit>');
        $this->write('config/database.php', "<?php return ['connections' => ['reports' => ['driver' => 'sqlsrv', 'host' => 'x']]];");
        $this->write('database/schema/reports-schema.sql', "CREATE TABLE a (x int);\n");
        self::assertNull(TestSchemaDump::path($this->root, null, []));
    }

    public function test_the_dump_is_the_connection_s_dump_then_sql(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="pgsql"/></php></phpunit>');
        self::assertNull(TestSchemaDump::path($this->root, null, []));

        $this->write('database/schema/mysql-schema.sql', "CREATE TABLE a (x int);\n");
        self::assertNull(TestSchemaDump::path($this->root, null, []), 'another connection\'s dump is not loaded');

        $this->write('database/schema/pgsql-schema.sql', "CREATE TABLE public.a (x int);\n");
        self::assertSame('database/schema/pgsql-schema.sql', TestSchemaDump::path($this->root, null, []));

        $this->write('database/schema/pgsql-schema.dump', "PGDMP\x01");
        self::assertSame('database/schema/pgsql-schema.dump', TestSchemaDump::path($this->root, null, []));
    }

    public function test_squashed_migrations_are_the_dump_s_migration_rows(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
        self::assertNull(TestSchemaDump::squashed($this->root, null, []), 'no dump');

        $this->write('database/schema/sqlite-schema.sql', "CREATE TABLE IF NOT EXISTS \"users\"(\"id\" integer);\nINSERT INTO migrations VALUES(1,'2024_01_01_000000_create_users_table',1);\n");
        self::assertSame(['2024_01_01_000000_create_users_table' => true], TestSchemaDump::squashed($this->root, null, []));

        $this->write('database/schema/sqlite-schema.sql', "CREATE TABLE IF NOT EXISTS \"users\"(\"id\" integer);\n");
        self::assertNull(TestSchemaDump::squashed($this->root, null, []), 'rows that cannot be read squash nothing');

        $this->write('database/schema/sqlite-schema.sql', 'garbage');
        self::assertNull(TestSchemaDump::squashed($this->root, null, []));
    }

    private function write(string $relative, string $content): void
    {
        TempDir::write($this->root . '/' . $relative, $content);
    }
}
