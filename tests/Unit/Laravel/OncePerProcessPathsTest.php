<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Laravel\OncePerProcessPaths;
use PHPUnit\Framework\TestCase;

/**
 * Pure path classification (docs/reproducibility.md "Once-per-process residue"): no
 * filesystem access, no Illuminate, no application boot — the convention path is the
 * whole signal, on purpose (see the class docblock for why that is sound here and would
 * not be for `app/Services/*` or a model/factory).
 */
final class OncePerProcessPathsTest extends TestCase
{
    private OncePerProcessPaths $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paths = new OncePerProcessPaths();
    }

    public function test_a_migration_matches(): void
    {
        self::assertTrue($this->paths->matches('database/migrations/2024_01_01_000000_create_users_table.php'));
    }

    public function test_a_seeder_matches(): void
    {
        self::assertTrue($this->paths->matches('database/seeders/DatabaseSeeder.php'));
    }

    public function test_a_console_command_matches(): void
    {
        self::assertTrue($this->paths->matches('app/Console/Commands/BackfillRecoveryLeads.php'));
    }

    public function test_a_nested_console_command_matches(): void
    {
        self::assertTrue($this->paths->matches('app/Console/Commands/Reports/DailySummary.php'));
    }

    /**
     * The measured false-green movers this class deliberately leaves alone
     * (docs/reproducibility.md): a service class, a factory and a model. None of these
     * paths says "executes once per process" on its own — see the class docblock.
     */
    public function test_a_service_class_does_not_match(): void
    {
        self::assertFalse($this->paths->matches('app/Services/RecoveryLeadService.php'));
    }

    public function test_a_factory_does_not_match(): void
    {
        self::assertFalse($this->paths->matches('database/factories/UserFactory.php'));
    }

    public function test_a_model_does_not_match(): void
    {
        self::assertFalse($this->paths->matches('app/Models/User.php'));
    }

    /** An unconventional layout (docs/reproducibility.md "known limitation") is simply unknown to this class. */
    public function test_a_module_migration_outside_the_convention_path_does_not_match(): void
    {
        self::assertFalse($this->paths->matches('Modules/Billing/Database/Migrations/2024_01_01_000000_create_invoices_table.php'));
    }

    /** A sibling directory that merely starts with the same prefix must not match (boundary, not substring). */
    public function test_a_directory_that_merely_starts_with_the_prefix_does_not_match(): void
    {
        self::assertFalse($this->paths->matches('database/migrations-backup/2024_01_01_000000_create_users_table.php'));
        self::assertFalse($this->paths->matches('database/seeders-old/DatabaseSeeder.php'));
        self::assertFalse($this->paths->matches('app/Console/CommandsLegacy/Foo.php'));
    }

    /** Matching is exact-case, like every other convention path this package hardcodes (Laravel\Rules\MigrationRule). */
    public function test_matching_is_case_sensitive(): void
    {
        self::assertFalse($this->paths->matches('Database/Migrations/2024_01_01_000000_create_users_table.php'));
        self::assertFalse($this->paths->matches('DATABASE/SEEDERS/DatabaseSeeder.php'));
    }

    public function test_a_test_file_does_not_match(): void
    {
        self::assertFalse($this->paths->matches('tests/Feature/UserModelTest.php'));
    }
}
