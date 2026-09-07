<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache\Remote;

use Manuglopez\Replay\Cache\Remote\FilesystemRemoteCache;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class FilesystemRemoteCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('remote-file');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function testFromRemoteAcceptsTheFileScheme(): void
    {
        $cache = FilesystemRemoteCache::fromRemote('file://' . $this->root . '/cache');

        self::assertNotNull($cache);
        self::assertSame($this->root . '/cache', $cache->root());
        self::assertSame('file', $cache->name());
    }

    public function testFromRemoteAcceptsTheLocalhostFormAndABarePath(): void
    {
        $localhost = FilesystemRemoteCache::fromRemote('file://localhost' . $this->root);
        $bare = FilesystemRemoteCache::fromRemote($this->root . '/');

        self::assertNotNull($localhost);
        self::assertNotNull($bare);
        self::assertSame($this->root, $localhost->root());
        self::assertSame($this->root, $bare->root());
    }

    public function testFromRemoteRejectsAnythingThatIsNotAPath(): void
    {
        self::assertNull(FilesystemRemoteCache::fromRemote('https://cache.example/replay/'));
        self::assertNull(FilesystemRemoteCache::fromRemote('relative/path'));
        self::assertNull(FilesystemRemoteCache::fromRemote(''));
    }

    public function testBeginCreatesTheRootAndPutGetRoundTrips(): void
    {
        $cache = $this->cache();
        $cache->begin();

        self::assertDirectoryExists($this->root . '/store');
        self::assertNull($cache->lastError());

        self::assertTrue($cache->put('objects/2026-09/abc.json', '{"k":"abc"}'));
        self::assertTrue($cache->has('objects/2026-09/abc.json'));
        self::assertSame('{"k":"abc"}', $cache->get('objects/2026-09/abc.json'));
        self::assertFileExists($this->root . '/store/objects/2026-09/abc.json');
    }

    public function testGetAndHasMissOnAnUnknownKey(): void
    {
        $cache = $this->cache();
        $cache->begin();

        self::assertNull($cache->get('objects/2026-09/nope.json'));
        self::assertFalse($cache->has('objects/2026-09/nope.json'));
    }

    public function testPutIsAtomicSoAReaderNeverSeesAHalfWrittenObject(): void
    {
        $cache = $this->cache();
        $cache->begin();
        $cache->put('objects/2026-09/abc.json', 'first');
        $cache->put('objects/2026-09/abc.json', 'second');

        self::assertSame('second', $cache->get('objects/2026-09/abc.json'));

        // AtomicFile writes through a temp file in the same directory and renames it: no
        // leftovers once the write completed.
        $entries = array_values(array_diff(scandir($this->root . '/store/objects/2026-09') ?: [], ['.', '..']));
        self::assertSame(['abc.json'], $entries);
    }

    public function testKeysScansTheDirectoryUnderAPrefix(): void
    {
        $cache = $this->cache();
        $cache->begin();
        $cache->put('objects/2026-08/one.json', '1');
        $cache->put('objects/2026-09/two.json', '2');
        $cache->put('graph/project-abc/main.json', '{}');

        self::assertSame(
            ['objects/2026-08/one.json', 'objects/2026-09/two.json'],
            $cache->keys('objects/'),
        );
        self::assertSame(['objects/2026-09/two.json'], $cache->keys('objects/2026-09'));
        self::assertSame(['graph/project-abc/main.json'], $cache->keys('graph/'));
        self::assertSame([], $cache->keys('objects/1999-01/'));
    }

    public function testDeleteRemovesAKeyAndIsHappyWhenItWasAlreadyGone(): void
    {
        $cache = $this->cache();
        $cache->begin();
        $cache->put('objects/2026-09/abc.json', '{}');

        self::assertTrue($cache->delete('objects/2026-09/abc.json'));
        self::assertFalse($cache->has('objects/2026-09/abc.json'));
        self::assertTrue($cache->delete('objects/2026-09/abc.json'));
    }

    public function testAKeyThatWouldEscapeTheRootIsRefused(): void
    {
        $cache = $this->cache();
        $cache->begin();

        self::assertFalse($cache->put('../escaped.json', 'nope'));
        self::assertStringContainsString('unsafe key', (string) $cache->lastError());
        self::assertNull($cache->get('objects/../../escaped.json'));
        self::assertFalse($cache->has('..'));
        self::assertFileDoesNotExist($this->root . '/escaped.json');
    }

    public function testAnUnwritableRootIsReportedRatherThanThrown(): void
    {
        $cache = new FilesystemRemoteCache($this->root . '/file-in-the-way/store');
        TempDir::write($this->root . '/file-in-the-way', 'not a directory');

        $cache->begin();

        self::assertNotNull($cache->lastError());
        self::assertFalse($cache->put('objects/2026-09/abc.json', '{}'));
        self::assertNull($cache->get('objects/2026-09/abc.json'));
        self::assertSame([], $cache->keys('objects/'));
    }

    private function cache(): FilesystemRemoteCache
    {
        $cache = FilesystemRemoteCache::fromRemote('file://' . $this->root . '/store');

        self::assertNotNull($cache);

        return $cache;
    }
}
