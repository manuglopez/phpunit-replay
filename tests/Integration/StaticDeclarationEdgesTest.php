<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §4.3.1 `static_declaration_edges`, end to end over
 * tests/Fixtures/Projects/declarations.
 *
 * The fixture is the `PublisherReviewDecision` shape in miniature: `src/ReviewDecision.php`
 * is an enum with cases and no method bodies, three of the four test files assert directly
 * against its cases, and PHPUnit runs them all in one process — so PHP executes the enum's
 * top level exactly once, during whichever test file it reaches first. `src/Limits.php` is
 * the same shape in the other common form (a constants-only class), and
 * `tests/DeltaLimitsTest.php` reads it with no other source file involved at all.
 */
#[Group('static-declaration-edges')]
final class StaticDeclarationEdgesTest extends TestCase
{
    /** @var array<string, string> */
    private const FLAG_ON = ['PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES' => '1'];

    private const ENUM = 'src/ReviewDecision.php';

    private const LIMITS = 'src/Limits.php';

    private const REGISTRY = 'src/Registry.php';

    private FixtureProject $fixture;

    /** @var list<FixtureProject> */
    private array $fixtures = [];

    /** @var list<string> */
    private array $temporaries = [];

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::declarations();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();

        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        foreach ($this->temporaries as $directory) {
            TempDir::remove($directory);
        }
    }

    // -- the bug, with the flag off (today's behaviour) -------------------------

    #[Test]
    public function with_the_flag_off_the_enum_is_credited_to_the_first_loader_only(): void
    {
        $this->record();

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        self::assertContains(self::ENUM, $graph->dependenciesOf('tests/AlphaApprovedTest.php'));
        self::assertNotContains(self::ENUM, $graph->dependenciesOf('tests/BetaRejectedTest.php'));
        self::assertNotContains(self::ENUM, $graph->dependenciesOf('tests/GammaPendingTest.php'));

        self::assertContains(self::LIMITS, $graph->dependenciesOf('tests/AlphaApprovedTest.php'));
        self::assertNotContains(self::LIMITS, $graph->dependenciesOf('tests/DeltaLimitsTest.php'));
    }

    #[Test]
    public function with_the_flag_off_the_structural_fingerprint_carries_no_new_key(): void
    {
        $this->record();

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        $structural = $graph->fingerprint()['structural'] ?? null;
        self::assertIsArray($structural);
        self::assertArrayNotHasKey('static_declaration_edges', $structural);
    }

    #[Test]
    public function with_the_flag_off_changing_the_enum_reruns_only_the_first_loader(): void
    {
        // The false green, reproduced: two test files assert directly against these cases
        // and neither is re-executed.
        $this->record();
        $this->addEnumCase();

        $result = $this->fixture->replay([]);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(1, ReplayAssert::executedCount($result['stdout']));
        self::assertSame(3, ReplayAssert::replayedCount($result['stdout']));
    }

    #[Test]
    public function with_the_flag_off_changing_a_lang_style_file_reruns_nothing(): void
    {
        $this->record();
        $this->fixture->repo->write('lang/messages.php', "<?php\n\nreturn ['pending' => 'Pendiente'];\n");

        $result = $this->fixture->replay([]);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($result['stdout']));
    }

    // -- the fix, with the flag on ---------------------------------------------

    #[Test]
    public function with_the_flag_on_the_enum_reaches_every_test_that_asserts_against_it(): void
    {
        $this->record(self::FLAG_ON);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        foreach (['tests/AlphaApprovedTest.php', 'tests/BetaRejectedTest.php', 'tests/GammaPendingTest.php'] as $file) {
            self::assertContains(self::ENUM, $graph->dependenciesOf($file), $file . ' should depend on the enum');
        }

        self::assertSame(
            ['tests/AlphaApprovedTest.php', 'tests/BetaRejectedTest.php', 'tests/GammaPendingTest.php'],
            $graph->testFilesDependingOn(self::ENUM),
        );
    }

    #[Test]
    public function with_the_flag_on_a_constants_class_reaches_the_test_that_only_reads_it(): void
    {
        // tests/DeltaLimitsTest.php touches no file with a method body: its only hop source
        // is its own file, which names App\Limits in a `use` statement.
        $this->record(self::FLAG_ON);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        self::assertContains(self::LIMITS, $graph->dependenciesOf('tests/DeltaLimitsTest.php'));
        self::assertCount(4, $graph->testFilesDependingOn(self::LIMITS));
    }

    #[Test]
    public function with_the_flag_on_the_graph_gains_edges_and_loses_none(): void
    {
        $this->record();
        $off = $this->edgeSet();

        $this->fixture->destroy();
        $this->fixture = FixtureProject::declarations();
        $this->record(self::FLAG_ON);
        $on = $this->edgeSet();

        self::assertSame([], array_values(array_diff($off, $on)), 'no edge coverage found may be dropped');
        self::assertSame(
            [
                'tests/BetaRejectedTest.php -> src/Limits.php',
                'tests/BetaRejectedTest.php -> src/ReviewDecision.php',
                'tests/DeltaLimitsTest.php -> src/Limits.php',
                'tests/GammaPendingTest.php -> src/Limits.php',
                'tests/GammaPendingTest.php -> src/ReviewDecision.php',
            ],
            array_values(array_diff($on, $off)),
        );
    }

    #[Test]
    public function with_the_flag_on_the_structural_fingerprint_carries_the_key(): void
    {
        $this->record(self::FLAG_ON);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        $structural = $graph->fingerprint()['structural'] ?? null;
        self::assertIsArray($structural);
        self::assertTrue($structural['static_declaration_edges'] ?? null);
    }

    #[Test]
    public function with_the_flag_on_changing_the_enum_reruns_every_test_that_uses_it(): void
    {
        $this->record(self::FLAG_ON);
        $this->addEnumCase();

        $result = $this->fixture->replay([], self::FLAG_ON);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(3, ReplayAssert::executedCount($result['stdout']));
        self::assertSame(1, ReplayAssert::replayedCount($result['stdout']));
    }

    #[Test]
    public function with_the_flag_on_changing_a_lang_style_file_reruns_the_whole_suite(): void
    {
        // The residue: `lang/messages.php` declares no name for anything to reference and
        // nothing in the project loads it, so neither technique can reach it. It must be
        // covered conservatively, not dropped.
        $this->record(self::FLAG_ON);
        $this->fixture->repo->write('lang/messages.php', "<?php\n\nreturn ['pending' => 'Pendiente'];\n");

        $result = $this->fixture->replay([], self::FLAG_ON);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(4, ReplayAssert::executedCount($result['stdout']));
    }

    #[Test]
    public function the_residue_is_explained_as_a_watch_match(): void
    {
        $this->record(self::FLAG_ON);

        $explain = $this->fixture->replay(['explain', 'lang/messages.php'], self::FLAG_ON);

        self::assertSame(0, $explain['exitCode'], $explain['stdout'] . $explain['stderr']);
        self::assertStringContainsString('lang/messages.php → tests', $explain['stdout']);
        self::assertStringContainsString('tests/DeltaLimitsTest.php', $explain['stdout']);
    }

    #[Test]
    public function a_filtered_re_record_leaves_the_edge_set_exactly_as_it_was(): void
    {
        // The determinism property. A `run` re-records edges for the subset it executed,
        // and those edges are union'd in (Graph::unionEdges()). If the static hop followed
        // the declaration-only edges it added last time, the graph would keep growing pass
        // after pass and two machines would compute different content keys for identical
        // code (Analysis\StaticEdges::hopSources()).
        $this->record(self::FLAG_ON);
        $before = $this->edgeSet();

        $this->addEnumCase();
        $first = $this->fixture->replay([], self::FLAG_ON);
        self::assertSame(0, $first['exitCode'], $first['stdout'] . $first['stderr']);
        $afterFirst = $this->edgeSet();

        $second = $this->fixture->replay([], self::FLAG_ON);
        self::assertSame(0, $second['exitCode'], $second['stdout'] . $second['stderr']);

        self::assertSame($before, $afterFirst);
        self::assertSame($before, $this->edgeSet());
    }

    // -- order independence ----------------------------------------------------

    #[Test]
    public function with_the_flag_on_the_graph_is_the_same_sequentially_and_in_parallel(): void
    {
        // The property the whole feature is for. With the flag OFF, Paratest gives each test
        // file its own process, so each becomes the first loader of the enum and the graph
        // comes out *different* from the sequential one — the dependency list depends on the
        // runner and the worker distribution. With it on, both are identical, because an
        // edge now only ever comes from a called body or from a name written in source.
        if (! FixtureProject::paratestAvailable()) {
            self::markTestSkipped('vendor/bin/paratest is not installed in this package (composer install --no-dev?).');
        }

        $this->record(self::FLAG_ON);
        $sequential = $this->edgeSet();

        $parallel = FixtureProject::declarations();

        try {
            $result = $parallel->replay(['record', '-p', '2'], self::FLAG_ON);
            self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);

            $graph = ReplayAssert::loadGraph($parallel);
            self::assertNotNull($graph);

            $edges = [];

            foreach ($graph->allTestFiles() as $testFile) {
                foreach ($graph->dependenciesOf($testFile) as $dependency) {
                    $edges[] = $testFile . ' -> ' . $dependency;
                }
            }

            sort($edges);

            self::assertSame($sequential, $edges);
        } finally {
            $parallel->destroy();
        }
    }

    #[Test]
    public function the_classifier_cache_lives_in_the_state_directory_and_prune_all_clears_it(): void
    {
        $this->record(self::FLAG_ON);

        $analysis = ReplayAssert::stateDir($this->fixture) . '/analysis';
        self::assertDirectoryExists($analysis);
        self::assertNotSame([], glob($analysis . '/*/*/*.json') ?: []);

        $pruned = $this->fixture->replay(['prune', '--all'], self::FLAG_ON);

        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);
        self::assertDirectoryDoesNotExist($analysis);
    }

    #[Test]
    public function the_in_process_entry_point_honours_the_flag_too(): void
    {
        // SPEC.md §6.1 has two ways in, and the extension registered directly in the user's
        // own phpunit.xml (no wrapper) reaches Analysis through a different path:
        // ReplayState::bootInProcess() builds both the Recorder's FactsCache and the
        // StaticEdges the in-process persist uses. A typo there would be invisible to every
        // wrapper-driven test above.
        $inprocess = FixtureProject::inprocess();

        try {
            $result = $inprocess->phpunitInProcess([], self::FLAG_ON);
            self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);

            $graph = ReplayAssert::loadGraph($inprocess);
            self::assertNotNull($graph);

            $structural = $graph->fingerprint()['structural'] ?? null;
            self::assertIsArray($structural);
            self::assertTrue($structural['static_declaration_edges'] ?? null);

            self::assertNotSame(
                [],
                glob(ReplayAssert::stateDir($inprocess) . '/analysis/*/*/*.json') ?: [],
                'the classifier should have run and cached its facts',
            );
        } finally {
            $inprocess->destroy();
        }
    }

    // -- every other reader of the fingerprint --------------------------------

    #[Test]
    public function status_does_not_report_phantom_drift_with_the_flag_on(): void
    {
        // Regression: `StatusCommand` computed its "current" fingerprint without the flag,
        // so it diffed a fingerprint that never carries the key against a stored one that
        // does, and printed a permanent `static_declaration_edges=true (drift)`.
        $this->fixture->repo->write('phpunit-replay.php', "<?php\n\nreturn ['static_declaration_edges' => true];\n");
        $this->fixture->repo->commitAll('enable static declaration edges');
        $this->record();

        $status = $this->fixture->replay(['status']);

        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertStringContainsString('static_declaration_edges=true', $status['stdout']);
        self::assertStringNotContainsString('(drift)', $status['stdout']);
    }

    #[Test]
    public function pull_accepts_a_remote_baseline_recorded_with_the_same_flag(): void
    {
        // Regression, and the worse half of the same omission: `PullCommand` rejected its
        // own remote baseline on every pull, with "the remote main baseline does not match
        // this project (static_declaration_edges): not stored".
        $this->fixture->repo->write('phpunit-replay.php', "<?php\n\nreturn ['static_declaration_edges' => true];\n");
        $this->fixture->repo->commitAll('enable static declaration edges');
        $this->fixture->repo->git('remote', 'add', 'origin', 'https://example.invalid/acme/declarations.git');

        $sharedCache = TempDir::make('declarations-remote');
        $this->temporaries[] = $sharedCache;
        $remote = ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $sharedCache];

        $recorded = $this->fixture->replay(['record'], $remote);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $pushed = $this->fixture->replay(['push', '--graph'], $remote);
        self::assertSame(0, $pushed['exitCode'], $pushed['stdout'] . $pushed['stderr']);

        $machine2 = TempDir::make('declarations-machine2');
        $this->temporaries[] = $machine2;
        $second = $this->fixture->copyTo($machine2 . '/other');
        $this->fixtures[] = $second;

        $pulled = $second->replay(['pull'], $remote);

        self::assertSame(0, $pulled['exitCode'], $pulled['stdout'] . $pulled['stderr']);
        self::assertStringNotContainsString('does not match this project', $pulled['stdout']);

        $graph = ReplayAssert::loadGraph($second);
        self::assertNotNull($graph, 'the pulled graph should have been stored');
        self::assertContains(self::ENUM, $graph->dependenciesOf('tests/BetaRejectedTest.php'));
    }

    // -- flipping the flag -----------------------------------------------------

    #[Test]
    public function flipping_the_flag_on_discards_the_graph_as_structural_drift(): void
    {
        // Deliberate invalidation: a graph whose edges came from coverage attribution must
        // never be read back by a pass that also produces static edges.
        $this->record();

        $result = $this->fixture->replay([], self::FLAG_ON);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString(
            'structural change (static_declaration_edges, analysis_rules): the cached baseline cannot be used, recording a fresh baseline',
            $result['stderr'],
        );
        self::assertStringContainsString('recorded 4 tests in 4 test files', $result['stdout']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        self::assertContains(self::ENUM, $graph->dependenciesOf('tests/BetaRejectedTest.php'));
    }

    #[Test]
    public function flipping_the_flag_off_again_discards_the_graph_too(): void
    {
        $this->record(self::FLAG_ON);

        $result = $this->fixture->replay([]);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString(
            'structural change (static_declaration_edges, analysis_rules): the cached baseline cannot be used, recording a fresh baseline',
            $result['stderr'],
        );
        self::assertStringContainsString('recorded 4 tests in 4 test files', $result['stdout']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        self::assertNotContains(self::ENUM, $graph->dependenciesOf('tests/BetaRejectedTest.php'));
    }

    #[Test]
    public function the_flag_can_also_come_from_the_project_config_file(): void
    {
        $this->fixture->repo->write('phpunit-replay.php', "<?php\n\nreturn ['static_declaration_edges' => true];\n");
        $this->fixture->repo->commitAll('enable static declaration edges');

        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        self::assertContains(self::ENUM, $graph->dependenciesOf('tests/BetaRejectedTest.php'));
    }

    // -- the middle the two halves used to miss -------------------------------

    #[Test]
    public function a_file_with_a_body_reaches_the_tests_that_only_name_it(): void
    {
        // The design flaw the shape-based index had: `src/Registry.php` has a method body,
        // so the static index declined it, and `tests/AaaRegistryConstTest.php` never enters
        // that body, so the Recorder declined it too. `Select\ResiduePatterns` then declined
        // the file as well, because the OTHER test's coverage had given it a `fileId`. The
        // edge existed with the flag off (the top-level `class_alias()` call gives the file a
        // load-time footprint the Pest heuristic keeps) and vanished with it on.
        $this->extend();
        $this->record(self::FLAG_ON);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        self::assertSame(
            ['tests/AaaRegistryConstTest.php', 'tests/ZzzRegistryCallTest.php'],
            $graph->testFilesDependingOn(self::REGISTRY),
        );
    }

    #[Test]
    public function changing_a_constant_reruns_the_test_that_only_reads_it(): void
    {
        // Same thing at the level that matters. Before the fix this printed
        // "✓ 1 executed · 5 replayed" — a green run over a broken assertion — where the flag
        // off printed "✗ 2 executed".
        $this->extend();
        $this->record(self::FLAG_ON);

        $this->fixture->repo->write(
            self::REGISTRY,
            str_replace("'alpha', 'beta'", "'alpha', 'BETA'", $this->fixture->repo->read(self::REGISTRY)),
        );

        $result = $this->fixture->replay([], self::FLAG_ON);

        self::assertSame(1, $result['exitCode'], 'the const-only test must fail, not be replayed');
        self::assertSame(2, ReplayAssert::executedCount($result['stdout']));
    }

    #[Test]
    public function an_enum_that_gains_one_method_still_reaches_every_test_that_reads_its_cases(): void
    {
        // The cliff: adding a single method to a declaration-only file used to take it out of
        // the static index entirely, so every test that merely reads its cases lost the edge
        // and only the method's caller kept one.
        $this->extend();
        $this->addEnumMethod();
        $this->record(self::FLAG_ON);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        foreach (['tests/AlphaApprovedTest.php', 'tests/BetaRejectedTest.php', 'tests/GammaPendingTest.php'] as $file) {
            self::assertContains(self::ENUM, $graph->dependenciesOf($file), $file . ' should depend on the enum');
        }
    }

    // -- the invariant ---------------------------------------------------------

    #[Test]
    public function no_test_loses_an_edge_the_flag_off_pass_gave_it(): void
    {
        // The promise that makes the flag safe to ship, over the fixture extended with every
        // shape that used to fall through the middle: a class with both a method body and a
        // top-level statement, a test that only reads its constant, a test that calls it, and
        // an enum with a method. An edge coverage found may only be dropped when it was
        // first-loader noise AND something else now covers the file — so at the edge level
        // the flag-on set must be a superset, with no exceptions to audit.
        $this->extend();
        $this->addEnumMethod();
        $this->fixture->repo->commitAll('every shape the two halves used to miss');

        $this->record();
        $off = $this->edgeSet();

        $this->fixture->destroy();
        $this->fixture = FixtureProject::declarations();
        $this->extend();
        $this->addEnumMethod();
        $this->fixture->repo->commitAll('every shape the two halves used to miss');

        $this->record(self::FLAG_ON);
        $on = $this->edgeSet();

        self::assertSame([], array_values(array_diff($off, $on)), 'no edge coverage found may be dropped');
        self::assertNotSame([], array_values(array_diff($on, $off)), 'and the flag must add some');
    }

    // -- a test whose coverage reports nothing at all --------------------------

    #[Test]
    public function a_test_whose_coverage_reports_nothing_still_gets_its_static_edges(): void
    {
        // `Record\Recorder::endTest()` only creates `perTestFiles[$test]` inside its loop over
        // the files coverage reported, so a test whose coverage saw nothing has no key in
        // `edges.json` at all — and `Cache\GraphUpdater` used to hand `StaticEdges` exactly
        // those keys, so the test never reached it. `tests/DeltaLimitsTest.php` is the
        // position `Analysis\StaticEdges` calls the hardest one, and it recorded zero edges.
        $this->excludeTestsFromTheCoverageScope();
        $this->record(self::FLAG_ON);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        self::assertNotSame(
            [],
            $graph->dependenciesOf('tests/AlphaApprovedTest.php'),
            'the coverage scope should still report src/ for the tests that do call into it',
        );
        self::assertContains(self::LIMITS, $graph->dependenciesOf('tests/DeltaLimitsTest.php'));
        self::assertCount(4, $graph->testFilesDependingOn(self::LIMITS));
    }

    #[Test]
    public function the_same_holds_when_a_coverage_report_is_requested(): void
    {
        // Reachable with no configuration change at all: `Record\PiggybackCoverageDriver`
        // reads PHPUnit's own coverage, already narrowed to `<source><include>`, so no test
        // file ever appears in its own coverage.
        $recorded = $this->fixture->replay(['record', '--coverage-text=/dev/null'], self::FLAG_ON);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        self::assertNotSame(
            [],
            $graph->dependenciesOf('tests/DeltaLimitsTest.php'),
            'tests/DeltaLimitsTest.php recorded no edge at all',
        );
        self::assertContains(self::LIMITS, $graph->dependenciesOf('tests/DeltaLimitsTest.php'));
        self::assertCount(4, $graph->testFilesDependingOn(self::LIMITS));
    }

    /**
     * Adds the shapes the `declarations` fixture deliberately does not have: a class with
     * BOTH a method body and a top-level statement (so the flag-off pass keeps a load-time
     * edge to it rather than dropping it as the highest-numbered line), a test that only
     * reads its constant, and a test that calls its method.
     */
    private function extend(): void
    {
        $this->fixture->repo->write(self::REGISTRY, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            final class Registry
            {
                public const NAMES = ['alpha', 'beta'];

                public static function first(): string
                {
                    return self::NAMES[0];
                }
            }

            class_alias(Registry::class, 'App\LegacyRegistry');

            PHP);

        $this->fixture->repo->write('tests/AaaRegistryConstTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use App\Registry;
            use PHPUnit\Framework\TestCase;

            /** Reads a constant only: no body of Registry is ever entered. Sorts first, so it is the first loader. */
            final class AaaRegistryConstTest extends TestCase
            {
                public function test_names_are_what_we_expect(): void
                {
                    self::assertSame(['alpha', 'beta'], Registry::NAMES);
                }
            }

            PHP);

        $this->fixture->repo->write('tests/ZzzRegistryCallTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use App\Registry;
            use PHPUnit\Framework\TestCase;

            /** Calls a real method, so this one has a behavioural edge either way. */
            final class ZzzRegistryCallTest extends TestCase
            {
                public function test_first_name(): void
                {
                    self::assertSame('alpha', Registry::first());
                }
            }

            PHP);

        $this->fixture->repo->commitAll('a class with a body and a top-level statement');
    }

    /** Turns the enum into a mixed file: cases plus one real method body. */
    private function addEnumMethod(): void
    {
        $source = $this->fixture->repo->read(self::ENUM);
        $patched = str_replace(
            "    case Rejected = 'rejected';\n}",
            "    case Rejected = 'rejected';\n\n    public function label(): string\n    {\n        return ucfirst(\$this->value);\n    }\n}",
            $source,
        );

        self::assertNotSame($source, $patched, 'the enum anchor should still be there');

        $this->fixture->repo->write(self::ENUM, $patched);
        $this->fixture->repo->commitAll('give the enum a method body');
    }

    /** Drops the test directory out of the coverage scope, so no test file appears in its own coverage. */
    private function excludeTestsFromTheCoverageScope(): void
    {
        $xml = $this->fixture->repo->read('phpunit.xml');
        $patched = str_replace(
            "        </include>\n",
            "        </include>\n        <exclude>\n            <directory>tests</directory>\n        </exclude>\n",
            $xml,
        );

        self::assertNotSame($xml, $patched, 'the <include> anchor should still be there');

        $this->fixture->repo->write('phpunit.xml', $patched);
        $this->fixture->repo->commitAll('exclude tests from the coverage scope');
    }

    /** @param array<string, string> $env */
    private function record(array $env = []): void
    {
        $recorded = $this->fixture->replay(['record'], $env);

        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    /** @return list<string> "<test file> -> <source file>", sorted */
    private function edgeSet(): array
    {
        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        $edges = [];

        foreach ($graph->allTestFiles() as $testFile) {
            foreach ($graph->dependenciesOf($testFile) as $dependency) {
                $edges[] = $testFile . ' -> ' . $dependency;
            }
        }

        sort($edges);

        return $edges;
    }

    private function addEnumCase(): void
    {
        $source = $this->fixture->repo->read(self::ENUM);
        $patched = str_replace(
            "    case Rejected = 'rejected';",
            "    case Rejected = 'rejected';\n    case Withdrawn = 'withdrawn';",
            $source,
        );

        self::assertNotSame($source, $patched, 'the enum case anchor should still be there');

        $this->fixture->repo->write(self::ENUM, $patched);
    }
}
