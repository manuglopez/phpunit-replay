<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select\Rules;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Rules\SiblingRule;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SiblingRuleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('sibling-rule');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_name_is_sibling(): void
    {
        self::assertSame('Sibling', (new SiblingRule())->name());
    }

    public function test_a_new_listener_affects_tests_with_an_edge_to_a_sibling_in_the_same_directory(): void
    {
        TempDir::write($this->root . '/app/Listeners/Foo.php', '<?php class Foo {}');

        $graph = new Graph($this->root);
        $graph->replaceEdges([
            'tests/EventTest.php' => ['app/Listeners/Bar.php'],
            'tests/OtherTest.php' => ['app/Other.php'],
        ]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['app/Listeners/Foo.php'], $selection);

        (new SiblingRule())->apply($context);

        self::assertSame(['tests/EventTest.php'], $selection->testFiles());
        self::assertSame([], $context->remaining);

        $reason = $selection->reasons()['tests/EventTest.php'][0];
        self::assertSame('Sibling', $reason->rule);
        self::assertSame('app/Listeners/Foo.php', $reason->trigger);
        self::assertSame('app/Listeners', $reason->detail);
    }

    /** @return list<array{0: string}> */
    public static function siblingDirectoryProvider(): array
    {
        return [
            ['app/Providers/CustomServiceProvider.php'],
            ['app/Events/SomethingHappened.php'],
            ['app/Observers/PostObserver.php'],
            ['app/Policies/PostPolicy.php'],
            ['app/Console/Commands/DoStuff.php'],
            ['database/factories/PostFactory.php'],
            ['database/seeders/PostSeeder.php'],
        ];
    }

    #[DataProvider('siblingDirectoryProvider')]
    public function test_recognises_every_sibling_directory(string $newFile): void
    {
        TempDir::write($this->root . '/' . $newFile, '<?php');

        $dir = dirname($newFile);

        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/SiblingDirTest.php' => [$dir . '/AlreadyKnown.php']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, [$newFile], $selection);

        (new SiblingRule())->apply($context);

        self::assertSame(['tests/SiblingDirTest.php'], $selection->testFiles());
        self::assertSame([], $context->remaining);
    }

    public function test_leaves_a_file_outside_the_sibling_directories_unconsumed(): void
    {
        TempDir::write($this->root . '/app/Http/Controllers/FooController.php', '<?php class FooController {}');

        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/FooTest.php' => ['app/Http/Controllers/BarController.php']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['app/Http/Controllers/FooController.php'], $selection);

        (new SiblingRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['app/Http/Controllers/FooController.php'], $context->remaining);
    }

    public function test_leaves_a_file_already_known_to_the_graph_unconsumed(): void
    {
        TempDir::write($this->root . '/app/Listeners/Foo.php', '<?php class Foo {}');

        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/EventTest.php' => ['app/Listeners/Foo.php']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['app/Listeners/Foo.php'], $selection);

        (new SiblingRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['app/Listeners/Foo.php'], $context->remaining);
    }

    public function test_leaves_a_deleted_sibling_candidate_unconsumed(): void
    {
        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/EventTest.php' => ['app/Listeners/Bar.php']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['app/Listeners/Deleted.php'], $selection);

        (new SiblingRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['app/Listeners/Deleted.php'], $context->remaining);
    }

    #[Group('static-declaration-edges')]
    public function test_leaves_a_sibling_candidate_with_no_sibling_to_stand_on_unconsumed(): void
    {
        // The presumption is "a new class in a directory full of already-tested siblings is
        // exercised the same way they are". With no tested sibling there is no presumption to
        // make, and consuming the path anyway hid it from WatchRule — the only rule that
        // would have covered it, since the Laravel watch default for `app/` is
        // `app/** !*.php` and excludes exactly these files. With static_declaration_edges on
        // it also swallowed the conservative residue pattern (Select\ResiduePatterns), so a
        // brand-new provider affected nothing at all. BladeRule has always guarded its
        // consume this way.
        TempDir::write($this->root . '/app/Providers/NewProvider.php', '<?php class NewProvider {}');

        $graph = new Graph($this->root);
        $graph->replaceEdges(['tests/FooTest.php' => ['app/Other.php']]);

        $selection = new Selection();
        $context = $this->makeContext($graph, ['app/Providers/NewProvider.php'], $selection);

        (new SiblingRule())->apply($context);

        self::assertSame([], $selection->testFiles());
        self::assertSame(['app/Providers/NewProvider.php'], $context->remaining);
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
