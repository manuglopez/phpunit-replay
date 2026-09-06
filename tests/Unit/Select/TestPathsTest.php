<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use PHPUnit\TextUI\CliArguments\Builder as CliBuilder;
use PHPUnit\TextUI\Configuration\Merger;
use PHPUnit\TextUI\XmlConfiguration\Loader as XmlLoader;

final class TestPathsTest extends TestCase
{
    // -- isTestFile() --------------------------------------------------------

    public function test_matches_a_standard_test_file_under_a_configured_directory(): void
    {
        $paths = new TestPaths(
            directories: ['tests/Unit', 'tests/Feature'],
            files: [],
            suffixes: ['Test.php'],
        );

        self::assertTrue($paths->isTestFile('tests/Unit/ExampleTest.php'));
        self::assertTrue($paths->isTestFile('tests/Feature/UserTest.php'));
    }

    public function test_does_not_match_a_non_test_file_that_shares_the_directory(): void
    {
        $paths = new TestPaths(
            directories: ['tests/Unit'],
            files: [],
            suffixes: ['Test.php'],
        );

        self::assertFalse($paths->isTestFile('tests/Unit/Helper.php'));
    }

    public function test_does_not_match_files_outside_the_configured_directories(): void
    {
        $paths = new TestPaths(
            directories: ['tests/Unit'],
            files: [],
            suffixes: ['Test.php'],
        );

        self::assertFalse($paths->isTestFile('app/Models/UserTest.php'));
    }

    public function test_matches_an_explicitly_listed_file_regardless_of_suffix(): void
    {
        $paths = new TestPaths(
            directories: [],
            files: ['tests/Pest.php'],
            suffixes: ['Test.php'],
        );

        self::assertTrue($paths->isTestFile('tests/Pest.php'));
    }

    public function test_honours_a_bare_php_suffix(): void
    {
        $paths = new TestPaths(
            directories: ['tests'],
            files: [],
            suffixes: ['.php'],
        );

        self::assertTrue($paths->isTestFile('tests/Unit/ExampleTest.php'));
        self::assertTrue($paths->isTestFile('tests/Unit/Helper.php'));
    }

    public function test_does_not_match_a_directory_prefix_that_is_not_followed_by_a_slash(): void
    {
        $paths = new TestPaths(
            directories: ['tests/Unit'],
            files: [],
            suffixes: ['Test.php'],
        );

        self::assertFalse($paths->isTestFile('tests/UnitSomethingElse/ExampleTest.php'));
    }

    public function test_directories_returns_the_configured_list(): void
    {
        $paths = new TestPaths(
            directories: ['tests/Unit', 'tests/Feature'],
            files: ['tests/Pest.php'],
            suffixes: ['Test.php'],
        );

        self::assertSame(['tests/Unit', 'tests/Feature'], $paths->directories());
    }

    // -- fromConfiguration() ---------------------------------------------------

    public function test_from_configuration_reads_directories_and_suffix_from_a_real_phpunit_xml(): void
    {
        $root = TempDir::make('testpaths');

        try {
            copy(dirname(__DIR__, 2) . '/Fixtures/Projects/plain/phpunit.xml', $root . '/phpunit.xml');


            $configuration = $this->mergedConfiguration($root . '/phpunit.xml');

            $testPaths = TestPaths::fromConfiguration($configuration, $root);

            self::assertSame(['tests'], $testPaths->directories());
            self::assertTrue($testPaths->isTestFile('tests/CartTest.php'));
            self::assertFalse($testPaths->isTestFile('src/Cart.php'));
        } finally {
            TempDir::remove($root);
        }
    }

    public function test_from_configuration_falls_back_to_tests_directory_when_testsuites_is_absent(): void
    {
        $root = TempDir::make('testpaths-fallback');

        try {
            mkdir($root . '/tests', 0o775, true);
            TempDir::write($root . '/phpunit.xml', <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <phpunit bootstrap="vendor/autoload.php"></phpunit>
                XML);

            $configuration = $this->mergedConfiguration($root . '/phpunit.xml');

            $testPaths = TestPaths::fromConfiguration($configuration, $root);

            self::assertSame(['tests'], $testPaths->directories());
            self::assertTrue($testPaths->isTestFile('tests/AnythingTest.php'));
        } finally {
            TempDir::remove($root);
        }
    }

    public function test_from_configuration_has_no_directories_when_neither_testsuites_nor_tests_dir_exist(): void
    {
        $root = TempDir::make('testpaths-none');

        try {
            TempDir::write($root . '/phpunit.xml', <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <phpunit bootstrap="vendor/autoload.php"></phpunit>
                XML);

            $configuration = $this->mergedConfiguration($root . '/phpunit.xml');

            $testPaths = TestPaths::fromConfiguration($configuration, $root);

            self::assertSame([], $testPaths->directories());
        } finally {
            TempDir::remove($root);
        }
    }

    private function mergedConfiguration(string $xmlPath): \PHPUnit\TextUI\Configuration\Configuration
    {
        $xml = (new XmlLoader())->load($xmlPath);
        $cli = (new CliBuilder())->fromParameters([]);

        return (new Merger())->merge($cli, $xml);
    }
}
