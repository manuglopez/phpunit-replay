<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit;

use Manuglopez\Replay\Config;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;

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

    #[Group('AC-I1-d')]
    public function test_translations_mode_defaults_to_per_key_and_accepts_conservative(): void
    {
        self::assertSame('per-key', Config::defaults()->translations);
        self::assertSame('per-key', Config::fromArray([])->translations);
        self::assertSame('per-key', Config::fromArray(['translations' => 'per-key'])->translations);
        self::assertSame('conservative', Config::fromArray(['translations' => 'conservative'])->translations);
        self::assertSame('per-key', Config::fromArray(['translations' => 'loose'])->translations, 'an unknown value: the default');
        self::assertSame('per-key', Config::fromArray(['translations' => true])->translations, 'not a string: the default');
        self::assertSame('conservative', Config::defaults()->with(['translations' => 'conservative'])->translations);
        self::assertSame('per-key', Config::defaults()->with(['laravel' => 'off'])->translations, 'with() keeps what it is not asked to change');
    }

    #[Group('AC-I1-d')]
    public function test_the_extension_parameters_and_the_environment_leave_translations_alone(): void
    {
        $config = Config::fromArray(['translations' => 'conservative']);

        self::assertSame('conservative', $config->mergeEnv(['PHPUNIT_REPLAY_MODE' => 'record'])->translations, 'no environment override');
        self::assertSame('per-key', Config::fromExtensionParameters(ParameterCollection::fromArray([]))->translations);
    }
}
