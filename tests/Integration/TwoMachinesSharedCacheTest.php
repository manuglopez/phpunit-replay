<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\ProjectKey;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The phase 3 gate (SPEC.md §9, docs/INTERNALS.md "Phase 3 contracts — distribution"): two
 * checkouts of the same project, each with its own `$HOME` (and therefore its own state
 * directory), sharing one `file://` remote cache. What one machine runs, the other replays
 * — by content key, without needing the same baseline sha to have been recorded locally.
 *
 * Both checkouts carry the same commits and the same `origin`, but their roots deliberately
 * have DIFFERENT basenames (`shop` vs. `warehouse`) — exactly like two developers cloning
 * the same repository into differently named directories. `Cache\ProjectKey::shared()`
 * resolves identically on both regardless (it is keyed off the origin identity alone, never
 * the checkout directory name), which is what makes `graph/<key>/<branch>.json` a shared
 * remote key rather than a per-checkout one; `Cache\ProjectKey::for()` — used only for the
 * LOCAL state directory name — is intentionally basename-sensitive and therefore differs.
 */
final class TwoMachinesSharedCacheTest extends TestCase
{
    private const ORIGIN = 'https://example.invalid/acme/shop.git';

    private FixtureProject $machine1;

    private FixtureProject $machine2;

    private string $sharedCache;

    /** @var list<string> */
    private array $temporaries = [];

    protected function setUp(): void
    {
        $this->sharedCache = $this->tempDir('shared-cache');

        $this->machine1 = FixtureProject::plain($this->tempDir('machine1') . '/shop');
        $this->machine1->repo->git('remote', 'add', 'origin', self::ORIGIN);

        // Different basename on purpose (SPEC.md §9): the remote graph key must not depend
        // on the checkout directory name.
        $this->machine2 = $this->machine1->copyTo($this->tempDir('machine2') . '/warehouse');

        self::assertNotSame(
            basename($this->machine1->root()),
            basename($this->machine2->root()),
            'this test is only meaningful when the two checkouts have different basenames',
        );
        self::assertNotSame(
            ProjectKey::for($this->machine1->root()),
            ProjectKey::for($this->machine2->root()),
            'for() is basename-sensitive: the two checkouts must NOT share a local project key',
        );
        self::assertSame(
            ProjectKey::shared($this->machine1->root()),
            ProjectKey::shared($this->machine2->root()),
            'shared() must resolve identically for two clones of the same origin, regardless of basename',
        );
    }

    protected function tearDown(): void
    {
        $this->machine1->destroy();
        $this->machine2->destroy();

        foreach ($this->temporaries as $dir) {
            TempDir::remove($dir);
        }
    }

    public function test_a_second_machine_replays_everything_from_the_shared_cache(): void
    {
        $env = $this->env();

        $recorded = $this->machine1->replay(['record'], $env);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
        self::assertStringContainsString('recorded 35 tests', $recorded['stdout']);

        // The whole baseline plus one object per test file is now on the shared cache.
        self::assertFileExists($this->sharedCache . '/graph/' . ProjectKey::shared($this->machine1->root()) . '/main.json');
        self::assertCount(7, $this->remoteObjects());

        // Machine 2 has never run anything: no local graph, no local objects. It pulls the
        // baseline, finds every content key already published, and executes nothing.
        self::assertNull(ReplayAssert::loadGraph($this->machine2));

        $second = $this->machine2->replay([], $env);

        self::assertSame(0, $second['exitCode'], $second['stdout'] . $second['stderr']);
        self::assertStringContainsString(
            '0 executed (0 affected, 0 uncached) · 35 replayed (35 from remote)',
            ReplayAssert::lastLine($second['stdout']),
        );
        // `PHPUnit\Runner\Version::getVersionString()` is 'PHPUnit <id> by Sebastian Bergmann
        // and contributors.' on every supported major; asserting the stable, version-agnostic
        // half proves no PHPUnit banner was printed at all (i.e. no PHPUnit process ran),
        // without hard-coding a major that would go stale on the next supported release.
        self::assertStringNotContainsString('by Sebastian Bergmann and contributors', $second['stdout'], 'PHPUnit must not have been launched at all');
        self::assertNotNull(ReplayAssert::loadGraph($this->machine2), 'the pulled baseline is now this machine\'s graph');

        // A real change on machine 2: the affected files have no published object for their
        // NEW content, so they run here for the first time.
        $this->machine2->applyVariant('Money.behaviour.php', 'src/Money.php');
        $afterEdit = $this->machine2->replay([], $env);

        self::assertSame(0, $afterEdit['exitCode'], $afterEdit['stdout'] . $afterEdit['stderr']);
        self::assertSame(31, ReplayAssert::executedCount($afterEdit['stdout']), ReplayAssert::lastLine($afterEdit['stdout']));
        self::assertSame(4, ReplayAssert::replayedCount($afterEdit['stdout']));

        // Machine 1 now makes the very same edit. Same file contents, same dependency
        // contents, same content keys — the work machine 2 just did comes back over the
        // shared cache and nothing runs here.
        $this->machine1->applyVariant('Money.behaviour.php', 'src/Money.php');
        $inherited = $this->machine1->replay([], $env);

        self::assertSame(0, $inherited['exitCode'], $inherited['stdout'] . $inherited['stderr']);
        self::assertStringContainsString(
            '0 executed (0 affected, 0 uncached) · 35 replayed (31 from remote)',
            ReplayAssert::lastLine($inherited['stdout']),
        );
        // Same rationale as above: the version-stable half of PHPUnit's own banner.
        self::assertStringNotContainsString('by Sebastian Bergmann and contributors', $inherited['stdout']);
    }

