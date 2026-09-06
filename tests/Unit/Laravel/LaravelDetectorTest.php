<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\LaravelDetector;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Tests that need `\Illuminate\Container\Container` to exist run in a separate process
 * (loading tests/Unit/Laravel/Fixtures/IlluminateContainerStub.php there): the class it
 * defines must never leak into the rest of the suite, which otherwise assumes Laravel is
 * not installed.
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

    public function test_disabled_without_illuminate_installed_even_with_an_artisan_file(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        $config = Config::fromArray(['laravel' => 'auto']);

        self::assertFalse(LaravelDetector::enabled($this->root, $config));
    }

    public function test_disabled_without_an_artisan_file(): void
    {
        $config = Config::fromArray(['laravel' => 'auto']);

        self::assertFalse(LaravelDetector::enabled($this->root, $config));
    }

    #[RunInSeparateProcess]
    public function test_enabled_when_illuminate_is_present_and_artisan_exists(): void
    {
        require_once __DIR__ . '/Fixtures/IlluminateContainerStub.php';

        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        $config = Config::fromArray(['laravel' => 'auto']);

        self::assertTrue(LaravelDetector::enabled($this->root, $config));
    }

    #[RunInSeparateProcess]
    public function test_on_behaves_like_auto(): void
    {
        require_once __DIR__ . '/Fixtures/IlluminateContainerStub.php';

        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        $config = Config::fromArray(['laravel' => 'on']);

        self::assertTrue(LaravelDetector::enabled($this->root, $config));
    }

    #[RunInSeparateProcess]
    public function test_off_takes_precedence_even_when_illuminate_is_present(): void
    {
        require_once __DIR__ . '/Fixtures/IlluminateContainerStub.php';

        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        $config = Config::fromArray(['laravel' => 'off']);

        self::assertFalse(LaravelDetector::enabled($this->root, $config));
    }

    #[RunInSeparateProcess]
    public function test_disabled_when_illuminate_is_present_but_there_is_no_artisan_file(): void
    {
        require_once __DIR__ . '/Fixtures/IlluminateContainerStub.php';

        $config = Config::fromArray(['laravel' => 'auto']);

        self::assertFalse(LaravelDetector::enabled($this->root, $config));
    }
}
