<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Laravel\SchemaDump;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `php artisan schema:dump` output, per dialect, split into per-table blocks: what
 * `Laravel\Rules\SchemaDumpRule` diffs and `Select\NonEdgeInputs` hashes per test file.
 */
final class SchemaDumpTest extends TestCase
{
    private const MYSQL = <<<'SQL'
        /*!40101 SET @saved_cs_client     = @@character_set_client */;
        /*!50503 SET character_set_client = utf8mb4 */;
        DROP TABLE IF EXISTS `users`;
        CREATE TABLE `users` (
          `id` bigint unsigned NOT NULL,
          `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'it''s; fine',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        /*!40101 SET character_set_client = @saved_cs_client */;
        DROP TABLE IF EXISTS `parcels`;
        CREATE TABLE `parcels` (
          `id` bigint unsigned NOT NULL,
          `note` varchar(10) NOT NULL DEFAULT 'a\';b',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB;
        DELIMITER ;;
        /*!50003 CREATE*/ /*!50017 DEFINER=`root`@`%`*/ /*!50003 TRIGGER `parcels_bi` BEFORE INSERT ON `parcels` FOR EACH ROW BEGIN SET NEW.note = 'x'; END */;;
        DELIMITER ;
        INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2024_01_01_000000_create_users_table',1);
        INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2024_01_02_000000_create_parcels_table',1);

        SQL;

    private const POSTGRES = <<<'SQL'
        --
        -- PostgreSQL database dump
        --

        \restrict 3f9c2d

        SET statement_timeout = 0;
        SELECT pg_catalog.set_config('search_path', '', false);

        CREATE FUNCTION public.touch() RETURNS trigger
            LANGUAGE plpgsql
            AS $$ BEGIN NEW.updated_at := now(); RETURN NEW; END; $$;

        CREATE TABLE public.users (
            id bigint NOT NULL,
            email character varying(255) NOT NULL
        );

        CREATE SEQUENCE public.users_id_seq
            START WITH 1
            INCREMENT BY 1;

        ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;

        CREATE TABLE public.orders (
            id bigint NOT NULL,
            user_id bigint NOT NULL
        );

        ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);

        ALTER TABLE ONLY public.users
            ADD CONSTRAINT users_pkey PRIMARY KEY (id);

        CREATE INDEX orders_user_id_index ON public.orders USING btree (user_id);

        CREATE TRIGGER users_touch BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.touch();

        \unrestrict 3f9c2d

        COPY public.migrations (id, migration, batch) FROM stdin;
        1	2024_01_01_000000_create_users_table	1
        2	2024_01_02_000000_create_orders_table	1
        \.

        SELECT pg_catalog.setval('public.migrations_id_seq', 2, true);

        SQL;

    private const SQLITE = <<<'SQL'
        CREATE TABLE IF NOT EXISTS "migrations"(
          "id" integer primary key autoincrement not null,
          "migration" varchar not null,
          "batch" integer not null
        );
        CREATE TABLE IF NOT EXISTS "users"(
          "id" integer primary key autoincrement not null,
          "email" varchar not null
        );
        CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
        CREATE TABLE IF NOT EXISTS "posts"(
          "id" integer primary key autoincrement not null,
          "user_id" integer not null
        );
        CREATE TRIGGER posts_guard BEFORE DELETE ON "posts" BEGIN SELECT RAISE(ABORT, 'no'); END;
        INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
        INSERT INTO migrations VALUES(2,'0001_01_02_000000_create_posts_table',1);

        SQL;

    public function test_mysql_tables_their_triggers_and_the_migration_rows(): void
    {
        $dump = SchemaDump::parse(self::MYSQL);

        self::assertNotNull($dump);
        self::assertSame(['parcels', 'users'], $dump->tables());
        self::assertStringContainsString('TRIGGER `parcels_bi`', $dump->block('parcels'));
        self::assertStringContainsString("'it''s; fine'", $dump->block('users'));
        self::assertStringContainsString("'a\\';b'", $dump->block('parcels'), 'a backslash-escaped quote does not end the string');
        self::assertSame('', $dump->global(), 'SET statements are noise');
        self::assertSame(['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_parcels_table'], $dump->migrations());
    }

    public function test_postgres_attributes_constraints_indexes_sequences_and_triggers_to_their_table(): void
    {
        $dump = SchemaDump::parse(self::POSTGRES);

        self::assertNotNull($dump);
        self::assertSame(['orders', 'users'], $dump->tables());
        self::assertStringContainsString('users_pkey', $dump->block('users'));
        self::assertStringContainsString('CREATE SEQUENCE public.users_id_seq', $dump->block('users'), 'an owned sequence is its table\'s');
        self::assertStringContainsString('CREATE TRIGGER users_touch', $dump->block('users'));
        self::assertStringContainsString('orders_user_id_index', $dump->block('orders'));
        self::assertStringContainsString('CREATE FUNCTION public.touch()', $dump->global(), 'a function belongs to no table');
        self::assertStringNotContainsString('restrict', $dump->global() . implode('', array_map($dump->block(...), $dump->tables())));
        self::assertSame(['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_orders_table'], $dump->migrations());
    }

    public function test_sqlite_tables_indexes_triggers_and_rows(): void
    {
        $dump = SchemaDump::parse(self::SQLITE);

        self::assertNotNull($dump);
        self::assertSame(['migrations', 'posts', 'users'], $dump->tables());
        self::assertStringContainsString('users_email_unique', $dump->block('users'));
        self::assertStringContainsString("RAISE(ABORT, 'no'); END", $dump->block('posts'), 'a trigger body keeps its semicolons');
        self::assertSame(['0001_01_01_000000_create_users_table', '0001_01_02_000000_create_posts_table'], $dump->migrations());
    }

    public function test_the_diff_names_added_removed_and_changed_tables_only(): void
    {
        $old = SchemaDump::parse(self::SQLITE);
        $new = SchemaDump::parse(str_replace(
            ['"user_id" integer not null', 'CREATE TABLE IF NOT EXISTS "users"('],
            ['"user_id" integer not null, "title" varchar', "CREATE TABLE IF NOT EXISTS \"tags\"(\"id\" integer);\nCREATE TABLE IF NOT EXISTS \"users\"("],
            self::SQLITE,
        ));

        self::assertNotNull($old);
        self::assertNotNull($new);
        self::assertSame(['posts', 'tags'], SchemaDump::changedTables($old, $new));
        self::assertSame(['posts', 'tags'], SchemaDump::changedTables($new, $old));
        self::assertFalse(SchemaDump::globalChanged($old, $new));
    }

    public function test_whitespace_and_comments_do_not_change_a_block(): void
    {
        $old = SchemaDump::parse(self::POSTGRES);
        $new = SchemaDump::parse(str_replace(
            ['-- PostgreSQL database dump', "    email character varying(255) NOT NULL\n", '\\restrict 3f9c2d', '\\unrestrict 3f9c2d'],
            ['-- PostgreSQL database dump, dumped again', "    email   character varying(255)  NOT NULL -- a note\n", '\\restrict 77aa01', '\\unrestrict 77aa01'],
            self::POSTGRES,
        ));

        self::assertNotNull($new);
        self::assertSame([], SchemaDump::changedTables($old, $new));
    }

    public function test_only_migration_rows_changing_changes_no_table(): void
    {
        $old = SchemaDump::parse(self::MYSQL);
        $new = SchemaDump::parse(self::MYSQL . "INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2024_01_03_000000_add_note',2);\n");

        self::assertNotNull($old);
        self::assertNotNull($new);
        self::assertSame([], SchemaDump::changedTables($old, $new));
        self::assertFalse(SchemaDump::globalChanged($old, $new));
        self::assertNotSame($old->migrations(), $new->migrations());
    }

    public function test_a_changed_function_is_a_global_change(): void
    {
        $old = SchemaDump::parse(self::POSTGRES);
        $new = SchemaDump::parse(str_replace('RETURN NEW; END;', 'RETURN NULL; END;', self::POSTGRES));

        self::assertNotNull($old);
        self::assertNotNull($new);
        self::assertSame([], SchemaDump::changedTables($old, $new));
        self::assertTrue(SchemaDump::globalChanged($old, $new));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'unterminated string' => ["CREATE TABLE `a` (`x` varchar(3) DEFAULT 'oops);\n"];
        yield 'unterminated comment' => ["CREATE TABLE a (x int);\n/* never closed\n"];
        yield 'unterminated dollar quote' => ["CREATE TABLE public.a (x int);\nCREATE FUNCTION f() AS \$\$ BEGIN;\n"];
        yield 'statement without a terminator' => ["CREATE TABLE a (x int);\nCREATE TABLE b (y int)\n"];
        yield 'no table at all' => ["SET x = 1;\n"];
        yield 'empty' => [''];
        yield 'a pg_dump custom-format archive' => ["PGDMP\x01\x0e\x00\x04\x08\x01\x01\x00binary"];
        yield 'garbage' => ["this is not SQL at all\n"];
        yield 'an unreadable CREATE TABLE' => ["CREATE TABLE (x int);\n"];
    }

    #[DataProvider('malformed')]
    public function test_malformed_input_does_not_parse(string $sql): void
    {
        self::assertNull(SchemaDump::parse($sql));
    }

    public function test_migration_rows_that_cannot_be_read_are_null_not_empty(): void
    {
        $dump = SchemaDump::parse("CREATE TABLE a (x int);\n");

        self::assertNotNull($dump);
        self::assertNull($dump->migrations(), 'no rows: whether a migration is squashed is unknown');
    }

    public function test_a_foreign_key_relates_both_tables(): void
    {
        // A child's ON DELETE change alters only the child's block, and changes what deleting
        // a parent does.
        $dump = SchemaDump::parse("CREATE TABLE `users` (`id` int);\nCREATE TABLE `posts` (`id` int, `user_id` int, CONSTRAINT `p_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE);\nCREATE TABLE `tags` (`id` int);\n");

