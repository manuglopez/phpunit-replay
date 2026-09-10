<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use PHPUnit\Framework\TestCase;

/**
 * `phpunit-replay remote:init` through the REAL `bin/phpunit-replay` binary, for the one thing
 * {@see RemoteInitCommandTest}'s `CommandTester` cannot see: `Console\Application`'s argv
 * pre-splitter has to recognise this command's own long options, or they land in the PHPUnit
 * passthrough bucket and the command — which declares no argument at all — fails with "too many
 * arguments" instead of running. That is not hypothetical; it is exactly the bug
 * {@see PruneRemoteArgvTest} was written for when `prune` gained `--remote`/`--keep-months`.
 *
 * `--dry-run` contacts nothing, creates nothing and writes nothing, so this needs no forge and
 * no reachable repository at all: `origin` is a URL that is only ever parsed.
 */
final class RemoteInitArgvTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
        $this->fixture->repo->git('remote', 'add', 'origin', 'git@example.invalid:acme/widget.git');
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_the_argv_splitter_hands_every_option_to_the_command(): void
    {
        $result = $this->fixture->replay([
            'remote:init',
            '--dry-run',
            '--name=custom-cache',
            '--owner=other-org',
            '--branch=cache-branch',
            '--no-create',
        ]);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('arguments', $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('git@example.invalid:other-org/custom-cache.git', $result['stdout']);
        self::assertStringContainsString('cache-branch', $result['stdout']);
        self::assertStringContainsString('dry run: nothing was contacted, created, pushed or written.', $result['stdout']);
    }

    /**
     * The pinned gap every `VALUE_REQUIRED` option in this CLI shares (`Application::isRecognised()`,
     * and `run --log-junit` / `prune --keep-months` in `tests/Unit/Console/ApplicationArgvSplitTest.php`):
     * the value has to be attached with `=`, because the splitter peeks a following token only
     * for a `VALUE_OPTIONAL` option. Two tokens are misrouted to the PHPUnit passthrough bucket,
     * which a command with no argument of its own rejects — visibly, with a non-zero exit, never
     * silently. Pinned here rather than fixed: closing it changes shared argv behaviour that has
     * its own pinned corpus.
     */
    public function test_a_value_required_option_still_needs_its_equals_sign(): void
    {
        $result = $this->fixture->replay(['remote:init', '--dry-run', '--branch', 'cache-branch']);

        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString('--branch', $result['stdout'] . $result['stderr']);
    }

    public function test_the_defaults_come_from_origin_and_the_command_is_listed(): void
    {
        $result = $this->fixture->replay(['remote:init', '--dry-run']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('git@example.invalid:acme/widget-replay-cache.git', $result['stdout']);

        $list = $this->fixture->replay(['list']);

        self::assertSame(0, $list['exitCode'], $list['stdout'] . $list['stderr']);
        self::assertStringContainsString('remote:init', $list['stdout']);
    }
}
