<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Analysis\FactsCache;
use Manuglopez\Replay\Analysis\StaticEdges;
use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphUpdater;
use Manuglopez\Replay\Cache\OnceProcessClassifier;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Hermeticity\Policy;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Laravel\OncePerProcessPaths;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Select\RunListBuilder;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The once-per-process residue fix (docs/reproducibility.md "Once-per-process residue"):
 * `Cache\GraphUpdater::apply()` refuses a COVERAGE-derived edge to a Laravel
 * migration/seeder/console-command ({@see \Manuglopez\Replay\Laravel\OncePerProcessPaths}),
 * gated on `static_declaration_edges` exactly like the declaration/first-loader filter it
 * sits beside — with the flag off, or with no classifier at all (not a detected Laravel
 * project), behaviour must be byte-identical to before this fix.
 *
 * Kept separate from `GraphUpdaterTest` (which knows nothing about `Select\*` or
 * `Analysis\StaticEdges`) because {@see self::test_the_property_to_pin_hardest()} chains
 * `GraphUpdater::apply()`'s output straight into `Select\RunListBuilder` to prove the
 * *selection* half, not just the edge-recording half — both halves the task this class
 * exists for asked to pin in one test.
 */
final class OnceProcessResidueTest extends TestCase
{
    private string $root;

    /** @var list<string> extra TempDir::make() directories to clean up in tearDown() */
    private array $extraDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('once-process-residue');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        foreach ($this->extraDirs as $dir) {
            TempDir::remove($dir);
        }

