<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit;

use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationReaderTest extends TestCase
{
    private string $root;

    private string $xmlFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('configuration-reader');
        $this->xmlFile = $this->root . '/phpunit.xml';

        TempDir::write($this->root . '/src/.keep', '');
        TempDir::write($this->root . '/tests/CartTest.php', '<?php');

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

    /** @param list<string> $cliArguments */
    private function build(array $cliArguments = []): ConfigurationReader
    {
        return ConfigurationReader::fromXmlFile($this->xmlFile, $cliArguments);
    }

    public function test_has_partial_selection_is_false_by_default(): void
    {
        self::assertFalse($this->build()->hasPartialSelection());
    }

    public function test_has_partial_selection_is_true_with_a_filter(): void
    {
        self::assertTrue($this->build(['--filter', 'Foo'])->hasPartialSelection());
    }

    public function test_has_partial_selection_is_true_with_a_group(): void
    {
        self::assertTrue($this->build(['--group', 'x'])->hasPartialSelection());
    }

    public function test_has_partial_selection_is_true_with_a_testsuite(): void
    {
        self::assertTrue($this->build(['--testsuite', 'default'])->hasPartialSelection());
    }

    public function test_has_partial_selection_is_true_with_a_positional_path_argument(): void
    {
        self::assertTrue($this->build(['tests/CartTest.php'])->hasPartialSelection());
    }

    public function test_source_include_directories_are_absolute(): void
    {
        $directories = $this->build()->sourceIncludeDirectories();

        self::assertCount(1, $directories);
        self::assertSame($this->root . '/src', $directories[0]);
    }

    public function test_test_suffixes_are_read_from_the_configuration(): void
    {
        self::assertSame(['Test.php', '.phpt'], $this->build()->testSuffixes());
    }

    public function test_configuration_file_returns_the_absolute_path(): void
    {
        self::assertSame($this->xmlFile, $this->build()->configurationFile());
    }

    /** @return array<string, array{int, bool}> */
    public static function statuses(): array
    {
        return [
            'success' => [0, false],
            'skipped' => [1, false],
            'incomplete' => [2, false],
            'notice' => [3, false],
            'deprecation' => [4, false],
            // The fixture sets failOnRisky="true".
            'risky' => [5, true],
            'warning' => [6, false],
            'failure' => [7, true],
            'error' => [8, true],
            'unknown' => [99, true],
        ];
    }

    #[DataProvider('statuses')]
    public function test_should_rerun_matches_the_fixtures_fail_on_risky_configuration(int $status, bool $expected): void
    {
        self::assertSame($expected, $this->build()->shouldRerun($status));
    }
}
