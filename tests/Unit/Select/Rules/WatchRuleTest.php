<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select\Rules;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Rules\WatchRule;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class WatchRuleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('watch-rule');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_name_is_watch(): void
    {
        self::assertSame('Watch', (new WatchRule())->name());
    }

    public function test_adds_a_reason_for_every_test_under_the_matched_directory_and_consumes_the_change(): void
    {
        $graph = new Graph($this->root);
        $graph->markKnownTestFiles(['tests/FooTest.php', 'tests/BarTest.php']);

        $watch = new WatchPatterns();
        $watch->add(['.env*' => ['tests']]);

        $selection = new Selection();
        $context = new Context(
            $graph,
            $this->root,
            new TestPaths([], [], ['Test.php']),
            $watch,
            ['.env'],
            $selection,
        );

        (new WatchRule())->apply($context);

        self::assertEqualsCanonicalizing(['tests/FooTest.php', 'tests/BarTest.php'], $selection->testFiles());
        self::assertSame([], $context->remaining);

        $reason = $selection->reasons()['tests/FooTest.php'][0];
        self::assertSame('Watch', $reason->rule);
        self::assertSame('.env', $reason->trigger);
        self::assertSame('.env* → tests', $reason->detail);
    }

    public function test_leaves_an_unmatched_file_unconsumed_and_selects_nothing(): void
    {
        $graph = new Graph($this->root);
        $graph->markKnownTestFiles(['tests/FooTest.php']);

        $watch = new WatchPatterns();
        $watch->add(['.env*' => ['tests']]);

        $selection = new Selection();
        $context = new Context(
            $graph,
            $this->root,
            new TestPaths([], [], ['Test.php']),
            $watch,
            ['README.md'],
            $selection,
        );

        (new WatchRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['README.md'], $context->remaining);
    }
}
