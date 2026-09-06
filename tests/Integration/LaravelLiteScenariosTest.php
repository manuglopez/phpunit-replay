<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end Laravel wiring, phase 2 acceptance gate (SPEC.md phase-2 gate,
 * docs/INTERNALS.md "Laravel"): drives the real `bin/phpunit-replay` wrapper against a
 * `laravel-lite` copy and shows that changing a migration only re-runs the tests that
 * touch that table, and that changing a Blade view only re-runs the tests that render
 * it. Skipped entirely when tests/Fixtures/Projects/laravel-lite/vendor was never
 * installed (see its README.md).
 *
 * Every test method gets its own fresh fixture with its own recorded baseline
 * ({@see self::setUp()}), so each scenario only ever has to reason about a single edit
 * against a clean baseline — no reverts, no interaction with `LastRunTree` between
 * scenarios.
 */
final class LaravelLiteScenariosTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FixtureProject::laravelLiteAvailable()) {
            self::markTestSkipped(
                'tests/Fixtures/Projects/laravel-lite/vendor is not installed — see its README.md.',
            );
        }

        $this->fixture = FixtureProject::laravelLite();

        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();

        parent::tearDown();
    }

    /**
     * (a) record: 4 test files known, PostsIndexTest widened to every migration table
     * (RefreshDatabase, SPEC.md §10 MigrationTables), HomePageTest untouched by that
     * widening (it never touches the database), and HomePageTest has a PhpEdge into the
     * Blade view it renders (BladeTracker). `status` reports the framework and the
     * distinct table count (deliverable 5).
     */
    public function test_record_captures_migration_tables_and_blade_edges(): void
    {
        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        self::assertTrue($graph->knowsTest('tests/Feature/HomePageTest.php'));
        self::assertTrue($graph->knowsTest('tests/Feature/PostJsonTest.php'));
        self::assertTrue($graph->knowsTest('tests/Feature/PostsIndexTest.php'));
        self::assertTrue($graph->knowsTest('tests/Feature/UserModelTest.php'));

        $tables = $graph->testTables();
        self::assertArrayHasKey('tests/Feature/PostsIndexTest.php', $tables);
        self::assertContains('posts', $tables['tests/Feature/PostsIndexTest.php']);
        self::assertContains('users', $tables['tests/Feature/PostsIndexTest.php']);
        self::assertContains('comments', $tables['tests/Feature/PostsIndexTest.php']);
        self::assertArrayNotHasKey('tests/Feature/HomePageTest.php', $tables);

        self::assertContains('resources/views/welcome.blade.php', $graph->dependenciesOf('tests/Feature/HomePageTest.php'));

        $status = $this->fixture->replay(['status']);
        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertStringContainsString('framework: laravel', $status['stdout']);
        self::assertMatchesRegularExpression('/tables:\s+3\b/', $status['stdout']);
    }

    /** (b) an unchanged run replays everything. */
    public function test_an_unchanged_run_executes_nothing(): void
    {
        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($result['stdout']));
        self::assertSame(4, ReplayAssert::replayedCount($result['stdout']));
    }

    /**
     * (c) a real migration change (a new nullable column, so `TableExtractor` still finds
     * `comments` — this is not a cosmetic-only edit) re-runs exactly the 3 test files
     * whose widened tables include `comments`: every RefreshDatabase test file, because
     * MigrationTables treats any migration as possibly affecting any database test
     * (conservative, SPEC.md §10). HomePageTest, which never touches the database,
     * replays instead. Verified via `--explain --dry-run` and the real run's own counts,
     * plus `explain <path>` directly (deliverable 5).
     */
    public function test_a_migration_change_reruns_the_database_test_files(): void
    {
        $migration = 'database/migrations/2024_01_03_000000_create_comments_table.php';
        $original = $this->fixture->read($migration);
        $this->fixture->write($migration, str_replace(
            "\$table->text('body');",
            "\$table->text('body');\n            \$table->string('edited_reason')->nullable();",
            $original,
        ));
        self::assertNotSame($original, $this->fixture->read($migration));

        $explain = $this->fixture->replay(['--explain', '--dry-run']);
        self::assertSame(0, $explain['exitCode'], $explain['stdout'] . $explain['stderr']);

        $lines = self::explainLines($explain['stdout']);
        self::assertSame(
            [
                'tests/Feature/PostJsonTest.php',
                'tests/Feature/PostsIndexTest.php',
                'tests/Feature/UserModelTest.php',
            ],
            array_keys($lines),
        );

        foreach ($lines as $line) {
            self::assertStringContainsString('Migration', $line);
            self::assertStringContainsString('comments', $line);
        }

        self::assertStringContainsString(
            '3 test files would run (3 affected, 0 uncached, 0 quarantined), 1 tests would replay',
            $explain['stdout'],
        );

        $direct = $this->fixture->replay(['explain', $migration]);
        self::assertSame(0, $direct['exitCode'], $direct['stdout'] . $direct['stderr']);
        $directLines = self::explainLines($direct['stdout']);
        self::assertSame(array_keys($lines), array_keys($directLines));

        foreach ($directLines as $line) {
            self::assertStringContainsString('Migration', $line);
            self::assertStringContainsString('comments', $line);
        }

        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(3, ReplayAssert::executedCount($result['stdout']));
        self::assertSame(3, ReplayAssert::affectedCount($result['stdout']));
        self::assertSame(0, ReplayAssert::uncachedCount($result['stdout']));
        self::assertSame(1, ReplayAssert::replayedCount($result['stdout']));
        self::assertStringNotContainsString('HomePageTest', $result['stdout']);
    }

    /**
     * (d) a Blade view change re-runs only the test that renders it (PhpEdge — the view
     * was already recorded as an edge through BladeTracker); everything else replays.
     */
    public function test_a_blade_view_change_reruns_only_its_test(): void
    {
        $view = 'resources/views/welcome.blade.php';
        $this->fixture->write($view, str_replace('<h1>Welcome</h1>', '<h1>Welcome aboard</h1>', $this->fixture->read($view)));

        $explain = $this->fixture->replay(['--explain', '--dry-run']);
        self::assertSame(0, $explain['exitCode'], $explain['stdout'] . $explain['stderr']);
        self::assertStringContainsString('tests/Feature/HomePageTest.php', $explain['stdout']);
        self::assertStringContainsString('PhpEdge', $explain['stdout']);
        self::assertStringContainsString(
            '1 test files would run (1 affected, 0 uncached, 0 quarantined), 3 tests would replay',
            $explain['stdout'],
        );

        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(1, ReplayAssert::executedCount($result['stdout']));
        self::assertSame(1, ReplayAssert::affectedCount($result['stdout']));
        self::assertSame(3, ReplayAssert::replayedCount($result['stdout']));
        self::assertStringNotContainsString('PostsIndexTest', $result['stdout']);
        self::assertStringNotContainsString('UserModelTest', $result['stdout']);
        self::assertStringNotContainsString('PostJsonTest', $result['stdout']);
    }

    /**
     * (e.1) a brand new partial `@include`d from an already-known view: the known view's
     * own change is what selects the test (PhpEdge on `posts/index.blade.php`), not
     * BladeRule — the new partial itself is not referenced by anything yet.
     */
    public function test_a_new_blade_partial_included_from_a_known_view_reruns_via_php_edge(): void
    {
        $this->fixture->write(
            'resources/views/posts/_footer.blade.php',
            "<p>{{ count(\$posts ?? []) }} posts</p>\n",
        );

        $index = 'resources/views/posts/index.blade.php';
        $this->fixture->write($index, str_replace(
            "    </ul>\n",
            "    </ul>\n    @include('posts._footer')\n",
            $this->fixture->read($index),
        ));

        $explain = $this->fixture->replay(['--explain', '--dry-run']);
        self::assertSame(0, $explain['exitCode'], $explain['stdout'] . $explain['stderr']);
        self::assertStringContainsString('tests/Feature/PostsIndexTest.php', $explain['stdout']);
        self::assertStringContainsString('PhpEdge', $explain['stdout']);

        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(1, ReplayAssert::executedCount($result['stdout']));
        self::assertSame(1, ReplayAssert::affectedCount($result['stdout']));
        self::assertStringNotContainsString('HomePageTest', $result['stdout']);
    }

    /**
     * (e.2) editing an already-included partial (`_item.blade.php`, included by
     * `posts/index.blade.php` since the recording pass): the edge already exists, so
     * PhpEdgeRule selects PostsIndexTest directly and nothing else.
     */
    public function test_editing_an_already_included_partial_reruns_only_its_parent(): void
    {
        $partial = 'resources/views/posts/_item.blade.php';
        $this->fixture->write($partial, str_replace(
            '{{ $post->title }}',
            '{{ $post->title }} (edited)',
            $this->fixture->read($partial),
        ));

        $explain = $this->fixture->replay(['--explain', '--dry-run']);
        self::assertSame(0, $explain['exitCode'], $explain['stdout'] . $explain['stderr']);
        self::assertStringContainsString('tests/Feature/PostsIndexTest.php', $explain['stdout']);
        self::assertStringContainsString('PhpEdge', $explain['stdout']);
        self::assertStringContainsString(
            '1 test files would run (1 affected, 0 uncached, 0 quarantined), 3 tests would replay',
            $explain['stdout'],
        );

        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(1, ReplayAssert::executedCount($result['stdout']));
        self::assertSame(1, ReplayAssert::affectedCount($result['stdout']));
        self::assertStringNotContainsString('HomePageTest', $result['stdout']);
        self::assertStringNotContainsString('UserModelTest', $result['stdout']);
        self::assertStringNotContainsString('PostJsonTest', $result['stdout']);
    }

    /**
     * (f) a single edit both creates an unknown Blade partial AND references it from an
     * already-known view: the known view's own PhpEdge selection fires regardless, and
     * BladeRule's static ancestor walk (from the new, unknown partial back up to the
     * known view) reaches the same test file too — either rule selecting HomePageTest is
     * correct (ExplainFormatter prints only the first reason per file, SPEC.md §11).
     */
    public function test_an_unknown_blade_referenced_by_a_known_view_reruns_via_php_edge_or_blade(): void
    {
        $this->fixture->write('resources/views/partials/new.blade.php', "<p>New partial</p>\n");

        $view = 'resources/views/welcome.blade.php';
        $this->fixture->write($view, str_replace(
            '<h1>Welcome</h1>',
            "<h1>Welcome</h1>\n    @include('partials.new')",
            $this->fixture->read($view),
        ));

        $explain = $this->fixture->replay(['--explain', '--dry-run']);
        self::assertSame(0, $explain['exitCode'], $explain['stdout'] . $explain['stderr']);
        self::assertMatchesRegularExpression(
            '/tests\/Feature\/HomePageTest\.php\s+← (PhpEdge|Blade)\b/',
            $explain['stdout'],
        );

        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(1, ReplayAssert::executedCount($result['stdout']));
        self::assertSame(1, ReplayAssert::affectedCount($result['stdout']));
    }

    /**
     * (g) a brand new source file in a directory with no existing test edges (an empty
     * `app/Listeners/`, so SiblingRule has no sibling to point at) affects nothing —
     * deliberate, SPEC.md §7.2.7 — and `explain` says so explicitly.
     */
    public function test_a_new_unrelated_source_file_affects_nothing(): void
    {
        $listener = 'app/Listeners/SendNotification.php';
        $this->fixture->write($listener, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Listeners;

            final class SendNotification
            {
                public function handle(): void
                {
                    //
                }
            }
            PHP);

        $explain = $this->fixture->replay(['--explain', '--dry-run']);
        self::assertSame(0, $explain['exitCode'], $explain['stdout'] . $explain['stderr']);
        self::assertStringContainsString(
            '0 test files would run (0 affected, 0 uncached, 0 quarantined), 4 tests would replay',
            $explain['stdout'],
        );

        $direct = $this->fixture->replay(['explain', $listener]);
        self::assertSame(0, $direct['exitCode'], $direct['stdout'] . $direct['stderr']);
        self::assertStringContainsString('no recorded test executes this file', $direct['stdout']);

        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($result['stdout']));
        self::assertSame(4, ReplayAssert::replayedCount($result['stdout']));
    }

    /** @return array<string, string> test file => its `--explain` line, insertion order */
    private static function explainLines(string $stdout): array
    {
        $lines = [];

        foreach (explode("\n", rtrim($stdout)) as $line) {
            if (preg_match('/^(\S+)\s+←/', $line, $m) === 1) {
                $lines[$m[1]] = $line;
            }
        }

        return $lines;
    }
}
