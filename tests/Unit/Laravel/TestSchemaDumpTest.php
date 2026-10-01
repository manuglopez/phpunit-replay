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

        self::assertSame('sqlite', TestSchemaDump::connection($this->root, null));
    }

    public function test_the_configuration_the_graph_was_recorded_with_wins_over_the_default_names(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
        $this->write('ci/phpunit.mariadb.xml', '<phpunit><php><server name="DB_CONNECTION" value="mariadb"/></php></phpunit>');

        self::assertSame('mariadb', TestSchemaDump::connection($this->root, 'ci/phpunit.mariadb.xml'));
    }

    public function test_then_the_env_files_then_the_config_default(): void
    {
        $this->write('.env', "APP_NAME=x\nDB_CONNECTION=\"mysql\" # main\n");
        self::assertSame('mysql', TestSchemaDump::connection($this->root, null));

        $this->write('.env.testing', "DB_CONNECTION=pgsql\n");
        self::assertSame('pgsql', TestSchemaDump::connection($this->root, null));

        TempDir::remove($this->root);
        $this->root = TempDir::make('test-schema-dump');
        $this->write('config/database.php', "<?php\nreturn ['default' => env('DB_CONNECTION', 'mariadb')];\n");
        self::assertSame('mariadb', TestSchemaDump::connection($this->root, null));

        TempDir::remove($this->root);
        $this->root = TempDir::make('test-schema-dump');
        self::assertNull(TestSchemaDump::connection($this->root, null), 'unknown: nothing is presumed squashed');
    }

    public function test_the_dump_is_the_connection_s_dump_then_sql(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="pgsql"/></php></phpunit>');
        self::assertNull(TestSchemaDump::path($this->root, null));

        $this->write('database/schema/mysql-schema.sql', "CREATE TABLE a (x int);\n");
        self::assertNull(TestSchemaDump::path($this->root, null), 'another connection\'s dump is not loaded');

        $this->write('database/schema/pgsql-schema.sql', "CREATE TABLE public.a (x int);\n");
        self::assertSame('database/schema/pgsql-schema.sql', TestSchemaDump::path($this->root, null));

        $this->write('database/schema/pgsql-schema.dump', "PGDMP\x01");
        self::assertSame('database/schema/pgsql-schema.dump', TestSchemaDump::path($this->root, null));
    }

    public function test_squashed_migrations_are_the_dump_s_migration_rows(): void
    {
        $this->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
        self::assertNull(TestSchemaDump::squashed($this->root, null), 'no dump');

        $this->write('database/schema/sqlite-schema.sql', "CREATE TABLE IF NOT EXISTS \"users\"(\"id\" integer);\nINSERT INTO migrations VALUES(1,'2024_01_01_000000_create_users_table',1);\n");
        self::assertSame(['2024_01_01_000000_create_users_table' => true], TestSchemaDump::squashed($this->root, null));

        $this->write('database/schema/sqlite-schema.sql', "CREATE TABLE IF NOT EXISTS \"users\"(\"id\" integer);\n");
        self::assertNull(TestSchemaDump::squashed($this->root, null), 'rows that cannot be read squash nothing');

        $this->write('database/schema/sqlite-schema.sql', 'garbage');
        self::assertNull(TestSchemaDump::squashed($this->root, null));
    }

    private function write(string $relative, string $content): void
    {
        TempDir::write($this->root . '/' . $relative, $content);
    }
}
