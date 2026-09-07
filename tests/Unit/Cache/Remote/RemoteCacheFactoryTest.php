<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache\Remote;

use Manuglopez\Replay\Cache\Remote\FilesystemRemoteCache;
use Manuglopez\Replay\Cache\Remote\GitRemoteCache;
use Manuglopez\Replay\Cache\Remote\HttpRemoteCache;
use Manuglopez\Replay\Cache\Remote\NullRemoteCache;
use Manuglopez\Replay\Cache\Remote\RemoteCache;
use Manuglopez\Replay\Cache\Remote\RemoteCacheFactory;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RemoteCacheFactoryTest extends TestCase
{
    public function testNoRemoteConfiguredIsTheNullBackend(): void
    {
        self::assertInstanceOf(NullRemoteCache::class, RemoteCacheFactory::fromConfig(Config::defaults(), '/state'));
        self::assertInstanceOf(NullRemoteCache::class, $this->backendFor(''));
        self::assertInstanceOf(NullRemoteCache::class, $this->backendFor('   '));
    }

    public function testAFileUrlOrAnAbsolutePathIsTheFilesystemBackend(): void
    {
        $fromUrl = $this->backendFor('file:///mnt/replay-cache');
        $fromPath = $this->backendFor('/mnt/replay-cache/');

        self::assertInstanceOf(FilesystemRemoteCache::class, $fromUrl);
        self::assertInstanceOf(FilesystemRemoteCache::class, $fromPath);
        self::assertSame('/mnt/replay-cache', $fromUrl->root());
        self::assertSame('/mnt/replay-cache', $fromPath->root());
        self::assertSame('file', $fromUrl->name());
    }

    public function testAnHttpUrlIsTheHttpBackendAndCarriesTheToken(): void
    {
        $config = Config::defaults()->with([
            'remote' => 'https://cache.example/replay',
            'remoteToken' => 'secret',
        ]);

        $backend = RemoteCacheFactory::fromConfig($config, '/state');

        self::assertInstanceOf(HttpRemoteCache::class, $backend);
        self::assertSame('https://cache.example/replay/', $backend->base());
        self::assertSame('http', $backend->name());
    }

    /**
     * @return list<array{string}>
     */
    public static function gitRemotes(): array
    {
        return [
            ['git+ssh://git@github.com/org/replay-cache'],
            ['git+https://github.com/org/replay-cache'],
            ['ssh://git@github.com/org/replay-cache'],
            ['git@github.com:org/cache.git'],
            ['https://github.com/org/cache.git'],
            ['/srv/mirrors/replay-cache.git'],
        ];
    }

    #[DataProvider('gitRemotes')]
    public function testGitLookingRemotesAreRecognised(string $remote): void
    {
        self::assertTrue(RemoteCacheFactory::looksLikeGit($remote), $remote);
    }

    /**
     * `GitRemoteCache` ships in the same package (docs/INTERNALS.md "Phase 3 contracts —
     * distribution"), so every git-looking remote form the factory recognises must yield a
     * real `GitRemoteCache` instance, never fall back to `NullRemoteCache`.
     */
    #[DataProvider('gitRemotes')]
    public function testAGitRemoteGoesToTheGitBackend(string $remote): void
    {
        $backend = $this->backendFor($remote);

        self::assertInstanceOf(GitRemoteCache::class, $backend);
        self::assertSame('git', $backend->name());
    }

    /**
     * `file:///…/bare.git` against a REAL bare repository: proves the factory does not just
     * pattern-match the URL but hands back a `GitRemoteCache` that actually clones and works
     * (`PruneCommand`'s git backend construction goes through this same
     * `RemoteCacheFactory::fromConfig()` path).
     */
    public function testAFileUrlEndingInDotGitYieldsAWorkingGitRemoteCacheAgainstARealBareRepo(): void
    {
        $base = TempDir::make('remote-cache-factory-git');
        $bareRepo = $base . '/cache.git';
        $stateDir = $base . '/state';

        $this->git($base, ['init', '--bare', '-q', '-b', 'main', $bareRepo]);

        $config = Config::defaults()->with(['remote' => 'file://' . $bareRepo]);
        $backend = RemoteCacheFactory::fromConfig($config, $stateDir);

        self::assertInstanceOf(GitRemoteCache::class, $backend);
        self::assertSame('git', $backend->name());
        self::assertSame('file://' . $bareRepo, $backend->remoteUrl());
        self::assertSame('main', $backend->branch());
        self::assertSame($stateDir . '/remote/git', $backend->mirrorDir());

        $backend->begin();
        self::assertNull($backend->lastError());

        self::assertTrue($backend->put('objects/2026-01/k.json', '{"i":1}'));
        $backend->end();
        self::assertNull($backend->lastError());

        $verify = RemoteCacheFactory::fromConfig($config, $base . '/state-verify');
        self::assertInstanceOf(GitRemoteCache::class, $verify);
        $verify->begin();
        self::assertNull($verify->lastError());
        self::assertSame('{"i":1}', $verify->get('objects/2026-01/k.json'));

        TempDir::remove($base);
    }

    public function testNonGitRemotesAreNotMistakenForGit(): void
    {
        self::assertFalse(RemoteCacheFactory::looksLikeGit('file:///mnt/replay-cache'));
        self::assertFalse(RemoteCacheFactory::looksLikeGit('https://cache.example/replay/'));
        self::assertFalse(RemoteCacheFactory::looksLikeGit('/mnt/replay-cache'));
        self::assertFalse(RemoteCacheFactory::looksLikeGit(''));
    }

    public function testAnUnsupportedSchemeFallsBackToNull(): void
    {
        self::assertInstanceOf(NullRemoteCache::class, $this->backendFor('s3://bucket/prefix'));
        self::assertInstanceOf(NullRemoteCache::class, $this->backendFor('relative/path'));
        self::assertInstanceOf(NullRemoteCache::class, $this->backendFor('ftp://cache.example/replay'));
    }

    private function backendFor(string $remote): RemoteCache
    {
        return RemoteCacheFactory::fromConfig(Config::defaults()->with(['remote' => $remote]), '/state');
    }

    /** @param list<string> $arguments */
    private function git(string $dir, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $dir);
        $process->setTimeout(30.0);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'git %s failed (%d): %s',
                implode(' ', $arguments),
                $process->getExitCode() ?? -1,
                $process->getErrorOutput(),
            ));
        }

        return $process->getOutput();
    }
}
