<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class WatchPatternsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('watch-patterns');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    // -- add() / patterns() ---------------------------------------------------

    public function test_add_accepts_a_single_string_directory(): void
    {
        $watch = new WatchPatterns();
        $watch->add(['config/billing/**' => 'tests/Feature/Billing']);

        self::assertSame(['tests/Feature/Billing'], $watch->patterns()['config/billing/**']);
    }

    public function test_add_accepts_a_list_of_directories(): void
    {
        $watch = new WatchPatterns();
        $watch->add(['config/**' => ['tests/Unit', 'tests/Feature']]);

        self::assertSame(['tests/Unit', 'tests/Feature'], $watch->patterns()['config/**']);
    }

    public function test_add_merges_with_an_existing_pattern_without_duplicating(): void
    {
        $watch = new WatchPatterns();
        $watch->add(['config/**' => ['tests/Unit']]);
        $watch->add(['config/**' => ['tests/Unit', 'tests/Feature']]);

        self::assertSame(['tests/Unit', 'tests/Feature'], $watch->patterns()['config/**']);
    }

    // -- useDefaults() ---------------------------------------------------------

    public function test_use_defaults_always_includes_the_generic_php_defaults(): void
    {
        $watch = new WatchPatterns();
        $watch->useDefaults($this->root, ['tests']);

        self::assertSame(['tests'], $watch->patterns()['.env*']);
    }

    public function test_use_defaults_skips_laravel_without_an_artisan_file(): void
    {
        $watch = new WatchPatterns();
        $watch->useDefaults($this->root, ['tests']);

        self::assertArrayNotHasKey('routes/**', $watch->patterns());
    }

    public function test_use_defaults_includes_laravel_when_artisan_exists(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        $watch = new WatchPatterns();
        $watch->useDefaults($this->root, ['tests']);

        self::assertSame(['tests'], $watch->patterns()['routes/**']);
    }

    // -- matches() ---------------------------------------------------------

    public function test_matches_returns_every_pattern_that_matches_with_its_dirs(): void
    {
        $watch = new WatchPatterns();
        $watch->add(['.env*' => ['tests'], 'phpunit.xml*' => ['tests']]);

        self::assertSame(['.env*' => ['tests']], $watch->matches('.env'));
        self::assertSame([], $watch->matches('README.md'));
    }

    public function test_matches_supports_double_star_globbing(): void
    {
        $watch = new WatchPatterns();
        $watch->add(['config/**' => ['tests']]);

        self::assertSame(['config/**' => ['tests']], $watch->matches('config/billing/plans.php'));
        self::assertSame([], $watch->matches('app/config/billing/plans.php'));
    }

    public function test_matches_honours_exclude_tokens(): void
    {
        $watch = new WatchPatterns();
        $watch->add(['app/** !*.php' => ['tests']]);

        self::assertSame([], $watch->matches('app/Models/User.php'));
        self::assertSame(['app/** !*.php' => ['tests']], $watch->matches('app/views/welcome.blade.php.compiled'));
    }

    public function test_matches_ignores_vcs_directories(): void
    {
        $watch = new WatchPatterns();
        $watch->add(['**' => ['tests']]);

        self::assertSame([], $watch->matches('.git/HEAD'));
    }

    public function test_matches_ignores_dotfiles_unless_the_pattern_targets_them(): void
    {
        $watch = new WatchPatterns();
        $watch->add(['**' => ['tests'], '.env*' => ['tests']]);

        self::assertArrayNotHasKey('**', $watch->matches('.env.local'));
        self::assertArrayHasKey('.env*', $watch->matches('.env.local'));
    }

    // -- matchedDirectories() ---------------------------------------------------

    public function test_matched_directories_aggregates_across_changed_files(): void
    {
        $watch = new WatchPatterns();
        $watch->add(['.env*' => ['tests'], 'config/**' => ['tests/Feature/Billing']]);

        $dirs = $watch->matchedDirectories($this->root, ['.env', 'config/billing/plans.php', 'README.md']);

        sort($dirs);
        self::assertSame(['tests', 'tests/Feature/Billing'], $dirs);
    }

    public function test_matched_directories_is_empty_without_any_pattern(): void
    {
        $watch = new WatchPatterns();

        self::assertSame([], $watch->matchedDirectories($this->root, ['.env']));
    }

    // -- testsUnderDirectories() ---------------------------------------------------

    public function test_tests_under_directories_matches_exact_file_and_directory_prefix(): void
    {
        $watch = new WatchPatterns();

        $all = ['tests/Feature/BillingTest.php', 'tests/Unit/PricingTest.php', 'tests/Pest.php'];

        self::assertSame(
            ['tests/Feature/BillingTest.php', 'tests/Pest.php'],
            $watch->testsUnderDirectories(['tests/Feature', 'tests/Pest.php'], $all),
        );
    }

    public function test_tests_under_directories_is_empty_without_directories(): void
    {
        $watch = new WatchPatterns();

        self::assertSame([], $watch->testsUnderDirectories([], ['tests/FooTest.php']));
    }
}
