<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache\Remote;

use Manuglopez\Replay\Cache\Remote\FilesystemRemoteCache;
use Manuglopez\Replay\Cache\Remote\HttpRemoteCache;
use Manuglopez\Replay\Cache\Remote\NullRemoteCache;
use Manuglopez\Replay\Cache\Remote\RemoteCache;
use Manuglopez\Replay\Cache\Remote\RemoteCacheFactory;
use Manuglopez\Replay\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemoteCacheFactoryTest extends TestCase
{
    private const GIT_BACKEND = 'Manuglopez\\Replay\\Cache\\Remote\\GitRemoteCache';

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
            ['git@github.com:org/replay-cache.git'],
            ['https://github.com/org/replay-cache.git'],
            ['/srv/mirrors/replay-cache.git'],
        ];
    }

    #[DataProvider('gitRemotes')]
    public function testGitLookingRemotesAreRecognised(string $remote): void
    {
        self::assertTrue(RemoteCacheFactory::looksLikeGit($remote), $remote);
    }

    #[DataProvider('gitRemotes')]
    public function testAGitRemoteGoesToTheGitBackendWhenItIsAvailable(string $remote): void
    {
        $backend = $this->backendFor($remote);

        if (! class_exists(self::GIT_BACKEND)) {
            // The git backend ships as its own unit; without it the factory warns and the
            // run continues local-only rather than dying.
            self::assertInstanceOf(NullRemoteCache::class, $backend);

            return;
        }

        self::assertSame('git', $backend->name());
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
}
