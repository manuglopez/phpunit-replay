<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\MigrationPaths;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Where a Laravel project keeps migrations: `database/migrations`, the `migration_paths`
 * key, literal `loadMigrationsFrom()` calls in `app/Providers` and `bootstrap`, and the
 * tenancy package conventions the lock file proves.
 */
final class MigrationPathsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('migration-paths');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_the_framework_directory_is_always_one(): void
    {
        $paths = MigrationPaths::for($this->root, Config::defaults());

        self::assertSame(['database/migrations'], $paths->paths());
        self::assertTrue($paths->isMigration('database/migrations/2024_01_01_000000_create_users_table.php'));
        self::assertTrue($paths->isMigration('database/migrations/tenant/2024_01_01_000000_create_users_table.php'));
        self::assertFalse($paths->isMigration('database/migrations/seed.sql'), 'a migration is a .php file');
        self::assertFalse($paths->isMigration('database/migrations.php'));
        self::assertFalse($paths->isMigration('app/Models/User.php'));
    }

    public function test_configured_paths_are_added_and_normalised(): void
    {
        $config = Config::defaults()->with(['migrationPaths' => ['./modules/Billing/database/migrations/', 'database/tenant', '../outside', '/abs/elsewhere']]);
        $paths = MigrationPaths::for($this->root, $config);

        self::assertSame(['database/migrations', 'database/tenant', 'modules/Billing/database/migrations'], $paths->paths());
        self::assertTrue($paths->isMigration('database/tenant/2024_01_01_000000_create_shops_table.php'));
        self::assertTrue($paths->isMigration('modules/Billing/database/migrations/2024_01_01_000000_create_invoices_table.php'));
        self::assertFalse($paths->isMigration('database/tenantx/a.php'), 'a directory, not a prefix');
    }

    public function test_literal_load_migrations_from_calls_are_detected(): void
    {
        TempDir::write($this->root . '/app/Providers/TenancyServiceProvider.php', <<<'PHP'
            <?php
            namespace App\Providers;
            class TenancyServiceProvider extends \Illuminate\Support\ServiceProvider
            {
                public function boot(): void
                {
                    $this->loadMigrationsFrom(database_path('migrations/tenant'));
                    $this->loadMigrationsFrom([base_path('modules/Shop/migrations'), 'database/landlord']);
                    $this->loadMigrationsFrom(__DIR__ . '/../../packages/audit/migrations');
                    $this->loadMigrationsFrom(dirname(__DIR__, 2) . '/database/archive');
                    $this->loadMigrationsFrom($this->paths());
                    $this->loadMigrationsFrom(config('tenancy.migrations'));
                    $this->loadMigrationsFrom(database_path($dir));
                }
            }
            PHP);
        TempDir::write($this->root . '/app/Providers/Nested/ModuleProvider.php', "<?php\n\$this->loadMigrationsFrom(database_path('migrations/modules'));\n");
        TempDir::write($this->root . '/bootstrap/app.php', "<?php\n\$app->loadMigrationsFrom(base_path('database/boot'));\n");
        TempDir::write($this->root . '/bootstrap/cache/services.php', "<?php\n\$this->loadMigrationsFrom('database/never');\n");
        TempDir::write($this->root . '/app/Models/Thing.php', "<?php\n\$this->loadMigrationsFrom('database/not-a-provider');\n");

        $detected = MigrationPaths::detected($this->root);

        self::assertSame([
            'database/archive',
            'database/boot',
            'database/landlord',
            'database/migrations/modules',
            'database/migrations/tenant',
            'modules/Shop/migrations',
            'packages/audit/migrations',
        ], $detected);
    }

    public function test_a_file_that_does_not_parse_is_skipped(): void
    {
        TempDir::write($this->root . '/app/Providers/Broken.php', "<?php\n\$this->loadMigrationsFrom('database/x'\n");

        self::assertSame([], MigrationPaths::detected($this->root));
    }

    public function test_the_stancl_tenancy_convention_needs_the_package_in_the_lock_file(): void
    {
        self::assertNotContains('database/migrations/tenant', MigrationPaths::detected($this->root));

        TempDir::write($this->root . '/composer.lock', json_encode(['packages' => [['name' => 'stancl/tenancy']], 'packages-dev' => []], JSON_THROW_ON_ERROR));

        self::assertContains('database/migrations/tenant', MigrationPaths::detected($this->root));
    }

    public function test_fallback_patterns_cover_each_root_once(): void
    {
        $paths = MigrationPaths::of(['database/migrations', 'database/migrations/tenant', 'database/tenant', 'database/one_off.php']);

        self::assertSame(['database/migrations/**', 'database/one_off.php', 'database/tenant/**'], $paths->fallbackPatterns());
        self::assertTrue($paths->isMigration('database/one_off.php'));
    }
}
