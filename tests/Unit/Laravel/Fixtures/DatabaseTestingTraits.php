<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for Laravel's database-refreshing testing traits, so
 * MigrationTablesTest can exercise MigrationTables::usesDatabase() by reflection without the
 * package (or its dev dependencies) ever depending on illuminate/*. Loaded once via
 * require_once — never autoloaded (its namespace does not match any PSR-4 prefix).
 */

namespace Illuminate\Foundation\Testing;

trait RefreshDatabase
{
}

trait DatabaseMigrations
{
}

trait DatabaseTransactions
{
}

namespace Manuglopez\Replay\Tests\Unit\Laravel\Fixtures;

class PlainTestCase
{
}

class RefreshDatabaseTestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
}

class DatabaseMigrationsTestCase
{
    use \Illuminate\Foundation\Testing\DatabaseMigrations;
}

class DatabaseTransactionsTestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;
}

class ChildOfRefreshDatabaseTestCase extends RefreshDatabaseTestCase
{
}
