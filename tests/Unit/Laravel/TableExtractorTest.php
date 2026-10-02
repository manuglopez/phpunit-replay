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

    public function test_a_parenthesised_union_names_both_tables(): void
    {
        // How Laravel's MySQL and PostgreSQL grammars wrap a union.
        self::assertSame(['comments', 'posts'], TableExtractor::fromSql('(select `id` from `posts`) union (select `id` from `comments`)'));
    }

    public function test_a_comma_join_names_every_table(): void
    {
        self::assertSame(['a', 'b'], TableExtractor::fromSql('select * from `a`, `b` where a.id = b.id'));
        self::assertSame(['orders', 'users'], TableExtractor::fromSql('select * from users u, public.orders as o where o.user_id = u.id'));
        self::assertSame(['users'], TableExtractor::fromSql('select * from users where id in (1, 2, 3)'), 'commas after the from list are not tables');
    }

    public function test_the_shapes_a_review_found_missing(): void
    {
        self::assertSame(['users'], TableExtractor::fromSql('/* a comment */ select * from users'));
        self::assertSame(['users'], TableExtractor::fromSql("-- a comment\nselect * from users"));
        self::assertSame(['order', 'tags', 'users'], TableExtractor::fromSql('select * from `users`, `order`, `tags` where 1'), 'a reserved word quoted as a name');
        self::assertSame(['a', 'b', 'c'], TableExtractor::fromSql('select * from a, (select id from b) x, c'));
        self::assertSame(['posts', 'users'], TableExtractor::fromSql('TRUNCATE users, posts'));
        self::assertSame(['users'], TableExtractor::fromSql('truncate "users" restart identity cascade'));
        self::assertSame(['a', 'b'], TableExtractor::fromSql('update `a`, `b` set a.x = b.x'));
        self::assertSame(['a', 'b'], TableExtractor::fromSql('delete from a using b where a.id = b.id'));
        self::assertSame(['users'], TableExtractor::fromSql("select * from `users` where name = 'from posts'"), 'a string is not a table');
        self::assertSame(['a', 'b'], TableExtractor::fromSql('select * from a join b using (id)'));
    }

    public function test_a_statement_whose_tables_cannot_be_read_is_unknown(): void
    {
        self::assertSame([TableExtractor::UNKNOWN], TableExtractor::fromSql('call refresh_totals()'));
        self::assertSame([TableExtractor::UNKNOWN], TableExtractor::fromSql('EXEC dbo.refresh'));
        self::assertSame(['logs'], TableExtractor::fromSql('truncate table "logs"'));
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

    public function test_an_aliased_db_table_names_the_table_not_the_alias(): void
    {
        self::assertSame(['posts'], TableExtractor::fromMigrationSource("DB::table('posts as p')->update(['x' => 1]);"));
        self::assertSame(['posts'], TableExtractor::fromMigrationSource("DB::table('posts AS p')->update(['x' => 1]);"));
        self::assertSame(['posts', 'users'], TableExtractor::fromSql('select * from "posts" as "p" inner join "users" as "u" on "u"."id" = "p"."user_id"'));
    }

    public function test_a_table_named_through_a_variable_is_unresolved_never_guessed(): void
    {
        $php = <<<'PHP'
            Schema::create('users', function ($table) {});
            Schema::create($tableNames['roles'], function ($table) {});
            PHP;

        self::assertSame(['tables' => ['users'], 'unresolved' => true], TableExtractor::migrationTables($php));
        self::assertSame(['tables' => [], 'unresolved' => true], TableExtractor::migrationTables('DB::table($table)->delete();'));
        self::assertSame(['tables' => [], 'unresolved' => true], TableExtractor::migrationTables('Schema::table(config("x.table"), fn ($t) => null);'));
    }

    public function test_literal_tables_are_resolved(): void
    {
        self::assertSame(['tables' => ['posts'], 'unresolved' => false], TableExtractor::migrationTables("Schema::table('posts', fn (\$t) => null);"));
        self::assertSame(['tables' => ['audits'], 'unresolved' => false], TableExtractor::migrationTables("Schema::connection('logs')->create('audits', fn (\$t) => null);"));
        self::assertSame(['tables' => [], 'unresolved' => false], TableExtractor::migrationTables('return new class extends Migration {};'));
    }
}
