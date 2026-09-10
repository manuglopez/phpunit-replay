<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\ContentHash;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\Remote\FilesystemRemoteCache;
use Manuglopez\Replay\Cache\Remote\NullRemoteCache;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Console\Commands\PruneCommand;
use Manuglopez\Replay\Record\CoverageSnapshots;
use Manuglopez\Replay\Tests\Support\GitRepo;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `phpunit-replay prune`'s local mirror + coverage snapshot collection
 * (docs/proposals/remote-layout.md §§3-6): addressability, not age, drives eviction, and it
 * runs on every local `prune` regardless of --flaky/--branches/--stale-edges — none of them
 * bear on which content keys this machine can still address.
 *
 * `PruneCommand` is exercised directly through Symfony's `CommandTester` against a real git
 * repository, with the graph/mirror/snapshot state built directly rather than recorded
 * through a live PHPUnit run — {@see PruneRemoteCommandTest}'s own rationale applies here
 * too: `PruneCommand::execute()` depends only on `getcwd()` and the project's
 * `phpunit-replay.php`, both fully controlled here, without the overhead of spawning a real
 * PHPUnit process for every case.
 */
final class PruneMirrorCommandTest extends TestCase
{
    private string $base;

    private GitRepo $repo;

    private string $projectRoot;

    private string $stateDir;

    private ?string $previousCwd;

    protected function setUp(): void
    {
        $this->base = TempDir::make('prune-mirror');
        $this->stateDir = $this->base . '/state';

        $this->repo = GitRepo::init($this->base . '/project');
        $this->projectRoot = $this->repo->root;

        $this->repo->write('tests/FooTest.php', "<?php\nfinal class FooMarker {}\n");
        $this->repo->commitAll('initial');

        TempDir::write($this->projectRoot . '/phpunit-replay.php', sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'state_dir' => %s,\n];\n",
            var_export($this->stateDir, true),
        ));

        // A second, non-current branch: git knows it, so the branch-pruning bundle that
        // already runs under a bare `prune` must not drop its baseline — the scenario this
        // file exists to cover (an object addressable only from a branch that is not the
        // one currently checked out).
        $this->repo->checkout('feature', true);
        $this->repo->checkout('main');

        $this->previousCwd = getcwd() ?: null;
        chdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== null) {
            chdir($this->previousCwd);
        }

        $this->repo->destroy();
        TempDir::remove($this->stateDir);
    }

    public function test_bare_prune_truncates_unreachable_mirror_objects_but_keeps_ones_reachable_from_any_branch(): void
    {
        $graph = new Graph($this->projectRoot);
        $graph->setDefaultBranch('main');
        $graph->markKnownTestFiles([$this->projectRoot . '/tests/FooTest.php']);

        $graph->setResult('main', 'Foo::test_main', $this->makeResult('reachable-main'));
        $graph->setRecordedSha('main', $this->repo->sha());
        $graph->markBaselineComplete('main');

        // Currently checked out on 'main', not 'feature' — this key must survive collection
        // purely because 'feature' is still a baseline this graph holds, not because it is
        // the branch prune is running on.
        $graph->setResult('feature', 'Foo::test_feature', $this->makeResult('reachable-feature'));
        $graph->setRecordedSha('feature', $this->repo->sha());
        $graph->markBaselineComplete('feature');

        (new GraphStore($this->stateDir, $this->projectRoot))->save($graph);

        $mirror = new ObjectStore(new NullRemoteCache(), $this->stateDir, 'unused');
        TempDir::write($mirror->mirrorPath('reachable-main'), str_repeat('A', 111));
        TempDir::write($mirror->mirrorPath('reachable-feature'), str_repeat('B', 222));
        TempDir::write($mirror->mirrorPath('dead-generation'), str_repeat('C', 333));

        $tester = new CommandTester(new PruneCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('mirror prune: truncated 1 unreachable object(s)', $tester->getDisplay());
        self::assertStringContainsString('kept 2 reachable object(s)', $tester->getDisplay());

        self::assertSame(111, filesize($mirror->mirrorPath('reachable-main')), "the current branch's own key must survive untouched");
        self::assertSame(222, filesize($mirror->mirrorPath('reachable-feature')), "a non-current branch's key must survive untouched too");
        self::assertFileExists($mirror->mirrorPath('dead-generation'), 'truncated, never unlinked, by default');
        self::assertSame(0, filesize($mirror->mirrorPath('dead-generation')), 'unreachable from every branch: truncated to free its blocks');
    }

    public function test_forget_published_unlinks_and_the_next_publish_is_not_skipped(): void
    {
        (new GraphStore($this->stateDir, $this->projectRoot))->save(new Graph($this->projectRoot));

        // A real (filesystem) backend, unlike the NullRemoteCache used elsewhere in this
        // file: the assertion below needs putObject()'s own `put()` call to be able to
        // succeed, so that "does it skip" is the only thing distinguishing the two branches.
        $backend = new FilesystemRemoteCache($this->base . '/remote');
        $backend->begin();
        $store = new ObjectStore($backend, $this->stateDir, 'unused');
        // Simulates what confirmPublished() leaves behind after a landed push: the marker
        // file, with no baseline anywhere addressing its key any more (e.g. the generation
        // it belonged to is gone).
        TempDir::write($store->mirrorPath('never-addressed-again'), '{"k":"never-addressed-again"}');

        $tester = new CommandTester(new PruneCommand());
        $tester->execute(['--forget-published' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('mirror prune: unlinked 1 unreachable object(s)', $tester->getDisplay());
        self::assertFileDoesNotExist($store->mirrorPath('never-addressed-again'));

        // No marker on disk any more: a later publish of the same key must not skip it.
        self::assertTrue($store->putObject('never-addressed-again', 'tests/FooTest.php', [
            'Foo::test_main' => $this->makeResult('never-addressed-again'),
        ]));
    }

    public function test_bare_prune_collects_unreachable_coverage_snapshots_but_keeps_ones_still_addressable(): void
    {
        $graph = new Graph($this->projectRoot);
        $graph->markKnownTestFiles([$this->projectRoot . '/tests/FooTest.php']);
        (new GraphStore($this->stateDir, $this->projectRoot))->save($graph);

        $liveKey = ContentHash::of($this->projectRoot . '/tests/FooTest.php');
        self::assertNotNull($liveKey);

        $snapshots = new CoverageSnapshots($this->stateDir);
        TempDir::write($snapshots->path($liveKey), 'still readable from tests/FooTest.php as it stands on disk');
        TempDir::write($snapshots->path('stale-hash-from-a-since-edited-file'), 'orphaned');

        $tester = new CommandTester(new PruneCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('coverage prune: unlinked 1 unreachable snapshot(s)', $tester->getDisplay());

        self::assertFileExists($snapshots->path($liveKey));
        self::assertFileDoesNotExist($snapshots->path('stale-hash-from-a-since-edited-file'));
    }

    /** @return array{status: int, message: string, time: float, assertions: int, file: string, key: string} */
    private function makeResult(string $key): array
    {
        return [
            'status' => 0,
            'message' => '',
            'time' => 0.1,
            'assertions' => 1,
            'file' => 'tests/FooTest.php',
            'key' => $key,
        ];
    }
}
