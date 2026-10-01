<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel\Rules;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Laravel\Rules\BladeRule;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class BladeRuleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('blade-rule');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_name_is_blade(): void
    {
        self::assertSame('Blade', (new BladeRule())->name());
    }

    public function test_an_unknown_partial_affects_tests_with_an_edge_to_its_known_ancestor(): void
    {
        $this->write('resources/views/partials/x.blade.php', '<div>x</div>');
        $this->write('resources/views/layout.blade.php', "@include('partials.x')");

        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/HomeTest.php' => ['resources/views/layout.blade.php']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['resources/views/partials/x.blade.php'], $selection);

        (new BladeRule())->apply($context);

        self::assertSame(['tests/HomeTest.php'], $selection->testFiles());
        self::assertSame([], $context->remaining);

        $reason = $selection->reasons()['tests/HomeTest.php'][0];
        self::assertSame('Blade', $reason->rule);
        self::assertSame('resources/views/partials/x.blade.php', $reason->trigger);
        self::assertSame('resources/views/layout.blade.php', $reason->detail);
    }

    public function test_a_partial_with_no_ancestor_is_left_unconsumed_for_watch_rule(): void
    {
        $this->write('resources/views/partials/orphan.blade.php', '<div>orphan</div>');
        $this->write('resources/views/unrelated.blade.php', '<p>nothing here</p>');

        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/HomeTest.php' => ['resources/views/unrelated.blade.php']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['resources/views/partials/orphan.blade.php'], $selection);

        (new BladeRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['resources/views/partials/orphan.blade.php'], $context->remaining);
    }

    public function test_an_ancestor_unknown_to_the_graph_does_not_count_as_a_match(): void
    {
        $this->write('resources/views/partials/x.blade.php', '<div>x</div>');
        $this->write('resources/views/layout.blade.php', "@include('partials.x')");

        // layout.blade.php exists and references partials/x, but no test has an edge to it.
        $graph = new Graph($this->root);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['resources/views/partials/x.blade.php'], $selection);

        (new BladeRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['resources/views/partials/x.blade.php'], $context->remaining);
    }

    public function test_leaves_a_blade_file_already_known_to_the_graph_unconsumed(): void
    {
        $this->write('resources/views/layout.blade.php', '<div>layout</div>');

        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/HomeTest.php' => ['resources/views/layout.blade.php']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['resources/views/layout.blade.php'], $selection);

        (new BladeRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['resources/views/layout.blade.php'], $context->remaining);
    }

    public function test_a_known_partial_also_selects_the_tests_rendering_a_page_that_includes_it(): void
    {
        // Additive: one test rendered the partial (it has an edge to it); another rendered the
        // page without meeting the condition the partial sits under. A change to the partial
        // can reach both, whoever else claimed it.
        $this->write('resources/views/partials/banner.blade.php', '<div>banner</div>');
        $this->write('resources/views/page.blade.php', "@if(\$admin) @include('partials.banner') @endif");

        $graph = new Graph($this->root);
        $graph->unionEdges([
            'tests/AdminTest.php' => ['resources/views/page.blade.php', 'resources/views/partials/banner.blade.php'],
            'tests/GuestTest.php' => ['resources/views/page.blade.php'],
        ]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['resources/views/partials/banner.blade.php'], $selection);
        $context->consume('resources/views/partials/banner.blade.php');

        (new BladeRule())->apply($context);

        self::assertSame(['tests/AdminTest.php', 'tests/GuestTest.php'], $selection->testFiles());
    }

    public function test_ignores_a_non_blade_file(): void
    {
        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/HomeTest.php' => ['resources/views/layout.blade.php']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['app/Models/Post.php'], $selection);

        (new BladeRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['app/Models/Post.php'], $context->remaining);
    }

    private function write(string $relative, string $content): void
    {
        TempDir::write($this->root . '/' . $relative, $content);
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
