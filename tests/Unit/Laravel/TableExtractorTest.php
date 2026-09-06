<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Laravel\TableExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Ports Pest's TableExtractor test cases (MIT).
 * @see https://github.com/pestphp/pest/blob/17d709e/tests/Unit/Plugins/Tia/TableExtractor.php
 */
final class TableExtractorTest extends TestCase
{
    // fromSql()

    public function test_extracts_tables_from_plain_dml(): void
    {
        self::assertSame(['users'], TableExtractor::fromSql('select * from users'));
        self::assertSame(['orders'], TableExtractor::fromSql('INSERT INTO orders (id) VALUES (1)'));
        self::assertSame(['posts'], TableExtractor::fromSql('UPDATE posts SET title = ?'));
        self::assertSame(['sessions'], TableExtractor::fromSql('DELETE FROM sessions WHERE id = ?'));
    }

    public function test_extracts_tables_from_joins(): void
    {
        self::assertSame(
            ['orders', 'users'],
            TableExtractor::fromSql('select * from orders join users on users.id = orders.user_id'),
        );
    }

    public function test_extracts_tables_from_cte_queries(): void
    {
        $sql = 'WITH recent AS (SELECT * FROM orders WHERE created_at > ?) SELECT * FROM recent JOIN users ON users.id = recent.user_id';

        self::assertSame(['orders', 'recent', 'users'], TableExtractor::fromSql($sql));
    }

    public function test_extracts_tables_from_replace_into(): void
    {
        self::assertSame(
            ['settings'],
            TableExtractor::fromSql('REPLACE INTO settings (key, value) VALUES (?, ?)'),
        );
    }

    public function test_records_the_table_not_the_schema_for_qualified_identifiers(): void
    {
        self::assertSame(['users'], TableExtractor::fromSql('select * from public.users'));
        self::assertSame(['users'], TableExtractor::fromSql('select * from "public"."users"'));
        self::assertSame(['events'], TableExtractor::fromSql('select * from `analytics`.`events`'));
        self::assertSame(['posts'], TableExtractor::fromSql('UPDATE public.posts SET title = ?'));
    }

    public function test_handles_quoted_identifiers(): void
    {
        self::assertSame(['users'], TableExtractor::fromSql('select * from "users"'));
        self::assertSame(['users'], TableExtractor::fromSql('select * from `users`'));
        self::assertSame(['users'], TableExtractor::fromSql('select * from [users]'));
    }

    public function test_ignores_schema_metadata_tables(): void
    {
        self::assertSame([], TableExtractor::fromSql("select * from sqlite_master where type = 'table'"));
        self::assertSame([], TableExtractor::fromSql('select * from pg_catalog.pg_tables'));
        self::assertSame([], TableExtractor::fromSql('select * from information_schema.tables'));
    }

    public function test_does_not_leak_int_keys_for_numeric_identifiers(): void
    {
        foreach (TableExtractor::fromSql('select substring(name from 1 for 3) from users') as $table) {
            self::assertIsString($table);
        }
    }

    public function test_returns_nothing_for_non_dml_statements(): void
    {
        self::assertSame([], TableExtractor::fromSql('PRAGMA foreign_keys = ON'));
        self::assertSame([], TableExtractor::fromSql(''));
        self::assertSame([], TableExtractor::fromSql('   '));
    }

    // fromMigrationSource()

    public function test_extracts_tables_from_schema_builder_calls(): void
    {
        $php = <<<'PHP'
        Schema::create('users', function (Blueprint $table) {});
        Schema::table('orders', function (Blueprint $table) {});
        Schema::rename('old_posts', 'posts');
        Schema::dropIfExists('sessions');
        PHP;

        self::assertSame(
            ['old_posts', 'orders', 'posts', 'sessions', 'users'],
            TableExtractor::fromMigrationSource($php),
        );
    }

    public function test_extracts_tables_from_raw_ddl_statements(): void
    {
        $php = <<<'PHP'
        DB::statement('ALTER TABLE users ADD COLUMN age INT');
        DB::statement('CREATE TABLE IF NOT EXISTS invoices (id INT)');
        PHP;

        self::assertSame(['invoices', 'users'], TableExtractor::fromMigrationSource($php));
    }

    public function test_records_the_table_not_the_schema_in_qualified_ddl_and_dml(): void
    {
        $php = <<<'PHP'
        DB::statement('ALTER TABLE public.users ADD COLUMN age INT');
        DB::statement('CREATE TABLE "analytics"."events" (id INT)');
        DB::statement('INSERT INTO public.settings (key) VALUES (1)');
        DB::statement('DELETE FROM `public`.`sessions`');
        DB::table('public.audits')->delete();
        PHP;

        self::assertSame(
            ['audits', 'events', 'sessions', 'settings', 'users'],
            TableExtractor::fromMigrationSource($php),
        );
    }

    public function test_does_not_leak_int_keys_for_numeric_table_names(): void
    {
        self::assertSame(['123'], TableExtractor::fromMigrationSource("DB::table('123')->insert([]);"));
    }

    public function test_extracts_tables_from_db_table_calls(): void
    {
        self::assertSame(
            ['permissions'],
            TableExtractor::fromMigrationSource("DB::table('permissions')->insert([]);"),
        );
    }

    public function test_drops_migrations_and_sqlite_and_pg_and_information_schema_meta_tables_from_migration_source(): void
    {
        $php = <<<'PHP'
        DB::statement('DELETE FROM migrations');
        DB::statement('DELETE FROM sqlite_sequence');
        DB::statement('UPDATE pg_stat_activity SET x = 1');
        DB::statement('CREATE TABLE information_schema.columns (id INT)');
        DB::table('posts')->delete();
        PHP;

        self::assertSame(['posts'], TableExtractor::fromMigrationSource($php));
    }

    public function test_returns_empty_for_migration_source_with_no_table_references(): void
    {
        self::assertSame([], TableExtractor::fromMigrationSource('return new class extends Migration {};'));
    }
}
