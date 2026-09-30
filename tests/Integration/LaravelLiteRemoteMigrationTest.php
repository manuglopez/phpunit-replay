<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The `Migration` rule selects every database test file when a migration changes, and a
 * migration is not among the files those tests execute, so their content keys do not move
 * (SPEC.md §9; tests/Integration/RemoteReplayNonEdgeSelectionTest.php for the same hole
 * through a watch pattern). With the previous run's objects on the remote, the files the
 * rule selected must still execute.
 */
final class LaravelLiteRemoteMigrationTest extends TestCase
{
    private FixtureProject $fixture;

    private string $sharedCache;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FixtureProject::laravelLiteAvailable()) {
            self::markTestSkipped(
                'tests/Fixtures/Projects/laravel-lite/vendor is not installed — see its README.md.',
            );
        }

        $this->sharedCache = sys_get_temp_dir() . '/lite-shared-' . bin2hex(random_bytes(6));
        mkdir($this->sharedCache, 0777, true);

        $this->fixture = FixtureProject::laravelLite();
        $this->fixture->repo->git('remote', 'add', 'origin', 'https://example.invalid/acme/lite.git');
    }

    protected function tearDown(): void
    {
        // setUp() may skip before assigning either property.
        if (isset($this->fixture)) {
            $this->fixture->destroy();
            TempDir::remove($this->sharedCache);
        }

        parent::tearDown();
    }

    public function test_a_migration_change_executes_the_database_tests_even_when_their_objects_are_on_the_remote(): void
    {
        $env = [
            'PHPUNIT_REPLAY_REMOTE' => 'file://' . $this->sharedCache,
            'PHPUNIT_REPLAY_REMOTE_PUSH' => 'all',
            'CI' => '',
        ];

        $recorded = $this->fixture->replay(['record'], $env);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $migration = 'database/migrations/2024_01_03_000000_create_comments_table.php';
        $original = $this->fixture->read($migration);
        $this->fixture->write($migration, str_replace(
            "\$table->text('body');",
            "\$table->text('body');\n            \$table->string('edited_reason')->nullable();",
            $original,
        ));
        self::assertNotSame($original, $this->fixture->read($migration));

        $result = $this->fixture->replay([], $env);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(3, ReplayAssert::executedCount($result['stdout']), ReplayAssert::lastLine($result['stdout']));
        self::assertSame(1, ReplayAssert::replayedCount($result['stdout']), ReplayAssert::lastLine($result['stdout']));
    }
}
