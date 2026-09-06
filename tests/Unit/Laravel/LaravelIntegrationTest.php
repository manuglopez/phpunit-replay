<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Laravel\LaravelIntegration;
use Manuglopez\Replay\PHPUnit\Mode;
use Manuglopez\Replay\PHPUnit\ReplayState;
use Manuglopez\Replay\PHPUnit\Subscribers\ArmLaravelTrackersOnPrepared;
use Manuglopez\Replay\PHPUnit\Subscribers\FlushUsesDatabaseOnExecutionFinished;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Select\Rules\BladeRule;
use Manuglopez\Replay\Select\Rules\MigrationRule;
use Manuglopez\Replay\Select\Rules\SiblingRule;
use Manuglopez\Replay\Tests\Support\TempDir;
use Manuglopez\Replay\Tests\Unit\Record\FakeCoverageDriver;
use PHPUnit\Framework\Attributes\After;
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

    public function test_rules_returns_migration_sibling_and_blade_in_that_order(): void
    {
        $rules = LaravelIntegration::rules(new Graph($this->root), $this->root);

        self::assertSame(['migration', 'sibling', 'blade'], array_keys($rules));
        self::assertInstanceOf(MigrationRule::class, $rules['migration']);
        self::assertInstanceOf(SiblingRule::class, $rules['sibling']);
        self::assertInstanceOf(BladeRule::class, $rules['blade']);
    }

    public function test_subscribers_returns_the_arming_and_flushing_subscribers(): void
    {
        ReplayState::boot(Mode::Record, $this->root, $this->root . '/.phpunit-replay', 'run-1', new FakeCoverageDriver([]));

        $subscribers = LaravelIntegration::subscribers(new Recorder(new FakeCoverageDriver([])));

        self::assertCount(2, $subscribers);
        self::assertInstanceOf(ArmLaravelTrackersOnPrepared::class, $subscribers[0]);
        self::assertInstanceOf(FlushUsesDatabaseOnExecutionFinished::class, $subscribers[1]);
    }

    public function test_augment_returns_the_partial_unchanged_when_no_test_uses_the_database(): void
    {
        $partial = new RunPartial(edges: [], results: [], tables: ['tests/HomeTest.php' => []], meta: []);

        $augmented = LaravelIntegration::augment($partial, $this->root);

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

        $augmented = LaravelIntegration::augment($partial, $this->root);

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

        $augmented = LaravelIntegration::augment($partial, $this->root);

        self::assertSame(['comments', 'posts'], $augmented->tables['tests/PostsTest.php']);
        self::assertSame(['comments', 'posts'], $augmented->tables['tests/UsersTest.php']);
        self::assertSame(['other'], $augmented->tables['tests/UnrelatedTest.php']);

        // Everything else on the partial is preserved.
        self::assertSame($partial->edges, $augmented->edges);
        self::assertSame($partial->results, $augmented->results);
        self::assertSame($partial->meta, $augmented->meta);
        self::assertSame($partial->usesDatabase, $augmented->usesDatabase);
    }
}
