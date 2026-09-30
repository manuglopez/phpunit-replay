<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Laravel\Rules\BladeRule;
use Manuglopez\Replay\Laravel\Rules\MigrationRule;
use Manuglopez\Replay\Laravel\Rules\SiblingRule;
use Manuglopez\Replay\Select\NonEdgeInputs;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\GitRepo;
use PHPUnit\Framework\TestCase;

/**
 * The non-edge input digest over a real repository: which changes move a test file's digest
 * (exactly the ones a non-edge rule would select it for) and which do not.
 */
final class NonEdgeInputsTest extends TestCase
{
    private GitRepo $repo;

    private Graph $graph;

    protected function setUp(): void
    {
        $this->repo = GitRepo::init();
        $this->repo->write('.gitignore', "ignored.txt\ntests/Fixtures/ignored.txt\n");
        $this->repo->write('src/A.php', "<?php\nfinal class A {}\n");
        $this->repo->write('tests/ATest.php', "<?php\nfinal class ATest {}\n");
        $this->repo->write('tests/BTest.php', "<?php\nfinal class BTest {}\n");
        $this->repo->write('tests/Fixtures/data.txt', "one\n");
        $this->repo->write('notes.txt', "nobody reads this\n");
        $this->repo->commitAll('initial');

        $this->graph = new Graph($this->repo->root);
        $this->graph->unionEdges(['tests/ATest.php' => ['src/A.php'], 'tests/BTest.php' => []]);
    }

    protected function tearDown(): void
    {
        $this->repo->destroy();
    }

    public function test_the_digest_is_versioned_and_a_function_of_the_tree(): void
    {
        $digest = $this->inputs()->digestFor('tests/ATest.php');

        self::assertNotNull($digest);
        self::assertStringStartsWith(NonEdgeInputs::VERSION . ':', $digest);
        self::assertSame($digest, $this->inputs()->digestFor('tests/ATest.php'));
    }

    public function test_a_watched_file_moves_the_digest_of_the_tests_its_pattern_maps_to(): void
    {
        $before = $this->inputs()->digestFor('tests/ATest.php');

        $this->repo->write('tests/Fixtures/data.txt', "two\n");

        self::assertNotSame($before, $this->inputs()->digestFor('tests/ATest.php'));
    }

    public function test_adding_and_deleting_a_watched_file_move_it_too(): void
    {
        $before = $this->inputs()->digestFor('tests/ATest.php');

        $this->repo->write('tests/Fixtures/new.txt', "untracked\n");
        $added = $this->inputs()->digestFor('tests/ATest.php');
        self::assertNotSame($before, $added);

        $this->repo->delete('tests/Fixtures/new.txt');
        $this->repo->delete('tests/Fixtures/data.txt');
        $deleted = $this->inputs()->digestFor('tests/ATest.php');
        self::assertNotSame($before, $deleted);
        self::assertNotSame($added, $deleted);
    }

    public function test_what_no_non_edge_rule_selects_for_leaves_it_alone(): void
    {
        $before = $this->inputs()->digestFor('tests/ATest.php');

        // An edge input (the key's business), a file matching no pattern, a file git ignores
        // — untracked, or tracked and matching an ignore pattern — and a comment-only edit.
        $this->repo->write('src/A.php', "<?php\nfinal class A { public int \$x = 1; }\n");
        $this->repo->write('notes.txt', "still nobody\n");
        $this->repo->write('tests/Fixtures/ignored.txt', "ignored\n");
        $this->repo->write('ignored.txt', "ignored\n");
        $this->repo->git('add', '-f', 'tests/Fixtures/ignored.txt');

        self::assertSame($before, $this->inputs()->digestFor('tests/ATest.php'));
    }

    public function test_a_file_of_the_universe_is_the_key_s_input_not_the_digest_s(): void
    {
        $this->graph->unionEdges(['tests/BTest.php' => ['tests/Fixtures/data.txt']]);
        $before = $this->inputs()->digestFor('tests/ATest.php');

        $this->repo->write('tests/Fixtures/data.txt', "two\n");

        self::assertSame($before, $this->inputs()->digestFor('tests/ATest.php'));
    }

    public function test_refresh_recomputes_scopes_after_the_graph_changes(): void
    {
        $inputs = $this->inputs();
        $before = $inputs->digestFor('tests/ATest.php');

        $this->graph->unionEdges(['tests/BTest.php' => ['tests/Fixtures/data.txt']]);
        self::assertSame($before, $inputs->digestFor('tests/ATest.php'), 'memoised until told');

        $inputs->refresh();
        self::assertNotSame($before, $inputs->digestFor('tests/ATest.php'), 'data.txt left the watch scope');
    }

    public function test_the_residue_scope_exists_only_with_static_declaration_edges(): void
    {
        $off = $this->inputs()->digestFor('tests/BTest.php');
        $on = $this->inputs(staticDeclarationEdges: true)->digestFor('tests/BTest.php');

        $this->repo->write('config/app.php', "<?php\nreturn ['name' => 'x'];\n");

        self::assertSame($off, $this->inputs()->digestFor('tests/BTest.php'));
        self::assertNotSame($on, $this->inputs(staticDeclarationEdges: true)->digestFor('tests/BTest.php'));
    }

