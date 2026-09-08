<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Analysis;

use Manuglopez\Replay\Analysis\FactsCache;
use Manuglopez\Replay\Analysis\StaticEdges;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('static-declaration-edges')]
final class StaticEdgesTest extends TestCase
{
    private string $root;

    private string $stateDir;

    protected function setUp(): void
    {
        $this->root = TempDir::make('static-edges-root');
        $this->stateDir = TempDir::make('static-edges-state');

        $this->write('src/Decision.php', <<<'PHP'
            <?php
            namespace App;
            enum Decision: string {
                case Approved = 'approved';
                case Rejected = 'rejected';
            }
            PHP);

        $this->write('src/Limits.php', <<<'PHP'
            <?php
            namespace App;
            final class Limits { public const MAX = 3; }
            PHP);

        $this->write('src/Policy.php', <<<'PHP'
            <?php
            namespace App;
            final class Policy {
                public function decide(int $n): Decision {
                    return $n >= Limits::MAX ? Decision::Approved : Decision::Rejected;
                }
            }
            PHP);

        $this->write('config/app.php', <<<'PHP'
            <?php
            return ['policy' => \App\Policy::class];
            PHP);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
        TempDir::remove($this->stateDir);
    }

    // -- index() ---------------------------------------------------------------

    #[Test]
    public function the_index_holds_only_declaration_only_files_keyed_by_declared_name(): void
    {
        self::assertSame(
            ['App\Decision' => ['src/Decision.php'], 'App\Limits' => ['src/Limits.php']],
            $this->staticEdges()->index(),
        );
    }

    // -- expand() --------------------------------------------------------------

    #[Test]
    public function a_declaration_only_file_reaches_every_test_whose_behavioural_dependency_names_it(): void
    {
        $graph = new Graph($this->root);
        // What coverage produced: Policy's body lines ran under both tests, and the enum's
        // load-time footprint was credited to the first loader only.
        $graph->unionEdges([
            'tests/FirstTest.php' => ['src/Policy.php', 'src/Decision.php', 'src/Limits.php'],
            'tests/SecondTest.php' => ['src/Policy.php'],
        ]);

        $added = $this->staticEdges()->expand($graph, ['tests/FirstTest.php', 'tests/SecondTest.php']);

        self::assertSame(2, $added);
        self::assertSame(
            ['src/Decision.php', 'src/Limits.php', 'src/Policy.php'],
            $this->sorted($graph, 'tests/SecondTest.php'),
        );
    }

    #[Test]
    public function a_test_whose_own_source_names_a_declaration_only_file_gets_the_edge_with_no_other_hop(): void
    {
        $this->write('tests/LimitsTest.php', <<<'PHP'
            <?php
            namespace App\Tests;
            use App\Limits;
            final class LimitsTest extends \PHPUnit\Framework\TestCase {
                public function testMax(): void { self::assertSame(3, Limits::MAX); }
            }
            PHP);

        $graph = new Graph($this->root);
        $graph->markKnownTestFiles([$this->root . '/tests/LimitsTest.php']);

        $added = $this->staticEdges()->expand($graph, ['tests/LimitsTest.php']);

        self::assertSame(1, $added);
        self::assertSame(['src/Limits.php'], $this->sorted($graph, 'tests/LimitsTest.php'));
    }

    #[Test]
    public function a_file_with_method_bodies_never_becomes_a_static_edge(): void
    {
        // src/Policy.php has bodies, so coverage can attribute it order-independently and
        // it has no business being pulled in by name resolution.
        $this->write('tests/OnlyNamesPolicyTest.php', <<<'PHP'
            <?php
            namespace App\Tests;
            use App\Policy;
            final class OnlyNamesPolicyTest extends \PHPUnit\Framework\TestCase {
                public function testNothing(): void { self::assertTrue(class_exists(Policy::class)); }
            }
            PHP);

        $graph = new Graph($this->root);
        $graph->markKnownTestFiles([$this->root . '/tests/OnlyNamesPolicyTest.php']);

        self::assertSame(0, $this->staticEdges()->expand($graph, ['tests/OnlyNamesPolicyTest.php']));
        self::assertSame([], $this->sorted($graph, 'tests/OnlyNamesPolicyTest.php'));
    }

    #[Test]
    public function a_return_array_file_declares_no_name_so_nothing_can_reach_it(): void
    {
        // config/app.php is the `lang/`-shaped residue: declaration-only, but with no name
        // for any reference to resolve to. It is left to the conservative watch fallback.
        $graph = new Graph($this->root);
        $graph->unionEdges(['tests/FirstTest.php' => ['src/Policy.php']]);

        $this->staticEdges()->expand($graph, ['tests/FirstTest.php']);

        self::assertNotContains('config/app.php', $graph->dependenciesOf('tests/FirstTest.php'));
    }

    #[Test]
    public function it_does_not_take_a_second_hop_through_a_declaration_only_file(): void
    {
        // Marker is named only from Limits, which is itself declaration-only and therefore
        // never a behavioural dependency of anything.
        $this->write('src/Limits.php', <<<'PHP'
            <?php
            namespace App;
            final class Limits {
                public const MAX = 3;
                public const MARKER = \App\Marker::class;
            }
            PHP);
        $this->write('src/Marker.php', <<<'PHP'
            <?php
            namespace App;
            interface Marker {}
            PHP);

        $graph = new Graph($this->root);
        $graph->unionEdges(['tests/FirstTest.php' => ['src/Policy.php']]);

        $this->staticEdges()->expand($graph, ['tests/FirstTest.php']);

        $deps = $graph->dependenciesOf('tests/FirstTest.php');
        self::assertContains('src/Limits.php', $deps, 'one hop from Policy');
        self::assertNotContains('src/Marker.php', $deps, 'a second hop is deliberately not taken');
    }

