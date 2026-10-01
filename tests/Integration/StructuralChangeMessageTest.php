<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * A structural change makes the LOCAL baseline unusable. What happens next depends on
 * the remote: a matching baseline is adopted (nothing is recorded), otherwise a fresh one
 * is recorded. The messages must say what actually happened, in that order: the fact
 * first, the decision once it is known.
 */
final class StructuralChangeMessageTest extends TestCase
{
    private const ORIGIN = 'https://example.invalid/acme/shop.git';

    private const DRIFT = "\n// bump\n";

    /** @var list<string> */
    private array $temporaries = [];

    /** @var list<FixtureProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            $project->destroy();
        }

        foreach ($this->temporaries as $dir) {
            TempDir::remove($dir);
        }
    }

    public function test_a_remote_baseline_that_matches_is_adopted_and_nothing_is_said_about_recording(): void
    {
        $env = $this->env($this->tempDir('shared-cache'));

        $first = $this->project('machine1');
        $first->repo->git('remote', 'add', 'origin', self::ORIGIN);
        $real = $first->copyTo($this->tempDir('machine2') . '/warehouse');
        $dry = $first->copyTo($this->tempDir('machine3') . '/depot');
        $this->projects[] = $real;
        $this->projects[] = $dry;

        $recorded = $first->replay(['record'], $env);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        // The other machines pull the baseline, so each holds a local graph. (Two of them:
        // a run that adopts the remote baseline saves it, so the same machine cannot show
        // the real run and the dry run one after the other.)
        foreach ([$real, $dry] as $machine) {
            $pulled = $machine->replay([], $env);
            self::assertSame(0, $pulled['exitCode'], $pulled['stdout'] . $pulled['stderr']);
        }

        // CI records again after the lock changed; the others change it the same way.
        $first->write('composer.lock', $first->read('composer.lock') . self::DRIFT);
        $rerecorded = $first->replay(['record'], $env);
        self::assertSame(0, $rerecorded['exitCode'], $rerecorded['stdout'] . $rerecorded['stderr']);

        foreach ([[$real, []], [$dry, ['--dry-run']]] as [$machine, $args]) {
            $machine->write('composer.lock', $machine->read('composer.lock') . self::DRIFT);

            $result = $machine->replay($args, $env);
            $all = $result['stdout'] . $result['stderr'];

            self::assertSame(0, $result['exitCode'], $all);
            self::assertStringContainsString(
                'phpunit-replay: structural change (composer_lock): the cached baseline cannot be used' . PHP_EOL,
                $result['stderr'],
            );
            self::assertStringContainsString('adopted the main baseline (', $result['stderr']);
            self::assertStringContainsString(') from the remote', $result['stderr']);
            self::assertStringNotContainsString('recording a fresh baseline', $all);
            self::assertStringNotContainsString('would record the full suite', $all);

            if ($args === []) {
                self::assertStringContainsString('0 executed', ReplayAssert::lastLine($result['stdout']));
                self::assertStringContainsString('35 replayed (35 from remote)', ReplayAssert::lastLine($result['stdout']));
            }
        }
    }

    public function test_a_structural_change_with_no_remote_still_says_it_records_a_fresh_baseline(): void
    {
        $project = $this->project('solo');
        $recorded = $project->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $project->write('composer.lock', $project->read('composer.lock') . self::DRIFT);

        $dry = $project->replay(['--dry-run']);
        self::assertStringContainsString('structural change (composer_lock): the cached baseline cannot be used', $dry['stderr']);
        self::assertStringContainsString('recording a fresh baseline', $dry['stderr']);

        $result = $project->replay([]);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('structural change (composer_lock): the cached baseline cannot be used', $result['stderr']);
        self::assertStringContainsString('phpunit-replay: recording a fresh baseline' . PHP_EOL, $result['stderr']);
        self::assertStringContainsString('recorded 35 tests', $result['stdout']);
    }

    private function project(string $name): FixtureProject
    {
        $project = FixtureProject::plain($this->tempDir($name) . '/shop');
        $this->projects[] = $project;

        return $project;
    }

    /** @return array<string, string> */
    private function env(string $cache): array
    {
        return [
            'PHPUNIT_REPLAY_REMOTE' => 'file://' . $cache,
            'PHPUNIT_REPLAY_REMOTE_PUSH' => 'all',
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
