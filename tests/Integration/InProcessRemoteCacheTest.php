<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\ProjectKey;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * In-process remote support (src/PHPUnit/ReplayState.php, SPEC.md §9): the same shared-cache
 * behaviour {@see TwoMachinesSharedCacheTest} exercises for the wrapper, driven here by two
 * real `vendor/bin/phpunit` runs (no wrapper — the `inprocess` fixture registers the
 * extension itself) inside the `inprocess` fixture, each with its own `$HOME` (and therefore
 * its own state directory) but sharing one `file://` remote.
 *
 * Mirrors only the first two steps of {@see TwoMachinesSharedCacheTest}: machine 1 records
 * the baseline and publishes it (`remote_push=all`); machine 2, starting with no local
 * graph at all, adopts that baseline from the remote and replays every test from it without
 * executing anything.
 */
final class InProcessRemoteCacheTest extends TestCase
{
    private const ORIGIN = 'https://example.invalid/acme/inprocess.git';

    private FixtureProject $machine1;

    private FixtureProject $machine2;

    private string $sharedCache;

    /** @var list<string> */
    private array $temporaries = [];

    protected function setUp(): void
    {
        $this->sharedCache = $this->tempDir('shared-cache');

        $this->machine1 = FixtureProject::inprocess($this->tempDir('machine1') . '/project');
        $this->machine1->repo->git('remote', 'add', 'origin', self::ORIGIN);

        $this->machine2 = $this->machine1->copyTo($this->tempDir('machine2') . '/project');

        self::assertSame(
            ProjectKey::for($this->machine1->root()),
            ProjectKey::for($this->machine2->root()),
            'two clones of the same repository must resolve to the same project key',
        );
    }

    protected function tearDown(): void
    {
        $this->machine1->destroy();
        $this->machine2->destroy();

        foreach ($this->temporaries as $dir) {
            TempDir::remove($dir);
        }

        parent::tearDown();
    }

    public function test_a_second_machine_replays_everything_in_process_from_the_shared_cache(): void
    {
        $env = $this->env();

        $recorded = $this->machine1->phpunitInProcess([], $env);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
        self::assertStringContainsString('Tests: 35, Assertions: 61', $recorded['stdout']);
        self::assertStringContainsString('Replay  ● recorded', $recorded['stdout']);

        // The whole baseline plus one object per test file is now on the shared cache.
        $projectKey = ProjectKey::for($this->machine1->root());
        self::assertFileExists($this->sharedCache . '/graph/' . $projectKey . '/main.json');
        self::assertNotSame([], $this->remoteObjects());

        // Machine 2 has never run anything: no local graph, no local objects. It adopts
        // the published baseline from the remote and replays every test from it — except
        // the one `#[Depends]` provider (DependsTest::testFirst), which the trait never
        // replays regardless of any cache (see Scenario10InProcessReplayTest).
        $second = $this->machine2->phpunitInProcess([], $env);

        self::assertSame(0, $second['exitCode'], $second['stdout'] . $second['stderr']);
        self::assertStringContainsString(
            '1 executed (0 affected, 1 uncached) · 34 replayed (34 from remote)',
            $second['stdout'],
        );
    }

    /** @return list<string> object keys currently on the shared cache */
    private function remoteObjects(): array
    {
        $found = glob($this->sharedCache . '/objects/*/*.json');

        return $found === false ? [] : array_values($found);
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return [
            'PHPUNIT_REPLAY_REMOTE' => 'file://' . $this->sharedCache,
            'PHPUNIT_REPLAY_REMOTE_PUSH' => 'all',
            // The package's own suite may itself run under CI, where a pass refuses to
            // publish a baseline; these fixtures are testing the developer path.
            'CI' => '',
        ];
    }

    private function tempDir(string $prefix): string
    {
        $dir = TempDir::make($prefix);
        $this->temporaries[] = $dir;

        return $dir;
    }
}
