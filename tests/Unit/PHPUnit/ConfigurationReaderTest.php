<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit;

use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\TextUI\Configuration\Configuration;

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

    public function test_repeat_or_retry_requested_is_false_by_default(): void
    {
        self::assertFalse($this->build()->repeatOrRetryRequested());
    }

    /**
     * `--repeat`/`--retry` do not exist as CLI options before PHPUnit 13.3: passing either
     * to an older installed `CliArgumentsBuilder` fails before this package's own code ever
     * runs, so these two tests are meaningless (not merely inapplicable) on 11.5/12/13.0-13.2
     * and are skipped there — the same boundary `ConfigurationReader::repeatOrRetryRequested()`
     * itself documents.
     */
    public function test_repeat_or_retry_requested_is_true_with_repeat(): void
    {
        if (! method_exists(Configuration::class, 'repeat')) {
            self::markTestSkipped('requires PHPUnit >= 13.3 (Configuration::repeat() not available)');
        }

        self::assertTrue($this->build(['--repeat=3'])->repeatOrRetryRequested());
    }

    public function test_repeat_or_retry_requested_is_true_with_retry(): void
    {
        if (! method_exists(Configuration::class, 'retry')) {
            self::markTestSkipped('requires PHPUnit >= 13.3 (Configuration::retry() not available)');
        }

        self::assertTrue($this->build(['--retry=2'])->repeatOrRetryRequested());
    }

    /**
     * `--repeat=1`/`--retry=1` are PHPUnit's own defaults: no different behaviour, no
     * degrade. Asserted one option at a time — PHPUnit itself rejects `--repeat` and
     * `--retry` together, regardless of value.
     */
    public function test_repeat_or_retry_requested_is_false_with_a_repeat_value_of_one(): void
    {
        if (! method_exists(Configuration::class, 'repeat')) {
            self::markTestSkipped('requires PHPUnit >= 13.3 (Configuration::repeat() not available)');
        }

        self::assertFalse($this->build(['--repeat=1'])->repeatOrRetryRequested());
    }

    public function test_repeat_or_retry_requested_is_false_with_a_retry_value_of_one(): void
    {
        if (! method_exists(Configuration::class, 'retry')) {
            self::markTestSkipped('requires PHPUnit >= 13.3 (Configuration::retry() not available)');
        }

        self::assertFalse($this->build(['--retry=1'])->repeatOrRetryRequested());
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