    public function test_status_reports_the_remote_and_its_push_policy(): void
    {
        $status = $this->machine1->replay(['status'], $this->env());

        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertStringContainsString('remote:    file file://' . $this->sharedCache, $status['stdout']);
        self::assertStringContainsString('push:      all', $status['stdout']);
    }

    public function test_the_remote_token_is_masked_in_status(): void
    {
        $status = $this->machine1->replay(['status'], [
            'PHPUNIT_REPLAY_REMOTE' => 'https://ci:hunter2@cache.example/replay/',
            'PHPUNIT_REPLAY_REMOTE_TOKEN' => 'do-not-print-me',
        ]);

        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertStringContainsString('remote:    http https://ci:***@cache.example/replay/', $status['stdout']);
        self::assertStringNotContainsString('hunter2', $status['stdout']);
        self::assertStringNotContainsString('do-not-print-me', $status['stdout']);
    }

    public function test_push_graph_and_pull_round_trip(): void
    {
        // remote_push=off: the pass itself publishes nothing, so `push` is the only writer.
        $offline = ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $this->sharedCache, 'PHPUNIT_REPLAY_REMOTE_PUSH' => 'off', 'CI' => ''];

        $recorded = $this->machine1->replay(['record'], $offline);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
        self::assertSame([], $this->remoteObjects(), 'remote_push=off must publish nothing');

        $pushed = $this->machine1->replay(['push', '--graph'], $offline);

        self::assertSame(0, $pushed['exitCode'], $pushed['stdout'] . $pushed['stderr']);
        self::assertStringContainsString('pushed 7 object(s) to the file remote', $pushed['stdout']);
        self::assertStringContainsString('pushed the main baseline', $pushed['stdout']);
        self::assertCount(7, $this->remoteObjects());

        $pulled = $this->machine2->replay(['pull'], $offline);

        self::assertSame(0, $pulled['exitCode'], $pulled['stdout'] . $pulled['stderr']);
        self::assertStringContainsString('pulled the main baseline: 7 test files, 35 results', $pulled['stdout']);

        $graph = ReplayAssert::loadGraph($this->machine2);
        self::assertNotNull($graph);
        self::assertCount(35, $graph->results('main'));

        // And the pulled baseline is a working one: nothing changed, nothing runs.
        $run = $this->machine2->replay([], $offline);
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($run['stdout']));
        self::assertSame(35, ReplayAssert::replayedCount($run['stdout']));
    }

    public function test_push_and_pull_report_a_remote_that_is_not_configured(): void
    {
        $push = $this->machine1->replay(['push']);
        $pull = $this->machine1->replay(['pull']);

        self::assertSame(1, $push['exitCode']);
        self::assertStringContainsString('no remote configured', $push['stdout']);
        self::assertSame(1, $pull['exitCode']);
        self::assertStringContainsString('no remote configured', $pull['stdout']);
    }

    public function test_pull_says_so_when_the_remote_holds_no_baseline(): void
    {
        $pull = $this->machine2->replay(['pull'], $this->env());

        self::assertSame(1, $pull['exitCode']);
        self::assertStringContainsString('holds no baseline for main', $pull['stdout']);
    }

    public function test_no_remote_skips_the_remote_entirely(): void
    {
        $env = $this->env();

        $recorded = $this->machine1->replay(['record'], $env);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        // Machine 2 could replay all 35 from the shared cache — but is told not to look.
        $fresh = $this->machine2->replay(['--no-remote'], $env);

        self::assertSame(0, $fresh['exitCode'], $fresh['stdout'] . $fresh['stderr']);
        self::assertStringContainsString('recorded 35 tests', $fresh['stdout'], 'it should have recorded from scratch');
        self::assertDirectoryDoesNotExist(ReplayAssert::stateDir($this->machine2) . '/remote');
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

    /** @return list<string> object keys currently on the shared cache */
    private function remoteObjects(): array
    {
        $found = glob($this->sharedCache . '/objects/*/*.json');

        return $found === false ? [] : array_values($found);
    }

    private function tempDir(string $prefix): string
    {
        $dir = TempDir::make($prefix);
        $this->temporaries[] = $dir;

        return $dir;
    }
}
