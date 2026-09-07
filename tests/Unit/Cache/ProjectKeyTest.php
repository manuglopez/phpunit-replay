<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\ProjectKey;
use Manuglopez\Replay\Tests\Support\GitRepo;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ProjectKeyTest extends TestCase
{
    public function testNormalizeOriginUrlHandlesEquivalentForms(): void
    {
        self::assertSame('github.com/org/repo', ProjectKey::normalizeOriginUrl('git@github.com:Org/Repo.git'));
        self::assertSame('github.com/org/repo', ProjectKey::normalizeOriginUrl('https://user@github.com/org/repo.git'));
        self::assertSame('github.com/org/repo', ProjectKey::normalizeOriginUrl('ssh://git@github.com:2222/org/repo'));
    }

    public function testNormalizeOriginUrlHandlesFileSchemeWithoutHost(): void
    {
        self::assertSame('x/y', ProjectKey::normalizeOriginUrl('file:///x/y'));
    }

    public function testOriginIdentityReadsRemoteFromGitConfig(): void
    {
        $repo = GitRepo::init();
        $repo->git('remote', 'add', 'origin', 'https://github.com/Org/Repo.git');

        try {
            self::assertSame('github.com/org/repo', ProjectKey::originIdentity($repo->root));
        } finally {
            $repo->destroy();
        }
    }

    public function testOriginIdentityReturnsNullWithoutRemote(): void
    {
        $repo = GitRepo::init();

        try {
            self::assertNull(ProjectKey::originIdentity($repo->root));
        } finally {
            $repo->destroy();
        }
    }

    public function testOriginIdentityReturnsNullWithoutGitAtAll(): void
    {
        $dir = TempDir::make('no-git');

        try {
            self::assertNull(ProjectKey::originIdentity($dir));
        } finally {
            TempDir::remove($dir);
        }
    }

    public function testForFallsBackToRealpathHashWhenNoRemote(): void
    {
        $dir = TempDir::make('projectkey-plain');

        try {
            $real = realpath($dir);
            self::assertIsString($real);

            $expected = basename($real) . '-' . substr(hash('sha256', $real), 0, 16);

            self::assertSame($expected, ProjectKey::for($dir));
        } finally {
            TempDir::remove($dir);
        }
    }

    public function testForKeyFormatIsSlugDashSixteenHex(): void
    {
        $dir = TempDir::make('key-format');

        try {
            self::assertMatchesRegularExpression('/^[a-z0-9-]*-[0-9a-f]{16}$/', ProjectKey::for($dir));
        } finally {
            TempDir::remove($dir);
        }
    }

    public function testForUsesOriginIdentityHashWhenRemoteConfigured(): void
    {
        $repo = GitRepo::init();
        $repo->git('remote', 'add', 'origin', 'git@github.com:Org/Repo.git');

        try {
            $expectedHash = substr(hash('sha256', 'github.com/org/repo'), 0, 16);

            self::assertStringEndsWith('-' . $expectedHash, ProjectKey::for($repo->root));
        } finally {
            $repo->destroy();
        }
    }

    public function testSharedIsIndependentOfBasenameWhenAnOriginExists(): void
    {
        $repoA = GitRepo::init(null, 'main');
        $repoA->git('remote', 'add', 'origin', 'git@github.com:Org/Repo.git');

        $container = TempDir::make('projectkey-shared-b');
        $repoB = GitRepo::init($container . '/a-totally-different-name', 'main');
        $repoB->git('remote', 'add', 'origin', 'git@github.com:Org/Repo.git');

        try {
            self::assertNotSame(basename($repoA->root), basename($repoB->root));
            self::assertNotSame(ProjectKey::for($repoA->root), ProjectKey::for($repoB->root), 'for() is basename-sensitive');

            $expected = 'p-' . substr(hash('sha256', 'github.com/org/repo'), 0, 16);

            self::assertSame($expected, ProjectKey::shared($repoA->root));
            self::assertSame($expected, ProjectKey::shared($repoB->root));
            self::assertSame(ProjectKey::shared($repoA->root), ProjectKey::shared($repoB->root));
        } finally {
            $repoA->destroy();
            $repoB->destroy();
            TempDir::remove($container);
        }
    }

    public function testSharedFallsBackToForWhenThereIsNoOrigin(): void
    {
        $dir = TempDir::make('projectkey-shared-no-origin');

        try {
            self::assertSame(ProjectKey::for($dir), ProjectKey::shared($dir));
        } finally {
            TempDir::remove($dir);
        }
    }

    public function testWorktreeSharesOriginIdentityWithMainCheckout(): void
    {
        $main = GitRepo::init();
        $main->git('remote', 'add', 'origin', 'git@github.com:Org/Repo.git');
        $main->commitAll('initial');

        $container = TempDir::make('worktree-container');
        $worktreeDir = $container . '/wt';

        try {
            $main->git('worktree', 'add', '-q', $worktreeDir, '-b', 'feature');

            self::assertTrue(is_file($worktreeDir . '/.git'), 'a worktree checkout has a .git *file*, not a directory');

            $mainIdentity = ProjectKey::originIdentity($main->root);
            $worktreeIdentity = ProjectKey::originIdentity($worktreeDir);

            self::assertSame('github.com/org/repo', $mainIdentity);
            self::assertSame($mainIdentity, $worktreeIdentity);
        } finally {
            $main->git('worktree', 'remove', '--force', $worktreeDir);
            TempDir::remove($container);
            $main->destroy();
        }
    }
}