        parent::tearDown();
    }

    /**
     * The property to pin hardest (per the task this class was written for): with the
     * flag on, a fixture whose test triggers a seeder gets NO coverage-derived edge to
     * it, and a change to that file still selects the test — via residue
     * (`Select\ResiduePatterns`/`Rules\WatchRule`), never via `Rules\PhpEdgeRule`. Both
     * halves, in one test.
     *
     * `database/seeders/` is used rather than `database/migrations/` deliberately: a
     * migration path is ALSO handled by `Laravel\Rules\MigrationRule` (a completely
     * separate, table-based selection mechanism unaffected by this fix), which would
     * select the test regardless of whether the residue mechanism engaged at all —
     * making a migration-based test ambiguous about which mechanism actually fired.
     * Nothing else in the rule chain, and no `Select\WatchDefaults\Laravel` pattern,
     * claims `database/seeders/`, so a selection here can only be residue.
     */
    public function test_the_property_to_pin_hardest_no_edge_but_still_selected_via_residue(): void
    {
        $this->write('tests/Feature/UserModelTest.php');
        $this->write('database/seeders/TeamSeeder.php');

        $graph = new Graph($this->root);

        // The seeder's body genuinely ran under this one test's coverage — exactly the
        // shape the false green measured in docs/reproducibility.md: a real, coverage-
        // reported dependency, not a fabrication.
        $partial = new RunPartial(
            edges: ['tests/Feature/UserModelTest.php' => ['database/seeders/TeamSeeder.php']],
            results: ['Tests\Feature\UserModelTest::test_it_persists_a_user' => $this->makeResult('tests/Feature/UserModelTest.php')],
            tables: [],
            meta: [],
        );

        $updater = new GraphUpdater(
            $graph,
            $this->root,
            new ContentKey($this->root),
            null,
            $this->staticEdges(),
            null,
            new OncePerProcessPaths(),
        );
        $updater->apply($partial, 'main', recordsEdges: true, complete: true);

        // Half one: no coverage-derived edge at all.
        self::assertNull($graph->fileId('database/seeders/TeamSeeder.php'), 'the seeder must have no fileId — no edge, not even a stale one');
        self::assertSame([], $graph->dependenciesOf('tests/Feature/UserModelTest.php'));
        self::assertTrue($graph->knowsTest('tests/Feature/UserModelTest.php'), 'the test file itself is still known');

        // Half two: a change to the seeder still selects the test — via Watch (residue),
        // never PhpEdge (there is no fileId for PhpEdgeRule to find).
        $builder = $this->runListBuilder($graph);
        $list = $builder->build(['database/seeders/TeamSeeder.php'], 'main');

        self::assertSame(['tests/Feature/UserModelTest.php'], $list->files());

        $reasons = $list->reasonsFor('tests/Feature/UserModelTest.php');
        self::assertNotSame([], $reasons);
        self::assertSame('Watch', $reasons[0]->rule, 'selected via residue, not an edge');
        self::assertSame('database/seeders/TeamSeeder.php', $reasons[0]->trigger);
    }

    /**
     * The safety gate, pinned explicitly: `$onceProcessPaths` non-null with NO
     * `$staticEdges` must still record the edge exactly as before. `GraphUpdater::apply()`
     * re-checks `staticEdges !== null` itself before ever consulting `$onceProcessPaths` —
     * this proves that check actually holds, not merely that the two real callers
     * (`Console\Runner\RunPipeline`, `PHPUnit\ReplayState`) are disciplined about building
     * them together. With the flag off there is no `Select\ResiduePatterns` net
     * (`Select\RunListBuilder::build()` only installs it when the flag is on), so refusing
     * the edge here would be a strictly worse false green than the one this fix closes.
     */
    public function test_with_static_declaration_edges_off_the_edge_is_still_recorded(): void
    {
        $this->write('tests/Feature/UserModelTest.php');
        $this->write('database/seeders/TeamSeeder.php');

        $graph = new Graph($this->root);
        $partial = new RunPartial(
            edges: ['tests/Feature/UserModelTest.php' => ['database/seeders/TeamSeeder.php']],
            results: ['Tests\Feature\UserModelTest::test_it_persists_a_user' => $this->makeResult('tests/Feature/UserModelTest.php')],
            tables: [],
            meta: [],
        );

        // No StaticEdges passed at all — the flag-off shape — even though a classifier is.
        $updater = new GraphUpdater($graph, $this->root, new ContentKey($this->root), null, null, null, new OncePerProcessPaths());
        $summary = $updater->apply($partial, 'main', recordsEdges: true, complete: true);

        self::assertNotNull($graph->fileId('database/seeders/TeamSeeder.php'));
        self::assertSame(['database/seeders/TeamSeeder.php'], $graph->dependenciesOf('tests/Feature/UserModelTest.php'));
        self::assertSame(1, $summary['edges']);
    }

    /** The other half of the same gate: a classifier that is simply absent (no detected Laravel project) changes nothing. */
    public function test_without_a_classifier_the_edge_is_recorded_normally_even_with_the_flag_on(): void
    {
        $this->write('tests/Feature/UserModelTest.php');
        $this->write('database/seeders/TeamSeeder.php');

        $graph = new Graph($this->root);
        $partial = new RunPartial(
            edges: ['tests/Feature/UserModelTest.php' => ['database/seeders/TeamSeeder.php']],
            results: ['Tests\Feature\UserModelTest::test_it_persists_a_user' => $this->makeResult('tests/Feature/UserModelTest.php')],
            tables: [],
            meta: [],
        );

        $updater = new GraphUpdater($graph, $this->root, new ContentKey($this->root), null, $this->staticEdges());
        $summary = $updater->apply($partial, 'main', recordsEdges: true, complete: true);

        self::assertNotNull($graph->fileId('database/seeders/TeamSeeder.php'));
        self::assertSame(['database/seeders/TeamSeeder.php'], $graph->dependenciesOf('tests/Feature/UserModelTest.php'));
        self::assertSame(1, $summary['edges']);
    }

    /**
     * The good half survives: a test whose OWN source names a once-process file still
     * gets an edge to it, through `Analysis\StaticEdges`' "the test's own file" hop — a
     * name reference is order-independent evidence, unlike a coverage-derived call, and
     * `GraphUpdater::apply()` only ever refuses the COVERAGE half.
     *
     * This test's coverage reports NOTHING for the seeder at all (`edges: []` for it, as
     * if some OTHER test's process happened to be the one that actually entered its
     * body) — the only way the edge below can exist is the static hop, which proves
     * wiring the once-process filter in did not also blunt it.
     */
    public function test_a_test_that_only_names_a_once_process_source_still_gets_the_edge(): void
    {
        $this->write('database/seeders/TeamSeeder.php', <<<'PHP'
            <?php

            namespace Database\Seeders;

            final class TeamSeeder
            {
                public function run(): void
                {
                }
            }
            PHP);

        $this->write('tests/NamesOnlyTest.php', <<<'PHP'
            <?php

            namespace Tests;

            use Database\Seeders\TeamSeeder;

            final class NamesOnlyTest
            {
                public function test_the_seeder_class_exists(): void
                {
                    assert(TeamSeeder::class !== '');
                }
            }
            PHP);

        $graph = new Graph($this->root);
        $partial = new RunPartial(
            edges: ['tests/NamesOnlyTest.php' => []],
            results: ['Tests\NamesOnlyTest::test_the_seeder_class_exists' => $this->makeResult('tests/NamesOnlyTest.php')],
            tables: [],
            meta: [],
        );

        $updater = new GraphUpdater(
            $graph,
            $this->root,
            new ContentKey($this->root),
            null,
            $this->staticEdges(),
            null,
            new OncePerProcessPaths(),
        );
        $updater->apply($partial, 'main', recordsEdges: true, complete: true);

        self::assertContains('database/seeders/TeamSeeder.php', $graph->dependenciesOf('tests/NamesOnlyTest.php'));
        self::assertNotNull($graph->fileId('database/seeders/TeamSeeder.php'));
    }

    /**
     * The acceptance property of the `Cache\OnceProcessClassifier` seam itself, not merely
     * a rename: `GraphUpdater`'s constructor and `withoutOnceProcessSources()` must accept
     * ANY implementation of the interface, not only `Laravel\OncePerProcessPaths` — proving
     * `src/Cache/` depends on the abstraction rather than importing `src/Laravel/` (a
     * generic core class must not depend on a framework adapter). The double below
     * implements {@see \Manuglopez\Replay\Cache\OnceProcessClassifier} directly and never
     * references the Laravel class at all; it is refused an edge exactly the way the real
     * Laravel implementation is in
     * {@see self::test_the_property_to_pin_hardest_no_edge_but_still_selected_via_residue()},
     * which is what proves the seam is real rather than a rename with the same one caller
     * still hardcoded underneath.
     */
    public function test_a_hand_written_non_laravel_classifier_is_honoured_identically(): void
    {
        $this->write('tests/Feature/UserModelTest.php');
        $this->write('resources/generated/OnceWidget.php');

        $graph = new Graph($this->root);
        $partial = new RunPartial(
            edges: ['tests/Feature/UserModelTest.php' => ['resources/generated/OnceWidget.php']],
            results: ['Tests\Feature\UserModelTest::test_it_persists_a_user' => $this->makeResult('tests/Feature/UserModelTest.php')],
            tables: [],
            meta: [],
        );

        $classifier = new class () implements OnceProcessClassifier {
            public function matches(string $relative): bool
            {
                return $relative === 'resources/generated/OnceWidget.php';
            }
        };

        $updater = new GraphUpdater(
            $graph,
            $this->root,
            new ContentKey($this->root),
            null,
            $this->staticEdges(),
            null,
            $classifier,
        );
        $updater->apply($partial, 'main', recordsEdges: true, complete: true);

        self::assertNull($graph->fileId('resources/generated/OnceWidget.php'), 'a non-Laravel Cache\OnceProcessClassifier implementation must be honoured exactly like OncePerProcessPaths');
        self::assertSame([], $graph->dependenciesOf('tests/Feature/UserModelTest.php'));
        self::assertTrue($graph->knowsTest('tests/Feature/UserModelTest.php'));
    }

    private function write(string $relative, string $content = "<?php\n"): void
    {
        TempDir::write($this->root . '/' . $relative, $content);
    }

    /** @param array{status?:int, message?:string, time?:float, assertions?:int} $overrides */
    private function makeResult(string $file, array $overrides = []): array
    {
        return [
            'status' => $overrides['status'] ?? 0,
            'message' => $overrides['message'] ?? '',
            'time' => $overrides['time'] ?? 0.01,
            'assertions' => $overrides['assertions'] ?? 1,
            'file' => $file,
        ];
    }

    private function staticEdges(): StaticEdges
    {
        $stateDir = TempDir::make('once-process-residue-facts');
        $this->extraDirs[] = $stateDir;

        return new StaticEdges(
            $this->root,
            new SourceScope([$this->root . '/tests', $this->root . '/database', $this->root . '/app'], []),
            new FactsCache($stateDir, $this->root),
        );
    }

    private function runListBuilder(Graph $graph): RunListBuilder
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/Projects/plain/phpunit.xml';
        $xml = file_get_contents($fixture);
        self::assertIsString($xml);
        TempDir::write($this->root . '/phpunit.xml', $xml);

        $stateDir = TempDir::make('once-process-residue-policy');
        $this->extraDirs[] = $stateDir;

        $watch = new WatchPatterns();
        $watch->useDefaults($this->root, ['tests']);

        return new RunListBuilder(
            $graph,
            new TestPaths(['tests'], [], ['Test.php']),
            $watch,
            ConfigurationReader::fromXmlFile($this->root . '/phpunit.xml'),
            new Policy($graph, Config::defaults(), Quarantine::load($stateDir), $this->root),
            $this->root,
            [],
            true,
        );
    }
}
