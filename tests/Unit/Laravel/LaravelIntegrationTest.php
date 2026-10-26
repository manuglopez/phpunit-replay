<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\LaravelIntegration;
use Manuglopez\Replay\Laravel\Rules\BladeRule;
use Manuglopez\Replay\Laravel\Rules\MigrationRule;
use Manuglopez\Replay\Laravel\Rules\SchemaDumpRule;
use Manuglopez\Replay\Laravel\Rules\SiblingRule;
use Manuglopez\Replay\Laravel\Subscribers\ArmLaravelTrackersOnPreparationStarted;
use Manuglopez\Replay\Laravel\Subscribers\ArmLaravelTrackersOnPrepared;
use Manuglopez\Replay\Laravel\Subscribers\FlushUsesDatabaseOnExecutionFinished;
use Manuglopez\Replay\PHPUnit\Mode;
use Manuglopez\Replay\PHPUnit\ReplayState;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Tests\Support\TempDir;
use Manuglopez\Replay\Tests\Unit\Record\FakeCoverageDriver;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class LaravelIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('laravel-integration');
    }

    #[After]
    public function cleanUp(): void
    {
        TempDir::remove($this->root);
        ReplayState::reset();
    }

    public function test_rules_returns_migration_schema_sibling_and_blade_in_that_order(): void
    {
        $rules = LaravelIntegration::rules(new Graph($this->root), $this->root);

        self::assertSame(['migration', 'schema', 'sibling', 'blade'], array_keys($rules));
        self::assertInstanceOf(MigrationRule::class, $rules['migration']);
        self::assertInstanceOf(SchemaDumpRule::class, $rules['schema']);
        self::assertInstanceOf(SiblingRule::class, $rules['sibling']);
        self::assertInstanceOf(BladeRule::class, $rules['blade']);
    }

    /**
     * `shouldArm()` is the one entry point still allowed a `class_exists()` check
     * (docs/INTERNALS.md "Laravel", `LaravelDetector`'s docblock): it gates the runtime
     * trackers, which only ever run inside the already-booted PHPUnit process.
     */
    #[RunInSeparateProcess]
    public function test_should_arm_requires_both_illuminate_and_an_artisan_file(): void
    {
        require_once __DIR__ . '/Fixtures/IlluminateContainerStub.php';

        self::assertFalse(LaravelIntegration::shouldArm($this->root));

        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');

        self::assertTrue(LaravelIntegration::shouldArm($this->root));
    }

    public function test_subscribers_returns_the_arming_and_flushing_subscribers(): void
    {
        ReplayState::boot(Mode::Record, $this->root, $this->root . '/.phpunit-replay', 'run-1', new FakeCoverageDriver([]));

        $subscribers = LaravelIntegration::subscribers(new Recorder(new FakeCoverageDriver([])), $this->root);

        self::assertCount(3, $subscribers);
        self::assertInstanceOf(ArmLaravelTrackersOnPrepared::class, $subscribers[0]);
        self::assertInstanceOf(FlushUsesDatabaseOnExecutionFinished::class, $subscribers[1]);
        self::assertInstanceOf(ArmLaravelTrackersOnPreparationStarted::class, $subscribers[2], 'arms inside setUp(), for the tables it queries');
    }

    /**
     * @return iterable<string, array{string|null, bool, bool}> config file body, artisan present, tracked
     */
    public static function translationTrackingProvider(): iterable
    {
        yield 'default (per-key) on a Laravel project' => ["<?php\nreturn [];\n", true, true];
        yield 'explicit per-key' => ["<?php\nreturn ['translations' => 'per-key'];\n", true, true];
        yield 'conservative arms nothing' => ["<?php\nreturn ['translations' => 'conservative'];\n", true, false];
        yield 'laravel off arms nothing' => ["<?php\nreturn ['laravel' => 'off'];\n", true, false];
        yield 'not a Laravel project (no artisan)' => ["<?php\nreturn [];\n", false, false];
        yield 'no config file at all' => [null, true, true];
    }

    #[DataProvider('translationTrackingProvider')]
    #[Group('AC-I1-d')]
    public function test_translation_tracking_follows_the_config_and_the_laravel_detection(?string $config, bool $artisan, bool $tracked): void
    {
        if ($config !== null) {
            TempDir::write($this->root . '/phpunit-replay.php', $config);
        }

        if ($artisan) {
            TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');
        }

        ReplayState::boot(Mode::Record, $this->root, $this->root . '/.phpunit-replay', 'run-1', new FakeCoverageDriver([]));
        $recorder = new Recorder(new FakeCoverageDriver([]));

        $wrapper = LaravelIntegration::subscribers($recorder, $this->root)[0];
        $inProcess = LaravelIntegration::inProcessSubscribers($recorder, $this->root)[0];

        self::assertInstanceOf(ArmLaravelTrackersOnPrepared::class, $wrapper);
        self::assertInstanceOf(ArmLaravelTrackersOnPrepared::class, $inProcess);
        self::assertSame($tracked, $wrapper->tracksTranslations(), 'the wrapper child');
        self::assertSame($tracked, $inProcess->tracksTranslations(), 'the in-process extension');
    }

    public function test_augment_returns_the_partial_unchanged_when_no_test_uses_the_database(): void
    {
        $partial = new RunPartial(edges: [], results: [], tables: ['tests/HomeTest.php' => []], meta: []);

        $augmented = LaravelIntegration::augment($partial, $this->root, self::conservative());

        self::assertSame($partial, $augmented);
    }

    public function test_augment_returns_the_partial_unchanged_when_there_are_no_migrations(): void
    {
        $partial = new RunPartial(
            edges: [],
            results: [],
            tables: [],
            meta: [],
            usesDatabase: ['tests/PostsTest.php'],
        );

        $augmented = LaravelIntegration::augment($partial, $this->root, self::conservative());

        self::assertSame($partial, $augmented);
    }

    public function test_augment_widens_database_test_tables_with_every_migration_table(): void
    {
        TempDir::write(
            $this->root . '/database/migrations/2024_01_01_000000_create_posts_table.php',
            "<?php\nSchema::create('posts', function (\$table) {});\n",
        );
        TempDir::write(
            $this->root . '/database/migrations/2024_01_02_000000_create_comments_table.php',
            "<?php\nSchema::create('comments', function (\$table) {});\n",
        );

        $partial = new RunPartial(
            edges: [],
            results: [],
            tables: [
                'tests/PostsTest.php' => ['posts'],
                'tests/UnrelatedTest.php' => ['other'],
            ],
            meta: ['driver' => 'pcov'],
            usesDatabase: ['tests/PostsTest.php', 'tests/UsersTest.php'],
        );

        $augmented = LaravelIntegration::augment($partial, $this->root, self::conservative());

        self::assertSame(['comments', 'posts'], $augmented->tables['tests/PostsTest.php']);
        self::assertSame(['comments', 'posts'], $augmented->tables['tests/UsersTest.php']);
        self::assertSame(['other'], $augmented->tables['tests/UnrelatedTest.php']);

        // Everything else on the partial is preserved.
        self::assertSame($partial->edges, $augmented->edges);
        self::assertSame($partial->results, $augmented->results);
        self::assertSame($partial->meta, $augmented->meta);
        self::assertSame($partial->usesDatabase, $augmented->usesDatabase);
    }

    /**
     * Regression test: an earlier version of `augment()` rebuilt the `RunPartial` with
     * positional arguments and silently dropped `notCacheable` (it defaults to `[]`),
     * which would un-quarantine every `#[NotCacheable]`/`never_cache` test file on a
     * Laravel project the moment it also had a database test to widen.
     */
    public function test_augment_preserves_not_cacheable_entries(): void
    {
        TempDir::write(
            $this->root . '/database/migrations/2024_01_01_000000_create_posts_table.php',
            "<?php\nSchema::create('posts', function (\$table) {});\n",
        );

        $partial = new RunPartial(
            edges: [],
            results: [],
            tables: ['tests/PostsTest.php' => ['posts']],
            meta: [],
            usesDatabase: ['tests/PostsTest.php'],
            notCacheable: ['tests/ExternalTest.php', 'App\Tests\FlakyTest::testX'],
        );

        $augmented = LaravelIntegration::augment($partial, $this->root, self::conservative());

        self::assertSame($partial->notCacheable, $augmented->notCacheable);
    }

    public function test_by_default_augment_leaves_the_recorded_tables_raw(): void
    {
        // The union made every database test record every migration table, so a migration
        // selected all of them whatever it touched (precise mode keeps what each recorded).
        TempDir::write(
            $this->root . '/database/migrations/2024_01_01_000000_create_posts_table.php',
            "<?php\nSchema::create('posts', function (\$table) {});\n",
        );
        TempDir::write(
            $this->root . '/database/migrations/2024_01_02_000000_create_comments_table.php',
            "<?php\nSchema::create('comments', function (\$table) {});\n",
        );
        $partial = new RunPartial(
            edges: [],
            results: [],
            tables: ['tests/PostsTest.php' => ['posts']],
            meta: [],
            usesDatabase: ['tests/PostsTest.php', 'tests/UsersTest.php'],
        );

        $augmented = LaravelIntegration::augment($partial, $this->root, Config::defaults());

        self::assertSame(['tests/PostsTest.php' => ['posts']], $augmented->tables);
        self::assertSame($partial->usesDatabase, $augmented->usesDatabase);
    }

    public function test_the_migration_rule_knows_the_configured_paths_and_mode(): void
    {
        $config = Config::defaults()->with(['migrationPaths' => ['database/tenant'], 'migrations' => 'conservative']);
        $rule = LaravelIntegration::rules(new Graph($this->root), $this->root, null, $config)['migration'];

        self::assertInstanceOf(MigrationRule::class, $rule);
        self::assertSame(['database/migrations', 'database/tenant'], $rule->paths()->paths());
        self::assertTrue($rule->conservative());
    }

    private static function conservative(): Config
    {
        return Config::defaults()->with(['migrations' => 'conservative']);
    }
}
