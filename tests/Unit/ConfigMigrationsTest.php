<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit;

use Manuglopez\Replay\Config;
use PHPUnit\Framework\TestCase;

/** `migration_paths` and `migrations` (docs/configuration.md). */
final class ConfigMigrationsTest extends TestCase
{
    public function test_migration_paths_default_to_the_framework_s_directory(): void
    {
        self::assertSame(['database/migrations'], Config::defaults()->migrationPaths);
        self::assertSame(['database/migrations'], Config::fromArray([])->migrationPaths);
    }

    public function test_migration_paths_are_read_as_a_list_of_strings(): void
    {
        $config = Config::fromArray(['migration_paths' => ['database/migrations', 'modules/Billing/database/migrations', 42]]);

        self::assertSame(['database/migrations', 'modules/Billing/database/migrations'], $config->migrationPaths);
        self::assertSame(['database/migrations'], Config::fromArray(['migration_paths' => 'database/tenant'])->migrationPaths, 'not a list: the default');
        self::assertSame(['database/tenant'], $config->with(['migrationPaths' => ['database/tenant']])->migrationPaths);
    }

    public function test_schema_dump_mode_defaults_to_conservative_and_accepts_per_table(): void
    {
        self::assertSame('conservative', Config::defaults()->schemaDump);
        self::assertSame('per-table', Config::fromArray(['schema_dump' => 'per-table'])->schemaDump);
        self::assertSame('conservative', Config::fromArray(['schema_dump' => 'by-table'])->schemaDump);
        self::assertSame('per-table', Config::defaults()->with(['schemaDump' => 'per-table'])->schemaDump);
    }

    public function test_migrations_mode_defaults_to_precise_and_accepts_conservative(): void
    {
        self::assertSame('precise', Config::defaults()->migrations);
        self::assertSame('conservative', Config::fromArray(['migrations' => 'conservative'])->migrations);
        self::assertSame('precise', Config::fromArray(['migrations' => 'loose'])->migrations);
        self::assertSame('conservative', Config::defaults()->with(['migrations' => 'conservative'])->migrations);
    }
}
