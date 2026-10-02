<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Laravel\MigrationPaths;
use Manuglopez\Replay\Laravel\Rules\BladeRule;
use Manuglopez\Replay\Laravel\Rules\MigrationRule;
use Manuglopez\Replay\Laravel\Rules\SchemaDumpRule;
use Manuglopez\Replay\Laravel\Rules\SiblingRule;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Select\NonEdgeInputs;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\GitRepo;
use PHPUnit\Framework\TestCase;

/**
 * The non-edge input digest over a real repository: which changes move a test file's digest
 * (exactly the ones a non-edge rule would select it for) and which do not — in particular,
 * nothing another test file records.
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
        self::assertSame('n4', NonEdgeInputs::VERSION);
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

    public function test_another_test_gaining_or_losing_an_edge_never_moves_this_digest(): void
    {
        // The cascade the universe-relative definition had: one test gaining an edge to a
        // watched file took it out of every other test's scope, and all of them re-ran.
        $inputs = $this->inputs();
        $before = $inputs->digestFor('tests/BTest.php');

        $this->graph->unionEdges(['tests/ATest.php' => ['tests/Fixtures/data.txt']]);
        $inputs->refresh();
        self::assertSame($before, $inputs->digestFor('tests/BTest.php'), 'A gained an edge');

        $this->repo->delete('tests/ATest.php');
        $this->graph->pruneMissingTestFiles();
        $inputs->refresh();
        self::assertSame($before, $inputs->digestFor('tests/BTest.php'), 'the only holder of the edge is gone');
    }

    public function test_this_test_gaining_an_edge_moves_the_file_from_its_scope_into_its_key(): void
    {
        $inputs = $this->inputs();
        $before = $inputs->digestFor('tests/ATest.php');

        $this->graph->unionEdges(['tests/ATest.php' => ['tests/Fixtures/data.txt']]);
        self::assertSame($before, $inputs->digestFor('tests/ATest.php'), 'memoised until told');

        $inputs->refresh();
        $after = $inputs->digestFor('tests/ATest.php');
        self::assertNotSame($before, $after, 'data.txt left ATest\'s scope: it re-runs once');

        // From now on data.txt is ATest's key input, not its digest's.
        $this->repo->write('tests/Fixtures/data.txt', "two\n");
        self::assertSame($after, $this->inputs()->digestFor('tests/ATest.php'));
    }

    public function test_the_residue_scope_exists_only_with_static_declaration_edges(): void
    {
        $off = $this->inputs()->digestFor('tests/BTest.php');
        $on = $this->inputs(staticDeclarationEdges: true)->digestFor('tests/BTest.php');

        $this->repo->write('config/app.php', "<?php\nreturn ['name' => 'x'];\n");

        self::assertSame($off, $this->inputs()->digestFor('tests/BTest.php'));
        self::assertNotSame($on, $this->inputs(staticDeclarationEdges: true)->digestFor('tests/BTest.php'));
    }

    public function test_a_file_source_exclude_keeps_out_of_coverage_is_every_test_s_input_whatever_the_flag(): void
    {
        $this->repo->write('app/Providers/AppServiceProvider.php', "<?php\nfinal class AppServiceProvider {}\n");
        $scope = new SourceScope([$this->repo->root . '/app'], [$this->repo->root . '/app/Providers'], [realpath($this->repo->root) . '/app/Providers']);

        $before = $this->inputs(scope: $scope)->digestFor('tests/BTest.php');
        $this->repo->write('app/Providers/AppServiceProvider.php', "<?php\nfinal class AppServiceProvider { public int \$x = 1; }\n");

        self::assertNotSame($before, $this->inputs(scope: $scope)->digestFor('tests/BTest.php'));

        // A configured watch pattern naming the file only adds: it stays every test's input,
        // and the pattern's targets carry it twice over (selection runs every test for it).
        $watched = ['app/**' => ['tests/ATest.php']];
        $a = $this->inputs(watch: $watched, scope: $scope)->digestFor('tests/ATest.php');
        $b = $this->inputs(watch: $watched, scope: $scope)->digestFor('tests/BTest.php');
        $this->repo->write('app/Providers/AppServiceProvider.php', "<?php\nfinal class AppServiceProvider { public int \$x = 2; }\n");
        self::assertNotSame($a, $this->inputs(watch: $watched, scope: $scope)->digestFor('tests/ATest.php'));
        self::assertNotSame($b, $this->inputs(watch: $watched, scope: $scope)->digestFor('tests/BTest.php'), 'the pattern never narrows it');
    }

    public function test_conservative_a_migration_is_an_input_of_the_tests_using_its_tables_only(): void
    {
        $this->repo->write('database/migrations/2024_create_users.php', "<?php\nSchema::create('users', function () {});\n");
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users'], 'tests/BTest.php' => ['orders']]);
        $rules = ['migration' => self::conservative()];

        $a = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $b = $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php');

        $this->repo->write('database/migrations/2024_create_users.php', "<?php\nSchema::create('users', function () { \$x = 1; });\n");

        self::assertNotSame($a, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'));
        self::assertSame($b, $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php'));
    }

    public function test_a_migration_with_no_table_to_narrow_by_is_every_test_s_input(): void
    {
        $this->repo->write('database/migrations/2024_data.php', "<?php\nreturn 1;\n");
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users']]);
        $rules = ['migration' => self::conservative()];

        $b = $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php');
        $this->repo->write('database/migrations/2024_data.php', "<?php\nreturn 2;\n");

        self::assertNotSame($b, $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php'));
    }

    public function test_conservative_a_migration_for_tables_no_test_records_is_an_input_of_every_database_test(): void
    {
        $this->repo->write('database/migrations/2030_create_widgets.php', "<?php\nSchema::create('widgets', function () {});\n");
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users']]);
        $rules = ['migration' => self::conservative()];

        $a = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $b = $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php');

        $this->repo->write('database/migrations/2030_create_widgets.php', "<?php\nSchema::create('widgets', function () { \$x = 1; });\n");

        self::assertNotSame($a, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'), 'A uses the database');
        self::assertSame($b, $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php'), 'B records no table');
    }

    public function test_a_file_coverage_cannot_see_stays_every_test_s_input_when_another_test_has_an_edge_to_it(): void
    {
        $this->repo->write('app/Providers/AppServiceProvider.php', "<?php\nfinal class AppServiceProvider {}\n");
        $scope = new SourceScope([$this->repo->root . '/app'], [$this->repo->root . '/app/Providers'], [realpath($this->repo->root) . '/app/Providers']);
        // A static-declaration or once-process edge: A records it, B still boots it.
        $this->graph->unionEdges(['tests/ATest.php' => ['app/Providers/AppServiceProvider.php']]);

        $b = $this->inputs(scope: $scope)->digestFor('tests/BTest.php');
        $this->repo->write('app/Providers/AppServiceProvider.php', "<?php\nfinal class AppServiceProvider { public int \$x = 1; }\n");

        self::assertNotSame($b, $this->inputs(scope: $scope)->digestFor('tests/BTest.php'));
    }

    public function test_a_template_no_rule_claims_is_every_test_s_input_through_the_views_fallback(): void
    {
        $this->repo->write('resources/views/home.blade.php', "@include('partial')\n");
        $this->repo->write('resources/views/partial.blade.php', "<p>one</p>\n");
        $this->graph->unionEdges(['tests/ATest.php' => ['resources/views/home.blade.php']]);
        $rules = ['blade' => new BladeRule()];
        $fallback = ['resources/views/**' => ['tests']];

        $a = $this->inputs(extraRules: $rules, fallback: $fallback)->digestFor('tests/ATest.php');
        $b = $this->inputs(extraRules: $rules, fallback: $fallback)->digestFor('tests/BTest.php');

        // An error page, a pagination override: nothing references it.
        $this->repo->write('resources/views/errors/404.blade.php', "<h1>gone</h1>\n");
        self::assertNotSame($a, $this->inputs(extraRules: $rules, fallback: $fallback)->digestFor('tests/ATest.php'));
        self::assertNotSame($b, $this->inputs(extraRules: $rules, fallback: $fallback)->digestFor('tests/BTest.php'));

        // A template the Blade rule attributes is not the fallback's.
        $b = $this->inputs(extraRules: $rules, fallback: $fallback)->digestFor('tests/BTest.php');
        $this->repo->write('resources/views/partial.blade.php', "<p>two</p>\n");
        self::assertSame($b, $this->inputs(extraRules: $rules, fallback: $fallback)->digestFor('tests/BTest.php'));
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

    public function test_a_template_is_an_input_of_the_tests_rendering_a_template_that_references_it(): void
    {
        $this->repo->write('resources/views/home.blade.php', "@if(\$admin) @include('partial') @endif\n");
        $this->repo->write('resources/views/partial.blade.php', "<p>one</p>\n");
        $this->repo->write('resources/views/orphan.blade.php', "<p>nobody includes me</p>\n");
        $this->graph->unionEdges(['tests/ATest.php' => ['resources/views/home.blade.php']]);
        $rules = ['blade' => new BladeRule()];

        $a = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $b = $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php');

        $this->repo->write('resources/views/partial.blade.php', "<p>two</p>\n");
        self::assertNotSame($a, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'));
        self::assertSame($b, $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php'));

        // The partial stays A's input when B happens to render it: B's edge is not A's.
        $a = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $this->graph->unionEdges(['tests/BTest.php' => ['resources/views/partial.blade.php']]);
        self::assertSame($a, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'));

        // A template nothing references and no test renders is nobody's input.
        $this->repo->write('resources/views/orphan.blade.php', "<p>changed</p>\n");
        self::assertSame($a, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'));
    }

    public function test_what_this_package_writes_into_the_tree_never_counts(): void
    {
        $watch = ['.phpunit*' => ['tests'], 'state/**' => ['tests']];
        $before = $this->inputs(watch: $watch, stateDir: $this->repo->root . '/state')->digestFor('tests/ATest.php');

        $this->repo->write('.phpunit-replay.xml', '<phpunit/>');
        $this->repo->write('state/graph.json', '{}');

        self::assertSame($before, $this->inputs(watch: $watch, stateDir: $this->repo->root . '/state')->digestFor('tests/ATest.php'));
    }

    public function test_covers_names_what_a_published_graph_depends_on_even_once_deleted(): void
    {
        $inputs = $this->inputs();

        self::assertTrue($inputs->covers('tests/ATest.php'), 'a test file');
        self::assertTrue($inputs->covers('src/A.php'), 'a file of the universe');
        self::assertTrue($inputs->covers('tests/Fixtures/data.txt'), 'a member of a scope');
        self::assertTrue($inputs->covers('tests/Fixtures/deleted.txt'), 'a path a scope would hold');
        self::assertFalse($inputs->covers('notes.txt'));
    }

    public function test_a_stamp_is_refused_for_a_file_that_changed_after_it_was_read(): void
    {
        $untouched = $this->inputs();
        self::assertSame($untouched->digestFor('tests/ATest.php'), $untouched->stampFor('tests/ATest.php'));

        $inputs = $this->inputs();
        $inputs->digestFor('tests/ATest.php');

        $this->repo->write('tests/Fixtures/data.txt', "changed while the tests ran, and longer\n");

        self::assertNull($inputs->stampFor('tests/ATest.php'), 'the tests may not have run on what the digest says');
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
        self::assertNull($inputs->stampFor('tests/ATest.php'));
    }

    // -- 0.13: precise migrations, schema dumps, new sibling subdirectories ----------------

    public function test_a_pending_migration_is_an_input_of_every_database_test_only(): void
    {
        $this->repo->write('tests/CTest.php', "<?php\nfinal class CTest {}\n");
        $this->repo->write('database/migrations/2024_create_users.php', "<?php\nSchema::create('users', function () {});\n");
        $this->graph->replaceTestTables(['tests/ATest.php' => ['orders']]);
        $this->graph->replaceUsesDatabase(['tests/CTest.php'], ['tests/CTest.php']);
        $rules = ['migration' => new MigrationRule()];

        $before = $this->digests($rules);
        $this->repo->write('database/migrations/2024_create_users.php', "<?php\nSchema::create('users', function () { \$x = 1; });\n");
        $after = $this->digests($rules);

        self::assertNotSame($before['tests/ATest.php'], $after['tests/ATest.php'], 'records a table it does not name: still runs it');
        self::assertNotSame($before['tests/CTest.php'], $after['tests/CTest.php'], 'uses a database, records no table');
        self::assertSame($before['tests/BTest.php'], $after['tests/BTest.php'], 'no database');
    }

    public function test_a_squashed_migration_is_nobody_s_input_until_the_dump_stops_listing_it(): void
    {
        $this->repo->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
        $this->repo->write('database/schema/sqlite-schema.sql', self::SQLITE_DUMP);
        $this->repo->write('database/migrations/2024_01_01_000000_create_users_table.php', "<?php\nSchema::create('users', function () {});\n");
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users']]);
        $rules = ['migration' => new MigrationRule()];

        $before = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $this->repo->write('database/migrations/2024_01_01_000000_create_users_table.php', "<?php\nSchema::create('users', function () { \$x = 1; });\n");
        self::assertSame($before, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'), 'squashed: no test database runs it');

        $this->repo->write('database/schema/sqlite-schema.sql', str_replace("INSERT INTO migrations VALUES(1,'2024_01_01_000000_create_users_table',1);\n", '', self::SQLITE_DUMP));
        self::assertNotSame($before, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'), 'pending again');
    }

    public function test_by_default_the_whole_normalised_dump_is_every_database_test_s_input(): void
    {
        $this->repo->write('tests/CTest.php', "<?php\nfinal class CTest {}\n");
        $this->repo->write('tests/DTest.php', "<?php\nfinal class DTest {}\n");
        $this->repo->write('database/schema/sqlite-schema.sql', self::SQLITE_DUMP);
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users'], 'tests/BTest.php' => ['posts']]);
        $this->graph->replaceUsesDatabase(['tests/CTest.php'], ['tests/CTest.php']);
        $rules = ['schema' => new SchemaDumpRule()];

        $before = $this->digests($rules);
        $this->repo->write('database/schema/sqlite-schema.sql', "-- again\n" . str_replace('"title" varchar', '"title"    varchar', self::SQLITE_DUMP));
        self::assertSame($before, $this->digests($rules), 'comments and whitespace only');

        $this->repo->write('database/schema/sqlite-schema.sql', str_replace('"title" varchar', '"title" varchar, "body" text', self::SQLITE_DUMP));
        $after = $this->digests($rules);

        foreach (['tests/ATest.php', 'tests/BTest.php', 'tests/CTest.php'] as $file) {
            self::assertNotSame($before[$file], $after[$file], $file . ' uses a database');
        }

        self::assertSame($before['tests/DTest.php'], $after['tests/DTest.php'], 'no database');
    }

    public function test_per_table_a_block_is_an_input_of_the_tests_using_its_table_and_of_database_tests_with_none(): void
    {
        $this->repo->write('tests/CTest.php', "<?php\nfinal class CTest {}\n");
        $this->repo->write('tests/DTest.php', "<?php\nfinal class DTest {}\n");
        $this->repo->write('database/schema/sqlite-schema.sql', self::SQLITE_DUMP);
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users'], 'tests/BTest.php' => ['posts']]);
        $this->graph->replaceUsesDatabase(['tests/CTest.php'], ['tests/CTest.php']);
        $rules = ['schema' => new SchemaDumpRule('per-table')];

        $before = $this->digests($rules);
        $this->repo->write('database/schema/sqlite-schema.sql', str_replace('"title" varchar', '"title" varchar, "body" text', self::SQLITE_DUMP));
        $after = $this->digests($rules);

        self::assertSame($before['tests/ATest.php'], $after['tests/ATest.php'], 'users did not change');
        self::assertNotSame($before['tests/BTest.php'], $after['tests/BTest.php'], 'posts did');
        self::assertNotSame($before['tests/CTest.php'], $after['tests/CTest.php'], 'a database test with no recorded table');
        self::assertSame($before['tests/DTest.php'], $after['tests/DTest.php'], 'no database');

        // The migration rows: which migrations run changed, every database test's.
        $before = $after;
        $rows = str_replace('"title" varchar', '"title" varchar, "body" text', self::SQLITE_DUMP) . "INSERT INTO migrations VALUES(3,'2024_03_01_000000_x',2);\n";
        $this->repo->write('database/schema/sqlite-schema.sql', $rows);
        $after = $this->digests($rules);

        foreach (['tests/ATest.php', 'tests/BTest.php', 'tests/CTest.php'] as $file) {
            self::assertNotSame($before[$file], $after[$file], $file);
        }

        // A table no test records: only the database tests whose tables are not all known.
        $before = $after;
        $this->repo->write('database/schema/sqlite-schema.sql', $rows . "CREATE TABLE IF NOT EXISTS \"tags\"(\"id\" integer);\n");
        $after = $this->digests($rules);
        self::assertSame($before['tests/ATest.php'], $after['tests/ATest.php']);
        self::assertSame($before['tests/BTest.php'], $after['tests/BTest.php']);
        self::assertNotSame($before['tests/CTest.php'], $after['tests/CTest.php']);
    }

    public function test_per_table_a_digest_never_moves_for_what_another_test_records(): void
    {
        // A test recording a table for the first time moved every database test's digest,
        // with no file changed.
        $this->repo->write('tests/CTest.php', "<?php\nfinal class CTest {}\n");
        $this->repo->write('database/schema/sqlite-schema.sql', self::SQLITE_DUMP . "CREATE TABLE IF NOT EXISTS \"tags\"(\"id\" integer);\n");
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users'], 'tests/BTest.php' => ['posts']]);
        $rules = ['schema' => new SchemaDumpRule('per-table')];

        $before = $this->digests($rules);
        $this->graph->unionTestTables(['tests/CTest.php' => ['tags']]);
        $after = $this->digests($rules);

        self::assertSame($before['tests/ATest.php'], $after['tests/ATest.php']);
        self::assertSame($before['tests/BTest.php'], $after['tests/BTest.php']);
    }

    public function test_per_table_unknown_tables_follow_the_latest_recording(): void
    {
        $this->repo->write('database/schema/sqlite-schema.sql', self::SQLITE_DUMP);
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users']]);
        $this->graph->replaceTablesUnknown(['tests/ATest.php'], ['tests/ATest.php']);
        $rules = ['schema' => new SchemaDumpRule('per-table')];

        $before = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $this->repo->write('database/schema/sqlite-schema.sql', str_replace('"title" varchar', '"title" varchar, "body" text', self::SQLITE_DUMP));
        self::assertNotSame($before, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'), 'unknown: every block');

        // The next recording names every table: posts is no longer A's.
        $this->graph->replaceTablesUnknown(['tests/ATest.php'], []);
        $before = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $this->repo->write('database/schema/sqlite-schema.sql', str_replace('"title" varchar', '"title" varchar, "lede" text', self::SQLITE_DUMP));
        self::assertSame($before, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'));
    }

    public function test_per_table_a_foreign_key_carries_the_tables_it_ties(): void
    {
        $dump = "CREATE TABLE IF NOT EXISTS \"users\"(\"id\" integer primary key);\nCREATE TABLE IF NOT EXISTS \"posts\"(\"id\" integer, \"user_id\" integer, foreign key(\"user_id\") references \"users\"(\"id\") on delete cascade);\nCREATE TABLE IF NOT EXISTS \"tags\"(\"id\" integer);\nINSERT INTO migrations VALUES(1,'x',1);\n";
        $this->repo->write('database/schema/sqlite-schema.sql', $dump);
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users'], 'tests/BTest.php' => ['tags']]);
        $rules = ['schema' => new SchemaDumpRule('per-table')];

        $before = $this->digests($rules);
        $this->repo->write('database/schema/sqlite-schema.sql', str_replace(' on delete cascade', '', $dump));
        $after = $this->digests($rules);

        self::assertNotSame($before['tests/ATest.php'], $after['tests/ATest.php'], 'deleting a user does something else now');
        self::assertSame($before['tests/BTest.php'], $after['tests/BTest.php']);
    }

    public function test_a_pending_migration_stays_in_the_scope_of_the_test_that_first_ran_it(): void
    {
        // The first loader has an edge to the migration file: squashing it (a row in the
        // dump) must still move its digest.
        $this->repo->write('phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
        $this->repo->write('database/schema/sqlite-schema.sql', self::SQLITE_DUMP);
        $this->repo->write('database/migrations/2024_02_01_000000_seed_admin.php', "<?php\nDB::table('users')->insert([]);\n");
        $this->graph->unionEdges(['tests/ATest.php' => ['database/migrations/2024_02_01_000000_seed_admin.php']]);
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users']]);
        $rules = ['migration' => new MigrationRule()];

        $before = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $this->repo->write('database/schema/sqlite-schema.sql', self::SQLITE_DUMP . "INSERT INTO migrations VALUES(3,'2024_02_01_000000_seed_admin',2);\n");

        self::assertNotSame($before, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'));
    }

    public function test_a_schema_dump_that_does_not_parse_is_every_database_test_s_input_whole(): void
    {
        $this->repo->write('database/schema/sqlite-schema.sql', "CREATE TABLE \"users\" (\"x\" varchar DEFAULT 'oops);\n");
        $this->graph->replaceTestTables(['tests/ATest.php' => ['users']]);
        $rules = ['schema' => new SchemaDumpRule()];

        $before = $this->digests($rules);
        $this->repo->write('database/schema/sqlite-schema.sql', "CREATE TABLE \"users\" (\"x\" varchar DEFAULT 'still oops);\n");
        $after = $this->digests($rules);

        self::assertNotSame($before['tests/ATest.php'], $after['tests/ATest.php']);
        self::assertSame($before['tests/BTest.php'], $after['tests/BTest.php']);
    }

    public function test_a_new_file_in_a_new_subdirectory_is_an_input_of_the_tests_under_the_nearest_ancestor_with_edges(): void
    {
        $this->repo->write('app/Console/Commands/PruneUsers.php', "<?php\nfinal class PruneUsers {}\n");
        $this->graph->unionEdges(['tests/ATest.php' => ['app/Console/Commands/PruneUsers.php']]);
        $rules = ['sibling' => new SiblingRule()];

        $a = $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php');
        $b = $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php');

        $this->repo->write('app/Console/Commands/Reports/SendReport.php', "<?php\nfinal class SendReport {}\n");

        self::assertNotSame($a, $this->inputs(extraRules: $rules)->digestFor('tests/ATest.php'));
        self::assertSame($b, $this->inputs(extraRules: $rules)->digestFor('tests/BTest.php'));
    }

    public function test_covers_every_migration_path_and_the_schema_dumps(): void
    {
        $inputs = $this->inputs(extraRules: [
            'migration' => new MigrationRule(MigrationPaths::of(['database/migrations', 'database/tenant'])),
            'schema' => new SchemaDumpRule(),
        ]);

        self::assertTrue($inputs->covers('database/tenant/2024_x.php'));
        self::assertTrue($inputs->covers('database/tenant/seed.sql'));
        self::assertTrue($inputs->covers('database/schema/mysql-schema.sql'));
        self::assertFalse($inputs->covers('database/schema/notes.sql'));
    }

    private const SQLITE_DUMP = <<<'SQL'
        CREATE TABLE IF NOT EXISTS "users"("id" integer primary key, "email" varchar);
        CREATE TABLE IF NOT EXISTS "posts"("id" integer primary key, "title" varchar);
        INSERT INTO migrations VALUES(1,'2024_01_01_000000_create_users_table',1);
        INSERT INTO migrations VALUES(2,'2024_01_02_000000_create_posts_table',1);

        SQL;

    /**
     * @param array{migration?: MigrationRule, schema?: SchemaDumpRule, sibling?: SiblingRule, blade?: BladeRule} $extraRules
     * @return array<string, ?string>
     */
    private function digests(array $extraRules): array
    {
        $inputs = $this->inputs(extraRules: $extraRules);
        $out = [];

        foreach (['tests/ATest.php', 'tests/BTest.php', 'tests/CTest.php', 'tests/DTest.php'] as $file) {
            $out[$file] = $inputs->digestFor($file);
        }

        return $out;
    }

    private static function conservative(): MigrationRule
    {
        return new MigrationRule(MigrationPaths::default(), 'conservative');
    }

    /**
     * @param array{migration?: MigrationRule, schema?: SchemaDumpRule, sibling?: SiblingRule, blade?: BladeRule} $extraRules
     * @param array<string, list<string>> $watch
     * @param array<string, list<string>> $fallback
     */
    private function inputs(
        bool $staticDeclarationEdges = false,
        array $extraRules = [],
        array $watch = [],
        ?string $stateDir = null,
        ?SourceScope $scope = null,
        ?FileHashes $hashes = null,
        array $fallback = [],
    ): NonEdgeInputs {
        $patterns = new WatchPatterns();
        $patterns->add(['tests/**/Fixtures/**' => ['tests'], ...$watch]);
        $patterns->addFallback($fallback);

        return new NonEdgeInputs(
            $this->graph,
            new TestPaths(['tests'], [], ['Test.php']),
            $patterns,
            $this->repo->root,
            $hashes ?? new FileHashes($this->repo->root),
            new Git($this->repo->root),
            $extraRules,
            $staticDeclarationEdges,
            $stateDir,
            $scope,
        );
    }
}