    #[Test]
    public function a_static_edge_from_an_earlier_pass_never_becomes_a_second_hop(): void
    {
        // Edges accumulate across passes (Graph::unionEdges()), so once src/Limits.php has
        // been linked it sits in the dependency list. Following it on the next pass would
        // reach src/Marker.php, then a third pass would reach further still — a graph that
        // depends on how many partial re-records happened rather than on the source tree.
        $this->write('src/Limits.php', <<<'PHP'
            <?php
            namespace App;
            final class Limits {
                public const MAX = 3;
                public const MARKER = \App\Marker::class;
            }
            PHP);
        $this->write('src/Marker.php', <<<'PHP'
            <?php
            namespace App;
            interface Marker {}
            PHP);

        $graph = new Graph($this->root);
        $graph->unionEdges(['tests/FirstTest.php' => ['src/Policy.php']]);
        $edges = $this->staticEdges();

        $edges->expand($graph, ['tests/FirstTest.php']);
        self::assertContains('src/Limits.php', $graph->dependenciesOf('tests/FirstTest.php'));

        self::assertSame(0, $edges->expand($graph, ['tests/FirstTest.php']), 'a second pass must add nothing');
        self::assertNotContains('src/Marker.php', $graph->dependenciesOf('tests/FirstTest.php'));
    }

    #[Test]
    public function a_declaration_only_test_file_is_still_a_hop_source(): void
    {
        // A stub test whose only method has an empty body classifies as declaration-only,
        // and must not therefore stop being the hop source for its own test.
        $this->write('tests/StubTest.php', <<<'PHP'
            <?php
            namespace App\Tests;
            use App\Limits;
            class StubTest extends \PHPUnit\Framework\TestCase {
                public function test__construct() {}
            }
            PHP);

        $graph = new Graph($this->root);
        $graph->markKnownTestFiles([$this->root . '/tests/StubTest.php']);

        self::assertSame(1, $this->staticEdges()->expand($graph, ['tests/StubTest.php']));
        self::assertSame(['src/Limits.php'], $this->sorted($graph, 'tests/StubTest.php'));
    }

    #[Test]
    public function expanding_twice_adds_nothing_the_second_time(): void
    {
        $graph = new Graph($this->root);
        $graph->unionEdges(['tests/FirstTest.php' => ['src/Policy.php']]);
        $edges = $this->staticEdges();

        self::assertSame(2, $edges->expand($graph, ['tests/FirstTest.php']));
        self::assertSame(0, $edges->expand($graph, ['tests/FirstTest.php']));
    }

    #[Test]
    public function an_unparseable_dependency_contributes_no_references_and_no_crash(): void
    {
        $this->write('src/Broken.php', '<?php final class { function ( }');

        $graph = new Graph($this->root);
        $graph->unionEdges(['tests/FirstTest.php' => ['src/Broken.php']]);

        self::assertSame(0, $this->staticEdges()->expand($graph, ['tests/FirstTest.php']));
    }

    #[Test]
    public function a_published_package_directory_called_vendor_is_still_walked(): void
    {
        // Laravel publishes package translations into `lang/vendor/<package>/...`, and 27 of
        // the 37 `lang/**.php` files in the project this was measured against live there. A
        // blanket segment match on "vendor" skipped every one of them.
        $this->write('lang/vendor/nova/en/Published.php', <<<'PHP'
            <?php
            namespace Lang\Vendor;
            interface Published {}
            PHP);

        self::assertArrayHasKey('Lang\Vendor\Published', $this->staticEdges()->index());
    }

    #[Test]
    public function a_real_composer_vendor_directory_is_pruned(): void
    {
        $this->write('src/vendor/autoload.php', '<?php
');
        $this->write('src/vendor/acme/lib/Marker.php', <<<'PHP'
            <?php
            namespace Acme;
            interface Marker {}
            PHP);

        self::assertArrayNotHasKey('Acme\Marker', $this->staticEdges()->index());
    }

    #[Test]
    public function node_modules_is_pruned(): void
    {
        $this->write('src/node_modules/pkg/Marker.php', <<<'PHP'
            <?php
            namespace Node;
            interface Marker {}
            PHP);

        self::assertArrayNotHasKey('Node\Marker', $this->staticEdges()->index());
    }

    #[Test]
    public function a_class_shaped_string_reaches_a_declaration_only_file(): void
    {
        $this->write('src/Container.php', <<<'PHP'
            <?php
            namespace App;
            final class Container {
                public function boot(): bool {
                    return class_exists('App\Decision');
                }
            }
            PHP);

        $graph = new Graph($this->root);
        $graph->unionEdges(['tests/BootTest.php' => ['src/Container.php']]);

        $this->staticEdges()->expand($graph, ['tests/BootTest.php']);

        self::assertContains('src/Decision.php', $graph->dependenciesOf('tests/BootTest.php'));
    }

    private function staticEdges(): StaticEdges
    {
        return new StaticEdges(
            $this->root,
            new SourceScope([$this->root . '/src', $this->root . '/tests', $this->root . '/config', $this->root . '/lang'], []),
            new FactsCache($this->stateDir, $this->root),
        );
    }

    /** @return list<string> */
    private function sorted(Graph $graph, string $testFile): array
    {
        $deps = $graph->dependenciesOf($testFile);
        sort($deps);

        return $deps;
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, $content);
    }
}
