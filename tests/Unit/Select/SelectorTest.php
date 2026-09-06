<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Select\Selector;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class SelectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('selector');

        TempDir::write($this->root . '/src/Foo.php', '<?php');
        TempDir::write($this->root . '/src/Bar.php', '<?php');
        TempDir::write($this->root . '/tests/FooTest.php', '<?php');
        TempDir::write($this->root . '/tests/BarTest.php', '<?php');
        TempDir::write($this->root . '/README.md', '# readme');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    private function testPaths(): TestPaths
    {
        return new TestPaths(['tests'], [], ['Test.php']);
    }

    private function watch(): WatchPatterns
    {
        $watch = new WatchPatterns();
        $watch->useDefaults($this->root, ['tests']);

        return $watch;
    }

    /** A test file may also appear as a "source" of its own edge (self-coverage). */
    private function graphWithSelfEdge(): Graph
    {
        $graph = new Graph($this->root);
        $graph->replaceEdges([
            'tests/FooTest.php' => ['src/Foo.php', 'tests/FooTest.php'],
            'tests/BarTest.php' => ['src/Bar.php'],
        ]);

        return $graph;
    }

    public function test_a_changed_source_affects_exactly_its_dependents_via_php_edge(): void
    {
        $selector = Selector::default($this->graphWithSelfEdge(), $this->testPaths(), $this->watch(), $this->root);

        $selection = $selector->affected(['src/Foo.php']);

        self::assertSame(['tests/FooTest.php'], $selection->testFiles());

        $reasons = $selection->reasons()['tests/FooTest.php'];
        self::assertCount(1, $reasons);
        self::assertSame('PhpEdge', $reasons[0]->rule);
        self::assertSame('src/Foo.php', $reasons[0]->trigger);
    }

    public function test_a_deleted_source_still_affects_its_dependents(): void
    {
        $graph = $this->graphWithSelfEdge();
        unlink($this->root . '/src/Bar.php');

        $selector = Selector::default($graph, $this->testPaths(), $this->watch(), $this->root);

        $selection = $selector->affected(['src/Bar.php']);

        self::assertSame(['tests/BarTest.php'], $selection->testFiles());
        self::assertSame('PhpEdge', $selection->reasons()['tests/BarTest.php'][0]->rule);
    }

    public function test_a_changed_test_file_that_depends_on_itself_is_selected_once_via_php_edge(): void
    {
        $selector = Selector::default($this->graphWithSelfEdge(), $this->testPaths(), $this->watch(), $this->root);

        $selection = $selector->affected(['tests/FooTest.php']);

        self::assertSame(['tests/FooTest.php'], $selection->testFiles());

        $reasons = $selection->reasons()['tests/FooTest.php'];
        self::assertCount(1, $reasons);
        self::assertSame('PhpEdge', $reasons[0]->rule);
    }

    public function test_a_new_unknown_test_file_on_disk_is_selected_via_test_file_rule(): void
    {
        TempDir::write($this->root . '/tests/NewTest.php', '<?php');

        $selector = Selector::default($this->graphWithSelfEdge(), $this->testPaths(), $this->watch(), $this->root);

        $selection = $selector->affected(['tests/NewTest.php']);

        self::assertSame(['tests/NewTest.php'], $selection->testFiles());

        $reasons = $selection->reasons()['tests/NewTest.php'];
        self::assertCount(1, $reasons);
        self::assertSame('TestFile', $reasons[0]->rule);
        self::assertSame('tests/NewTest.php', $reasons[0]->trigger);
    }

    public function test_an_env_change_affects_every_graph_test_file_under_tests_via_watch(): void
    {
        $selector = Selector::default($this->graphWithSelfEdge(), $this->testPaths(), $this->watch(), $this->root);

        $selection = $selector->affected(['.env']);

        self::assertEqualsCanonicalizing(['tests/FooTest.php', 'tests/BarTest.php'], $selection->testFiles());

        $reason = $selection->reasons()['tests/FooTest.php'][0];
        self::assertSame('Watch', $reason->rule);
        self::assertSame('.env', $reason->trigger);
        self::assertSame('.env* → tests', $reason->detail);
    }

    public function test_a_readme_change_affects_nothing(): void
    {
        $selector = Selector::default($this->graphWithSelfEdge(), $this->testPaths(), $this->watch(), $this->root);

        $selection = $selector->affected(['README.md']);

        self::assertSame([], $selection->testFiles());
    }

    public function test_a_test_file_removed_from_disk_is_excluded_even_when_edges_point_to_it(): void
    {
        $graph = $this->graphWithSelfEdge();
        unlink($this->root . '/tests/BarTest.php');

        $selector = Selector::default($graph, $this->testPaths(), $this->watch(), $this->root);

        // src/Bar.php still has an edge to tests/BarTest.php, which would normally select it.
        $selection = $selector->affected(['src/Bar.php']);

        self::assertSame([], $selection->testFiles());
        self::assertFalse($selection->has('tests/BarTest.php'));
    }

    public function test_source_php_changed_is_true_for_a_source_file(): void
    {
        $selector = Selector::default($this->graphWithSelfEdge(), $this->testPaths(), $this->watch(), $this->root);

        self::assertTrue($selector->affected(['src/Foo.php'])->sourcePhpChanged);
    }

    public function test_source_php_changed_is_false_for_a_test_file(): void
    {
        $selector = Selector::default($this->graphWithSelfEdge(), $this->testPaths(), $this->watch(), $this->root);

        self::assertFalse($selector->affected(['tests/FooTest.php'])->sourcePhpChanged);
    }

    public function test_source_php_changed_is_false_for_a_non_php_file(): void
    {
        $selector = Selector::default($this->graphWithSelfEdge(), $this->testPaths(), $this->watch(), $this->root);

        self::assertFalse($selector->affected(['README.md'])->sourcePhpChanged);
    }

    public function test_laravel_watch_defaults_are_inactive_without_an_artisan_file(): void
    {
        $selector = Selector::default($this->graphWithSelfEdge(), $this->testPaths(), $this->watch(), $this->root);

        $selection = $selector->affected(['routes/web.php']);

        self::assertSame([], $selection->testFiles());
    }

    public function test_laravel_watch_defaults_activate_once_an_artisan_file_exists(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        // The watch patterns are (re)primed after artisan appears, as production code would do.
        $selector = Selector::default($this->graphWithSelfEdge(), $this->testPaths(), $this->watch(), $this->root);

        $selection = $selector->affected(['routes/web.php']);

        self::assertEqualsCanonicalizing(['tests/FooTest.php', 'tests/BarTest.php'], $selection->testFiles());

        $reason = $selection->reasons()['tests/FooTest.php'][0];
        self::assertSame('Watch', $reason->rule);
        self::assertSame('routes/**', str_replace(' → tests', '', $reason->detail));
    }
}
