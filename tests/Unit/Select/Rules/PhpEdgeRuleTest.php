<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select\Rules;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Rules\PhpEdgeRule;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class PhpEdgeRuleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('phpedge-rule');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_name_is_php_edge(): void
    {
        self::assertSame('PhpEdge', (new PhpEdgeRule())->name());
    }

    public function test_adds_a_reason_for_every_test_depending_on_a_changed_source_and_consumes_it(): void
    {
        $graph = new Graph($this->root);
        $graph->replaceEdges([
            'tests/ATest.php' => ['app/Shared.php'],
            'tests/BTest.php' => ['app/Shared.php'],
            'tests/CTest.php' => ['app/Other.php'],
        ]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['app/Shared.php'], $selection);

        (new PhpEdgeRule())->apply($context);

        self::assertEqualsCanonicalizing(['tests/ATest.php', 'tests/BTest.php'], $selection->testFiles());
        self::assertSame([], $context->remaining);

        $reasons = $selection->reasons()['tests/ATest.php'];
        self::assertCount(1, $reasons);
        self::assertSame('PhpEdge', $reasons[0]->rule);
        self::assertSame('app/Shared.php', $reasons[0]->trigger);
        self::assertSame('', $reasons[0]->detail);
    }

    public function test_consumes_a_known_file_even_when_nothing_currently_depends_on_it(): void
    {
        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/ATest.php' => ['app/Old.php']]);
        // A subsequent recording no longer lists app/Old.php as a dependency of any test,
        // but the graph never garbage-collects the "files" table itself: fileId() still resolves.
        $graph->replaceEdges(['tests/ATest.php' => []]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['app/Old.php'], $selection);

        (new PhpEdgeRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame([], $context->remaining);
    }

    public function test_leaves_a_file_unknown_to_the_graph_unconsumed(): void
    {
        $graph = new Graph($this->root);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['app/Unknown.php'], $selection);

        (new PhpEdgeRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['app/Unknown.php'], $context->remaining);
    }

    /** @param list<string> $remaining */
    private function makeContext(Graph $graph, array $remaining, Selection $selection): Context
    {
        return new Context(
            $graph,
            $this->root,
            new TestPaths([], [], ['Test.php']),
            new WatchPatterns(),
            $remaining,
            $selection,
        );
    }
}
