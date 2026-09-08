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
    public function the_index_holds_every_declaring_file_keyed_by_declared_name(): void
    {
        // src/Policy.php has method bodies and is in the index all the same: the two halves
        // of the feature partition by kind of signal, not by file shape. config/app.php
        // declares no name at all, so nothing can ever reference it.
        self::assertSame(
            [
                'App\Decision' => ['src/Decision.php'],
                'App\Limits' => ['src/Limits.php'],
                'App\Policy' => ['src/Policy.php'],
            ],
            $this->staticEdges()->index(),
        );
    }

    // -- expand(): a file with no body at all ----------------------------------

    #[Test]
    public function a_declaration_only_file_reaches_every_test_whose_behavioural_dependency_names_it(): void
    {
        // What coverage produced with the flag on: Policy's body lines ran under both tests,
        // and the enum's load-time footprint was credited to nobody at all.
        $behavioural = [
            'tests/FirstTest.php' => ['src/Policy.php'],
            'tests/SecondTest.php' => ['src/Policy.php'],
        ];

        $graph = new Graph($this->root);
        $graph->unionEdges($behavioural);

        $added = $this->staticEdges()->expand($graph, $behavioural);

        self::assertSame(4, $added);

        foreach (['tests/FirstTest.php', 'tests/SecondTest.php'] as $testFile) {
            self::assertSame(
                ['src/Decision.php', 'src/Limits.php', 'src/Policy.php'],
                $this->sorted($graph, $testFile),
                $testFile,
            );
        }
    }

    #[Test]
    public function a_return_array_file_declares_no_name_so_nothing_can_reach_it(): void
    {
        // config/app.php is the `lang/`-shaped residue: it declares no name for any reference
        // to resolve to. It is left to the conservative watch fallback.
        $graph = new Graph($this->root);
        $graph->unionEdges(['tests/FirstTest.php' => ['src/Policy.php']]);

        $this->staticEdges()->expand($graph, ['tests/FirstTest.php' => ['src/Policy.php']]);

        self::assertNotContains('config/app.php', $graph->dependenciesOf('tests/FirstTest.php'));
    }

    // -- expand(): a file that has bodies --------------------------------------

    #[Test]
    public function a_file_with_bodies_reaches_the_test_whose_own_source_names_it(): void
    {
        // The gap the shape-based index left open, and the reason the index is now keyed by
        // declaration. src/Policy.php has bodies, so a test that never calls into it gets no
        // behavioural edge; naming it is the only signal there is, and it is an
        // order-independent one. Before this, such a file was in neither half, and
        // Select\ResiduePatterns declined it too because some other test's coverage had
        // already given it a fileId — so it left the graph entirely for this test.
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

        self::assertSame(1, $this->staticEdges()->expand($graph, ['tests/OnlyNamesPolicyTest.php' => []]));
        self::assertSame(['src/Policy.php'], $this->sorted($graph, 'tests/OnlyNamesPolicyTest.php'));
    }

    #[Test]
    public function a_behavioural_dependency_may_not_reach_a_file_that_has_bodies(): void
    {
        // The asymmetry, and the whole reason this is affordable. src/Routes.php is the shape
        // that forced it: a file that names classes it merely *registers*. It is a
        // behavioural dependency of nearly every test on a real Laravel project (a closure
        // inside it runs on any HTTP test) and it names every controller in the application.
        // Letting it hop would give every test an edge to every controller: measured at
        // +105% edges over 62,743 on the project this was built for.
        //
        // Nothing is lost by refusing: src/Handler.php gets a behavioural edge from every
        // test that calls into it, and a static edge from every test that names it.
        $this->write('src/Routes.php', <<<'PHP'
            <?php
            namespace App;
            Router::get('/a', function () { return Handler::class; });
            PHP);
        $this->write('src/Handler.php', <<<'PHP'
            <?php
            namespace App;
            final class Handler {
                public function handle(): int { return 1; }
            }
            PHP);

        $graph = new Graph($this->root);
        $behavioural = ['tests/RouteTest.php' => ['src/Routes.php']];
        $graph->unionEdges($behavioural);

        $this->staticEdges()->expand($graph, $behavioural);

        self::assertNotContains('src/Handler.php', $graph->dependenciesOf('tests/RouteTest.php'));
    }

    #[Test]
    public function the_same_registration_file_still_hands_over_its_declaration_only_names(): void
    {
        // The other half of the asymmetry: a file with no body at all can never be
        // attributed by coverage in any process for any test, so the hop is the only
        // mechanism it will ever have and it keeps its full reach.
        $this->write('src/Routes.php', <<<'PHP'
            <?php
            namespace App;
            Router::get('/a', function () { return Limits::MAX; });
            PHP);

        $graph = new Graph($this->root);
        $behavioural = ['tests/RouteTest.php' => ['src/Routes.php']];
        $graph->unionEdges($behavioural);

        $this->staticEdges()->expand($graph, $behavioural);

        self::assertContains('src/Limits.php', $graph->dependenciesOf('tests/RouteTest.php'));
    }

    // -- expand(): the hardest position ----------------------------------------

    #[Test]
    public function a_test_with_no_behavioural_edge_at_all_is_still_expanded_from_its_own_source(): void
    {
        // The position `Cache\GraphUpdater` used to skip: a test whose coverage reported no
        // source file at all has no key in `edges.json`, so keying the loop on that map never
        // reached it. Reproducible with a `<source><exclude>` over the test directory, or
        // with any `--coverage-*` report at all.
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

        $added = $this->staticEdges()->expand($graph, ['tests/LimitsTest.php' => []]);

        self::assertSame(1, $added);
        self::assertSame(['src/Limits.php'], $this->sorted($graph, 'tests/LimitsTest.php'));
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

        self::assertSame(1, $this->staticEdges()->expand($graph, ['tests/StubTest.php' => []]));
        self::assertSame(['src/Limits.php'], $this->sorted($graph, 'tests/StubTest.php'));
    }

    // -- expand(): one hop, and it stays one hop across passes -----------------

    #[Test]
    public function it_does_not_take_a_second_hop(): void
    {
        // Marker is named only from Limits, which is not a behavioural dependency of anything.
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

        $this->staticEdges()->expand($graph, ['tests/FirstTest.php' => ['src/Policy.php']]);

        $deps = $graph->dependenciesOf('tests/FirstTest.php');
        self::assertContains('src/Limits.php', $deps, 'one hop from Policy');
        self::assertNotContains('src/Marker.php', $deps, 'a second hop is deliberately not taken');
    }

    #[Test]
    public function a_static_edge_from_an_earlier_pass_never_becomes_a_second_hop(): void
    {
        // Edges accumulate across passes (Graph::unionEdges()), so once src/Limits.php has
        // been linked it sits in the dependency list. Following the GRAPH on the next pass
        // would reach src/Marker.php, then a third pass would reach further still — a graph
        // that depends on how many partial re-records happened rather than on the source
        // tree. Hop sources therefore come from the run's own coverage, never from the graph.
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
        $behavioural = ['tests/FirstTest.php' => ['src/Policy.php']];
        $graph->unionEdges($behavioural);
        $edges = $this->staticEdges();

        $edges->expand($graph, $behavioural);
        self::assertContains('src/Limits.php', $graph->dependenciesOf('tests/FirstTest.php'));

        self::assertSame(0, $edges->expand($graph, $behavioural), 'a second pass must add nothing');
        self::assertNotContains('src/Marker.php', $graph->dependenciesOf('tests/FirstTest.php'));
    }

    #[Test]
    public function a_static_edge_to_a_file_with_bodies_never_becomes_a_hop_source_either(): void
    {
        // Same property for the shape the index gained. The test's own source names
        // src/Service.php, which HAS bodies — so on the next pass it sits in the graph's
        // dependency list and, being body-bearing, would be a legitimate hop source if hop
        // sources came from the graph. It would then hand over src/Marker.php, which is
        // declaration-only and therefore reachable by any hop. Coverage never reported
        // src/Service.php for this test, so it is never a hop source for it.
        $this->write('tests/ServiceTest.php', <<<'PHP'
            <?php
            namespace App\Tests;
            use App\Service;
            final class ServiceTest extends \PHPUnit\Framework\TestCase {
                public function testNothing(): void { self::assertTrue(class_exists(Service::class)); }
            }
            PHP);
        $this->write('src/Service.php', <<<'PHP'
            <?php
            namespace App;
            final class Service {
                public function run(): string { return Marker::class; }
            }
            PHP);
        $this->write('src/Marker.php', <<<'PHP'
            <?php
            namespace App;
            interface Marker {}
            PHP);

        $graph = new Graph($this->root);
        $graph->markKnownTestFiles([$this->root . '/tests/ServiceTest.php']);
        $edges = $this->staticEdges();

        self::assertSame(1, $edges->expand($graph, ['tests/ServiceTest.php' => []]));
        self::assertSame(['src/Service.php'], $this->sorted($graph, 'tests/ServiceTest.php'));

        self::assertSame(0, $edges->expand($graph, ['tests/ServiceTest.php' => []]), 'a second pass must add nothing');
        self::assertNotContains('src/Marker.php', $graph->dependenciesOf('tests/ServiceTest.php'));
    }

    #[Test]
    public function expanding_twice_adds_nothing_the_second_time(): void
    {
        $graph = new Graph($this->root);
        $behavioural = ['tests/FirstTest.php' => ['src/Policy.php']];
        $graph->unionEdges($behavioural);
        $edges = $this->staticEdges();

        self::assertSame(2, $edges->expand($graph, $behavioural));
        self::assertSame(0, $edges->expand($graph, $behavioural));
    }

    #[Test]
    public function an_unparseable_dependency_contributes_no_references_and_no_crash(): void
    {
        $this->write('src/Broken.php', '<?php final class { function ( }');

        $graph = new Graph($this->root);
        $graph->unionEdges(['tests/FirstTest.php' => ['src/Broken.php']]);

        self::assertSame(0, $this->staticEdges()->expand($graph, ['tests/FirstTest.php' => ['src/Broken.php']]));
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

        $this->staticEdges()->expand($graph, ['tests/BootTest.php' => ['src/Container.php']]);

        self::assertContains('src/Decision.php', $graph->dependenciesOf('tests/BootTest.php'));
    }

    // -- index(): what the walk does and does not see --------------------------

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
        $this->write('src/vendor/autoload.php', "<?php\n");
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
