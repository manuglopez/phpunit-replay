<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Where falling back to a parent baseline's layer is NOT sound (`Cache\Graph::fallbackChain()`):
 * below a nearest baseline whose sha the resolver read off the remote. The pass diffs from
 * that sha, but this machine's graph holds no layer for it, so what it serves comes from
 * the default branch's layer — recorded on a tree the diff never compared against.
 *
 *   machine 1: main ──● recorded, pushed
 *                     └── develop ──● breaks Money::add, run, graph pushed
 *   machine 2: main pulled; fetches develop; feature cut from develop, runs
 *
 * On machine 2 `develop` (0 files away, from the remote) wins over `main` (1 file away,
 * local), its diff is empty, and before the fix main's passes for everything Money touches
 * were replayed: exit 0 while PHPUnit itself fails 5 tests.
 */
final class RemoteNearestBaselineFallbackTest extends TestCase
{
    private const ORIGIN = 'https://example.invalid/acme/shop.git';

    /** @var list<string> */
    private array $temporaries = [];

    /** @var list<FixtureProject> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        foreach ($this->temporaries as $dir) {
            TempDir::remove($dir);
        }
    }

    public function test_a_default_layer_under_a_remote_nearest_baseline_is_checked_against_its_own_diff(): void
    {
        $sharedCache = $this->tempDir('shared-cache');
        $env = [
            'PHPUNIT_REPLAY_REMOTE' => 'file://' . $sharedCache,
            'PHPUNIT_REPLAY_REMOTE_PUSH' => 'all',
            'PHPUNIT_REPLAY_BASELINE_BRANCHES' => 'develop,main',
            'CI' => '',
        ];

        $machine1 = $this->fixtures[] = FixtureProject::plain($this->tempDir('machine1') . '/shop');
        $machine1->repo->git('remote', 'add', 'origin', self::ORIGIN);
        $machine2 = $this->fixtures[] = $machine1->copyTo($this->tempDir('machine2') . '/warehouse');

        $recorded = $machine1->replay(['record'], $env);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        // Machine 2 adopts main's baseline from the remote: its local graph holds main only.
        $pulled = $machine2->replay([], $env);
        self::assertSame(0, $pulled['exitCode'], $pulled['stdout'] . $pulled['stderr']);

        $machine1->repo->checkout('develop', create: true);
        $machine1->write('src/Money.php', str_replace(
            'return new self($this->amount + $other->amount, $this->currency);',
            'return new self($this->amount - $other->amount + 0, $this->currency);',
            $machine1->read('src/Money.php'),
        ));
        $machine1->repo->commitAll('develop breaks Money::add');
        $onDevelop = $machine1->replay([], $env);
        self::assertSame(1, $onDevelop['exitCode'], $onDevelop['stdout'] . $onDevelop['stderr']);

        $machine2->repo->git('fetch', '-q', $machine1->root(), 'develop:develop');
        $machine2->repo->git('checkout', '-q', '-b', 'feature', 'develop');

        $phpunit = $machine2->phpunit();
        self::assertSame(1, $phpunit['exitCode'], 'control: PHPUnit itself fails on this tree');

        // The plan is taken before the run changes anything, but asserted after it: the exit
        // code is the false green itself, and must be what fails first without the fix.
        $dryRun = $machine2->replay(['run', '--dry-run'], $env);

        $run = $machine2->replay([], $env);
        self::assertSame(1, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertGreaterThan(0, ReplayAssert::executedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));

        self::assertStringContainsString('baseline develop@', $dryRun['stdout'], $dryRun['stdout']);
        self::assertMatchesRegularExpression('#tests/MoneyTest\.php\s+← StaleLayer src/Money\.php \(main@[0-9a-f]{7}\)#u', $dryRun['stdout'], $dryRun['stdout']);
    }

    private function tempDir(string $prefix): string
    {
        $dir = TempDir::make($prefix);
        $this->temporaries[] = $dir;

        return $dir;
    }
}
