<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\Remote\NullRemoteCache;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Console\Commands\StatusCommand;
use Manuglopez\Replay\Tests\Support\GitRepo;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `phpunit-replay status`'s `mirror:` line (docs/proposals/remote-layout.md §6): the same
 * addressability accounting `prune` collects by, reported here without changing anything on
 * disk. `StatusCommand` is exercised directly through `CommandTester` against a real git
 * repository, for the same reason as {@see PruneMirrorCommandTest}.
 */
final class StatusMirrorCommandTest extends TestCase
{
    private string $base;

    private GitRepo $repo;

    private string $projectRoot;

    private string $stateDir;

    private ?string $previousCwd;

    protected function setUp(): void
    {
        $this->base = TempDir::make('status-mirror');
        $this->stateDir = $this->base . '/state';

        $this->repo = GitRepo::init($this->base . '/project');
        $this->projectRoot = $this->repo->root;
        $this->repo->write('tests/FooTest.php', "<?php\nfinal class FooMarker {}\n");
        $this->repo->commitAll('initial');

        TempDir::write($this->projectRoot . '/phpunit-replay.php', sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'state_dir' => %s,\n];\n",
            var_export($this->stateDir, true),
        ));

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

    public function test_mirror_line_reports_zero_before_any_baseline_exists(): void
    {
        $tester = new CommandTester(new StatusCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('mirror:    0 objects · 0 reachable · 0 KB reclaimable', $tester->getDisplay());
    }

    public function test_mirror_line_counts_reachable_and_reclaimable_objects_without_changing_anything(): void
    {
        $graph = new Graph($this->projectRoot);
        $graph->setDefaultBranch('main');
        $graph->markKnownTestFiles([$this->projectRoot . '/tests/FooTest.php']);
        $graph->setResult('main', 'Foo::test_main', [
            'status' => 0,
            'message' => '',
            'time' => 0.1,
            'assertions' => 1,
            'file' => 'tests/FooTest.php',
            'key' => 'reachable-main',
        ]);
        $graph->setRecordedSha('main', $this->repo->sha());
        $graph->markBaselineComplete('main');
        (new GraphStore($this->stateDir, $this->projectRoot))->save($graph);

        $mirror = new ObjectStore(new NullRemoteCache(), $this->stateDir, 'unused');
        TempDir::write($mirror->mirrorPath('reachable-main'), str_repeat('A', 1024));
        TempDir::write($mirror->mirrorPath('dead-generation'), str_repeat('C', 2048));

        $tester = new CommandTester(new StatusCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('mirror:    2 objects · 1 reachable · 2 KB reclaimable', $tester->getDisplay());

        // status is read-only: neither mirror file changed size.
        self::assertSame(1024, filesize($mirror->mirrorPath('reachable-main')));
        self::assertSame(2048, filesize($mirror->mirrorPath('dead-generation')));
    }
}
