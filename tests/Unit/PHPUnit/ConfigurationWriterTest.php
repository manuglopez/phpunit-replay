<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit;

use Manuglopez\Replay\PHPUnit\ConfigurationWriter;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use PHPUnit\TextUI\CliArguments\Builder as CliArgumentsBuilder;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Merger;
use PHPUnit\TextUI\XmlConfiguration\Loader as XmlConfigurationLoader;

final class ConfigurationWriterTest extends TestCase
{
    private string $root;

    private string $xmlFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('configuration-writer');
        $this->xmlFile = $this->root . '/phpunit.xml';

        TempDir::write($this->root . '/tests/CartTest.php', '<?php');
        TempDir::write($this->root . '/tests/GreeterTest.php', '<?php');

        $fixture = dirname(__DIR__, 2) . '/Fixtures/Projects/plain/phpunit.xml';
        $content = file_get_contents($fixture);
        self::assertIsString($content);

        TempDir::write($this->xmlFile, $content);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        parent::tearDown();
    }

    private function load(string $xmlFile): Configuration
    {
        $xml = (new XmlConfigurationLoader())->load($xmlFile);
        $cli = (new CliArgumentsBuilder())->fromParameters(['--configuration', $xmlFile]);

        return (new Merger())->merge($cli, $xml);
    }

    public function test_temp_path_is_next_to_the_source_file(): void
    {
        self::assertSame($this->root . '/.phpunit-replay.xml', ConfigurationWriter::tempPath($this->xmlFile));
    }

    public function test_write_replaces_the_test_selection_with_exactly_the_given_files(): void
    {
        $path = (new ConfigurationWriter())->write(
            $this->xmlFile,
            ['tests/CartTest.php', 'tests/GreeterTest.php'],
            $this->root,
        );

        self::assertSame(ConfigurationWriter::tempPath($this->xmlFile), $path);

        $configuration = $this->load($path);
        $suites = iterator_to_array($configuration->testSuite());

        self::assertCount(1, $suites);
        self::assertSame('phpunit-replay', $suites[0]->name());

        $files = [];

        foreach ($suites[0]->files() as $file) {
            $files[] = $file->path();
        }

        self::assertSame(
            [$this->root . '/tests/CartTest.php', $this->root . '/tests/GreeterTest.php'],
            $files,
        );
    }

    public function test_write_keeps_bootstrap_fail_on_risky_and_source_untouched(): void
    {
        $path = (new ConfigurationWriter())->write($this->xmlFile, ['tests/CartTest.php'], $this->root);

        $configuration = $this->load($path);

        self::assertTrue($configuration->hasBootstrap());
        self::assertSame($this->root . '/vendor/autoload.php', $configuration->bootstrap());
        self::assertTrue($configuration->failOnRisky());

        $includeDirectories = [];

        foreach ($configuration->source()->includeDirectories() as $directory) {
            $includeDirectories[] = $directory->path();
        }

        self::assertSame([$this->root . '/src'], $includeDirectories);
    }

    public function test_write_injects_the_extension_bootstrap(): void
    {
        $path = (new ConfigurationWriter())->write($this->xmlFile, ['tests/CartTest.php'], $this->root);

        self::assertStringContainsString(
            '<bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension"/>',
            (string) file_get_contents($path),
        );
    }

    public function test_write_is_idempotent_when_the_extension_is_already_present(): void
    {
        $writer = new ConfigurationWriter();

        $first = $writer->write($this->xmlFile, ['tests/CartTest.php'], $this->root);
        $second = $writer->write($first, ['tests/GreeterTest.php'], $this->root);

        $occurrences = substr_count(
            (string) file_get_contents($second),
            '<bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension"/>',
        );

        self::assertSame(1, $occurrences);
    }

    public function test_with_extension_only_keeps_the_original_test_directory(): void
    {
        $path = (new ConfigurationWriter())->withExtensionOnly($this->xmlFile);

        self::assertStringContainsString('<directory>tests</directory>', (string) file_get_contents($path));
        self::assertStringContainsString(
            '<bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension"/>',
            (string) file_get_contents($path),
        );
    }

    public function test_with_extension_only_is_idempotent(): void
    {
        $writer = new ConfigurationWriter();

        $first = $writer->withExtensionOnly($this->xmlFile);
        $second = $writer->withExtensionOnly($first);

        $occurrences = substr_count(
            (string) file_get_contents($second),
            '<bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension"/>',
        );

        self::assertSame(1, $occurrences);
    }
}
