<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select\Rules;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Rules\TestFileRule;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class TestFileRuleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('testfile-rule');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_name_is_test_file(): void
    {
        self::assertSame('TestFile', (new TestFileRule())->name());
    }

    public function test_adds_and_consumes_an_existing_test_file(): void
    {
        TempDir::write($this->root . '/tests/FooTest.php', '<?php');

        $selection = new Selection();
        $context = $this->makeContext(['tests/FooTest.php'], $selection);

        (new TestFileRule())->apply($context);

        self::assertSame(['tests/FooTest.php'], $selection->testFiles());
        self::assertSame([], $context->remaining);

        $reasons = $selection->reasons()['tests/FooTest.php'];
        self::assertCount(1, $reasons);
        self::assertSame('TestFile', $reasons[0]->rule);
        self::assertSame('tests/FooTest.php', $reasons[0]->trigger);
    }

    public function test_skips_a_test_file_that_no_longer_exists_on_disk(): void
    {
        $selection = new Selection();
        $context = $this->makeContext(['tests/GoneTest.php'], $selection);

        (new TestFileRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['tests/GoneTest.php'], $context->remaining);
    }

    public function test_skips_a_file_that_is_not_a_test_file(): void
    {
        TempDir::write($this->root . '/src/Foo.php', '<?php');

        $selection = new Selection();
        $context = $this->makeContext(['src/Foo.php'], $selection);

        (new TestFileRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['src/Foo.php'], $context->remaining);
    }

    /** @param list<string> $remaining */
    private function makeContext(array $remaining, Selection $selection): Context
    {
        return new Context(
            new Graph($this->root),
            $this->root,
            new TestPaths(['tests'], [], ['Test.php']),
            new WatchPatterns(),
            $remaining,
            $selection,
        );
    }
}
