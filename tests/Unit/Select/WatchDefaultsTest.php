<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Select\WatchDefaults\Laravel;
use Manuglopez\Replay\Select\WatchDefaults\Php;
use Manuglopez\Replay\Select\WatchDefaults\Symfony;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class WatchDefaultsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('watch-defaults');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    // -- Php ---------------------------------------------------------------

    public function test_php_is_always_applicable(): void
    {
        self::assertTrue((new Php())->applicable($this->root));
    }

    public function test_php_maps_generic_patterns_to_every_test_directory(): void
    {
        $defaults = (new Php())->defaults($this->root, ['tests/Unit', 'tests/Feature']);

        self::assertSame(['tests/Unit', 'tests/Feature'], $defaults['.env*']);
        self::assertSame(['tests/Unit', 'tests/Feature'], $defaults['phpunit.xml*']);
        self::assertSame(['tests/Unit', 'tests/Feature'], $defaults['docker-compose*.y*ml']);
        self::assertSame(['tests/Unit'], $defaults['tests/Unit/**/Fixtures/**']);
        self::assertSame(['tests/Feature'], $defaults['tests/Feature/**/Fixtures/**']);
        self::assertSame(['tests/Unit'], $defaults['tests/Unit/**/__snapshots__/**']);
        self::assertSame(['tests/Feature'], $defaults['tests/Feature/**/__snapshots__/**']);
    }

    public function test_php_produces_no_patterns_without_test_directories(): void
    {
        self::assertSame([], (new Php())->defaults($this->root, []));
    }

    // -- Laravel -------------------------------------------------------------

    public function test_laravel_is_not_applicable_without_an_artisan_file(): void
    {
        self::assertFalse((new Laravel())->applicable($this->root));
    }

    public function test_laravel_is_applicable_when_artisan_exists(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        self::assertTrue((new Laravel())->applicable($this->root));
    }

    public function test_laravel_maps_every_pattern_to_every_test_directory(): void
    {
        $defaults = (new Laravel())->defaults($this->root, ['tests']);

        self::assertSame(['tests'], $defaults['config/**']);
        self::assertSame(['tests'], $defaults['routes/**']);
        self::assertSame(['tests'], $defaults['database/migrations/**']);
        self::assertSame(['tests'], $defaults['resources/views/**']);
        self::assertSame(['tests'], $defaults['lang/**']);
        self::assertSame(['tests'], $defaults['resources/lang/**']);
        self::assertSame(['tests'], $defaults['app/** !*.php']);
        self::assertSame(['tests'], $defaults['bootstrap/*.php']);
    }

    // -- Symfony -------------------------------------------------------------

    public function test_symfony_is_not_applicable_without_a_bundles_file(): void
    {
        self::assertFalse((new Symfony())->applicable($this->root));
    }

    public function test_symfony_is_applicable_when_config_bundles_php_exists(): void
    {
        TempDir::write($this->root . '/config/bundles.php', '<?php return [];');

        self::assertTrue((new Symfony())->applicable($this->root));
    }

    public function test_symfony_maps_every_pattern_to_every_test_directory(): void
    {
        $defaults = (new Symfony())->defaults($this->root, ['tests']);

        self::assertSame(['tests'], $defaults['config/**']);
        self::assertSame(['tests'], $defaults['migrations/**']);
        self::assertSame(['tests'], $defaults['templates/**']);
        self::assertSame(['tests'], $defaults['translations/**']);
    }
}
