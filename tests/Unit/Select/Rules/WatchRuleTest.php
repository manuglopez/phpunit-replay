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

    public function test_a_configured_pattern_applies_to_a_file_an_earlier_rule_claimed_and_a_residue_pattern_does_not(): void
    {
        $graph = new Graph($this->root);
        $graph->markKnownTestFiles(['tests/FooTest.php', 'tests/BarTest.php']);

        $watch = new WatchPatterns();
        $watch->add(['config/**' => ['tests/FooTest.php']]);
        $watch->addFallback(['src/Claimed.php' => ['tests']]);

        $selection = new Selection();
        $context = new Context(
            $graph,
            $this->root,
            new TestPaths([], [], ['Test.php']),
            $watch,
            ['config/app.php', 'src/Claimed.php'],
            $selection,
        );
        // PhpEdgeRule (or any other) consumed both before WatchRule ran.
        $context->consume('config/app.php');
        $context->consume('src/Claimed.php');

        (new WatchRule())->apply($context);

        self::assertSame(['tests/FooTest.php'], $selection->testFiles(), 'additive for the configured pattern, fallback-only for the residue');
    }

    public function test_a_configured_key_spelled_like_a_fallback_or_naming_an_unattributable_file_only_adds(): void
    {
        $graph = new Graph($this->root);
        $graph->markKnownTestFiles(['tests/FooTest.php', 'tests/BarTest.php', 'tests/BazTest.php']);

        $watch = new WatchPatterns();
        // The project's own key, the same string as the Laravel fallback's.
        $watch->add(['resources/views/**' => ['tests/FooTest.php'], 'app/**' => ['tests/BarTest.php']]);
        $watch->addFallback(['resources/views/**' => ['tests']]);
        $watch->addUnattributable(['app/Providers/AppServiceProvider.php' => ['tests']]);

        foreach (['resources/views/errors/404.blade.php', 'app/Providers/AppServiceProvider.php'] as $changed) {
            $selection = new Selection();
            (new WatchRule())->apply(new Context($graph, $this->root, new TestPaths([], [], ['Test.php']), $watch, [$changed], $selection));

            $selected = $selection->testFiles();
            sort($selected);
            self::assertSame(['tests/BarTest.php', 'tests/BazTest.php', 'tests/FooTest.php'], $selected, $changed . ': every test, never the configured target alone');
        }
    }

    public function test_pattern_maps_are_unioned_per_key(): void
    {
        self::assertSame(
            ['a/**' => ['tests/A', 'tests'], 'b' => ['tests/B']],
            WatchPatterns::union(['a/**' => ['tests/A']], ['a/**' => ['tests', 'tests/A'], 'b' => ['tests/B']], []),
        );
    }
}
