<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\LaravelDetector;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * `LaravelDetector::enabled()` is deliberately file/config-based only (see its docblock):
 * an `artisan` file at the project root plus `config.laravel !== 'off'`, with no
 * `class_exists()` check — it must give the same answer whether or not this process
 * happens to have Illuminate loaded, since the wrapper process (unlike the PHPUnit
 * process `LaravelIntegration::shouldArm()` runs in, see LaravelIntegrationTest) is not
 * guaranteed to.
 */
final class LaravelDetectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('laravel-detector');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        parent::tearDown();
    }

    public function test_disabled_when_config_laravel_is_off_even_with_an_artisan_file(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        $config = Config::fromArray(['laravel' => 'off']);

        self::assertFalse(LaravelDetector::enabled($this->root, $config));
    }

    public function test_disabled_without_an_artisan_file(): void
    {
        $config = Config::fromArray(['laravel' => 'auto']);

        self::assertFalse(LaravelDetector::enabled($this->root, $config));
    }

    public function test_enabled_with_auto_and_an_artisan_file_regardless_of_illuminate_being_loaded(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        $config = Config::fromArray(['laravel' => 'auto']);

        self::assertTrue(LaravelDetector::enabled($this->root, $config));
    }

    public function test_on_behaves_like_auto(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        $config = Config::fromArray(['laravel' => 'on']);

        self::assertTrue(LaravelDetector::enabled($this->root, $config));
    }

    public function test_off_takes_precedence_over_an_artisan_file(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        $config = Config::fromArray(['laravel' => 'off']);

        self::assertFalse(LaravelDetector::enabled($this->root, $config));
    }
}