    public function test_a_migration_is_an_input_of_the_tests_using_its_tables_only(): void
    {
        $this->repo->write('database/migrations/2024_create_users.php', "<?php\nSchema::create('users', function () {});\n");
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users'], 'tests/BTest.php' => ['orders']]);
        $rules = ['migration' => new MigrationRule()];
        $watch = ['database/migrations/**' => ['tests']];

        $a = $this->inputs(extraRules: $rules, watch: $watch)->digestFor('tests/ATest.php');
        $b = $this->inputs(extraRules: $rules, watch: $watch)->digestFor('tests/BTest.php');

        $this->repo->write('database/migrations/2024_create_users.php', "<?php\nSchema::create('users', function () { \$x = 1; });\n");

        self::assertNotSame($a, $this->inputs(extraRules: $rules, watch: $watch)->digestFor('tests/ATest.php'));
        // Claimed by the migration rule, so never reaches the migrations watch pattern.
        self::assertSame($b, $this->inputs(extraRules: $rules, watch: $watch)->digestFor('tests/BTest.php'));
    }

    public function test_a_new_sibling_is_an_input_of_the_tests_depending_on_its_directory(): void
    {
        $this->repo->write('app/Providers/AppProvider.php', "<?php\nfinal class AppProvider {}\n");
        $this->graph->unionEdges(['tests/ATest.php' => ['app/Providers/AppProvider.php']]);
        $rules = ['sibling' => new SiblingRule()];

        $a = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $b = $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php');

        $this->repo->write('app/Providers/NewProvider.php', "<?php\nfinal class NewProvider {}\n");

        self::assertNotSame($a, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'));
        self::assertSame($b, $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php'));
    }

    public function test_an_unknown_blade_template_is_an_input_of_the_tests_rendering_templates(): void
    {
        $this->repo->write('resources/views/home.blade.php', "@include('partial')\n");
        $this->repo->write('resources/views/partial.blade.php', "<p>one</p>\n");
        $this->graph->unionEdges(['tests/ATest.php' => ['resources/views/home.blade.php']]);
        $rules = ['blade' => new BladeRule()];

        $a = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $b = $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php');

        $this->repo->write('resources/views/partial.blade.php', "<p>two</p>\n");

        self::assertNotSame($a, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'));
        self::assertSame($b, $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php'));
    }

    public function test_a_template_blade_claims_leaves_the_views_watch_pattern_and_an_orphan_does_not(): void
    {
        $this->repo->write('resources/views/home.blade.php', "@include('partial')\n");
        $this->repo->write('resources/views/partial.blade.php', "<p>one</p>\n");
        $this->repo->write('resources/views/orphan.blade.php', "<p>nobody includes me</p>\n");
        $this->graph->unionEdges(['tests/ATest.php' => ['resources/views/home.blade.php']]);
        $rules = ['blade' => new BladeRule()];
        $watch = ['resources/views/**' => ['tests']];

        $b = $this->inputs(extraRules: $rules, watch: $watch)->digestFor('tests/BTest.php');

        // BladeRule narrows the partial to home's dependents: BTest is not one of them.
        $this->repo->write('resources/views/partial.blade.php', "<p>two</p>\n");
        self::assertSame($b, $this->inputs(extraRules: $rules, watch: $watch)->digestFor('tests/BTest.php'));

        // A template with no known ancestor is WatchRule's, for every test it maps to.
        $this->repo->write('resources/views/orphan.blade.php', "<p>changed</p>\n");
        self::assertNotSame($b, $this->inputs(extraRules: $rules, watch: $watch)->digestFor('tests/BTest.php'));
    }

    public function test_what_this_package_writes_into_the_tree_never_counts(): void
    {
        $watch = ['.phpunit*' => ['tests'], 'state/**' => ['tests']];
        $before = $this->inputs(watch: $watch, stateDir: $this->repo->root . '/state')->digestFor('tests/ATest.php');

        $this->repo->write('.phpunit-replay.xml', '<phpunit/>');
        $this->repo->write('state/graph.json', '{}');

        self::assertSame($before, $this->inputs(watch: $watch, stateDir: $this->repo->root . '/state')->digestFor('tests/ATest.php'));
    }

    public function test_covers_names_what_a_published_graph_depends_on(): void
    {
        $inputs = $this->inputs();

        self::assertTrue($inputs->covers('tests/ATest.php'), 'a test file');
        self::assertTrue($inputs->covers('src/A.php'), 'a file of the universe');
        self::assertTrue($inputs->covers('tests/Fixtures/data.txt'), 'a member of a scope');
        self::assertFalse($inputs->covers('notes.txt'));
    }

    public function test_no_digest_when_the_tree_cannot_be_listed(): void
    {
        $inputs = new NonEdgeInputs(
            $this->graph,
            new TestPaths(['tests'], [], ['Test.php']),
            new WatchPatterns(),
            $this->repo->root,
            new FileHashes($this->repo->root),
            new Git(sys_get_temp_dir() . '/definitely-not-a-repository-' . bin2hex(random_bytes(4))),
        );

        self::assertNull($inputs->digestFor('tests/ATest.php'));
        self::assertTrue($inputs->covers('notes.txt'), 'fails closed');
    }

    /**
     * @param array{migration?: MigrationRule, sibling?: SiblingRule, blade?: BladeRule} $extraRules
     * @param array<string, list<string>> $watch
     */
    private function inputs(bool $staticDeclarationEdges = false, array $extraRules = [], array $watch = [], ?string $stateDir = null): NonEdgeInputs
    {
        $patterns = new WatchPatterns();
        $patterns->add(['tests/**/Fixtures/**' => ['tests'], ...$watch]);

        return new NonEdgeInputs(
            $this->graph,
            new TestPaths(['tests'], [], ['Test.php']),
            $patterns,
            $this->repo->root,
            new FileHashes($this->repo->root),
            new Git($this->repo->root),
            $extraRules,
            $staticDeclarationEdges,
            $stateDir,
        );
    }
}