        self::assertNotNull($dump);
        self::assertSame(['posts', 'users'], SchemaDump::related(['posts'], $dump));
        self::assertSame(['posts', 'users'], SchemaDump::related(['users'], $dump));
        self::assertSame(['tags'], SchemaDump::related(['tags'], $dump));
    }

    public function test_a_trigger_relates_its_table_to_the_tables_its_body_writes(): void
    {
        $mysql = "CREATE TABLE `users` (`id` int);\nCREATE TABLE `audit_log` (`user_id` int);\nDELIMITER ;;\n/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`%`*/ /*!50003 TRIGGER `users_ai` AFTER INSERT ON `users` FOR EACH ROW INSERT INTO audit_log(user_id) VALUES (NEW.id) */;;\nDELIMITER ;\n";
        $sqlite = "CREATE TABLE IF NOT EXISTS \"users\"(\"id\" integer);\nCREATE TABLE IF NOT EXISTS \"audit_log\"(\"user_id\" integer);\nCREATE TRIGGER users_audit AFTER INSERT ON users BEGIN INSERT INTO audit_log(user_id) VALUES (new.id); END;\n";

        foreach ([$mysql, $sqlite] as $sql) {
            $dump = SchemaDump::parse($sql);
            self::assertNotNull($dump);
            self::assertSame(['audit_log', 'users'], SchemaDump::related(['audit_log'], $dump));
        }
    }

    public function test_a_postgres_trigger_follows_its_function(): void
    {
        $sql = "CREATE TABLE public.users (id bigint);\nCREATE TABLE public.audit_log (user_id bigint);\nCREATE TABLE public.tags (id bigint);\n"
            . "CREATE FUNCTION public.audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN INSERT INTO public.audit_log (user_id) VALUES (NEW.id); RETURN NEW; END; \$\$;\n"
            . "CREATE TRIGGER users_audit AFTER INSERT ON public.users FOR EACH ROW EXECUTE FUNCTION public.audit();\n";
        $dump = SchemaDump::parse($sql);

        self::assertNotNull($dump);
        self::assertSame(['audit_log', 'users'], SchemaDump::related(['audit_log'], $dump));

        $missing = SchemaDump::parse(str_replace('CREATE FUNCTION public.audit()', 'CREATE FUNCTION public.other()', $sql));
        self::assertNotNull($missing);
        self::assertSame([SchemaDump::EVERY_TABLE], SchemaDump::related(['tags'], $missing), 'a trigger whose function is not in the dump relates to everything');
    }

    public function test_a_view_relates_to_the_tables_it_selects_from(): void
    {
        $sql = "CREATE TABLE `users` (`id` int, `active` tinyint);\n/*!50001 DROP VIEW IF EXISTS `active_users`*/;\n/*!50001 CREATE ALGORITHM=UNDEFINED */\n/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */\n/*!50001 VIEW `active_users` AS select `users`.`id` AS `id` from `users` where `users`.`active` = 1 */;\n";
        $dump = SchemaDump::parse($sql);

        self::assertNotNull($dump);
        self::assertSame(['active_users', 'users'], $dump->tables());
        self::assertSame('', $dump->global(), 'DROP VIEW belongs to the view');
        self::assertSame(['active_users', 'users'], SchemaDump::related(['users'], $dump));
    }

    public function test_dynamic_sql_in_a_trigger_relates_to_everything(): void
    {
        $dump = SchemaDump::parse("CREATE TABLE IF NOT EXISTS \"users\"(\"id\" integer);\nCREATE TABLE IF NOT EXISTS \"tags\"(\"id\" integer);\nDELIMITER ;;\nCREATE TRIGGER t AFTER INSERT ON users FOR EACH ROW CALL refresh_totals() ;;\nDELIMITER ;\n");

        self::assertNotNull($dump);
        self::assertSame([SchemaDump::EVERY_TABLE], SchemaDump::related(['tags'], $dump));
    }

    public function test_the_migration_rows_have_their_own_hash(): void
    {
        $old = SchemaDump::parse(self::MYSQL);
        $new = SchemaDump::parse(self::MYSQL . "INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2024_01_03_000000_x',2);\n");
        $whitespace = SchemaDump::parse(str_replace("\n  `", "\n      `", self::MYSQL) . "\n\n-- dumped again\n");

        self::assertNotNull($old);
        self::assertNotNull($new);
        self::assertNotNull($whitespace);
        self::assertNotSame($old->rowsHash(), $new->rowsHash());
        self::assertNotSame($old->normalisedHash(), $new->normalisedHash());
        self::assertSame($old->normalisedHash(), $whitespace->normalisedHash(), 'comments and whitespace only');
    }

    public function test_the_dump_path_shape(): void
    {
        self::assertTrue(SchemaDump::isDumpPath('database/schema/mysql-schema.sql'));
        self::assertTrue(SchemaDump::isDumpPath('database/schema/pgsql-schema.dump'));
        self::assertTrue(SchemaDump::isDumpPath('database/schema/tenant_db-schema.sql'));
        self::assertFalse(SchemaDump::isDumpPath('database/schema/notes.sql'));
        self::assertFalse(SchemaDump::isDumpPath('database/schema/old/mysql-schema.sql'));
        self::assertFalse(SchemaDump::isDumpPath('database/migrations/mysql-schema.sql'));
    }
}
