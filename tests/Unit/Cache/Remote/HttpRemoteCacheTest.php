<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache\Remote;

use Manuglopez\Replay\Cache\Remote\HttpRemoteCache;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises the HTTP backend against a real server: PHP's own built-in one, running
 * tests/Support/http-cache-server.php as its router, so the four verbs go over a socket
 * rather than through a test double. Skipped when no local port can be bound.
 */
final class HttpRemoteCacheTest extends TestCase
{
    private const TOKEN = 's3cr3t-token';

    private ?Process $server = null;

    private string $storage = '';

    private string $base = '';

    protected function setUp(): void
    {
        $port = self::freePort();

        if ($port === null) {
            self::markTestSkipped('cannot bind a local TCP port');
        }

        $this->storage = TempDir::make('remote-http');
        $this->base = 'http://127.0.0.1:' . $port . '/cache/';

        $this->server = new Process(
            ['php', '-S', '127.0.0.1:' . $port, '-t', $this->storage, __DIR__ . '/../../../Support/http-cache-server.php'],
            $this->storage,
            [
                'REPLAY_HTTP_CACHE_DIR' => $this->storage,
                'REPLAY_HTTP_CACHE_TOKEN' => self::TOKEN,
                'REPLAY_HTTP_CACHE_PREFIX' => 'cache',
            ],
        );
        $this->server->setTimeout(60.0);
        $this->server->start();

        if (! self::waitForPort($port)) {
            $this->stopServer();
            self::markTestSkipped('the built-in PHP server did not come up');
        }
    }

    protected function tearDown(): void
    {
        $this->stopServer();

        if ($this->storage !== '') {
            TempDir::remove($this->storage);
        }
    }

    public function testFromRemoteAcceptsHttpAndHttpsOnly(): void
    {
        self::assertNotNull(HttpRemoteCache::fromRemote('http://cache.example/replay'));
        self::assertNotNull(HttpRemoteCache::fromRemote('https://cache.example/replay/'));
        self::assertNull(HttpRemoteCache::fromRemote('file:///mnt/cache'));
        self::assertNull(HttpRemoteCache::fromRemote('git@github.com:org/repo.git'));
    }

    public function testFromRemoteNormalisesTheBaseToASingleTrailingSlash(): void
    {
        $cache = HttpRemoteCache::fromRemote('https://cache.example/replay///');

        self::assertNotNull($cache);
        self::assertSame('https://cache.example/replay/', $cache->base());
        self::assertSame('http', $cache->name());
    }

    public function testPutGetHasAndDeleteRoundTripOverTheWire(): void
    {
        $cache = $this->cache();
        $cache->begin();

        self::assertFalse($cache->has('objects/2026-09/abc.json'));
        self::assertNull($cache->get('objects/2026-09/abc.json'));

        self::assertTrue($cache->put('objects/2026-09/abc.json', '{"k":"abc"}'), (string) $cache->lastError());
        self::assertFileExists($this->storage . '/objects/2026-09/abc.json');

        self::assertTrue($cache->has('objects/2026-09/abc.json'));
        self::assertSame('{"k":"abc"}', $cache->get('objects/2026-09/abc.json'));

        self::assertTrue($cache->delete('objects/2026-09/abc.json'));
        self::assertFalse($cache->has('objects/2026-09/abc.json'));

        // 404 is a successful delete: the key is gone either way.
        self::assertTrue($cache->delete('objects/2026-09/abc.json'));
    }

    public function testTheBearerTokenIsSentAndAMissingOneIsReportedNotThrown(): void
    {
        $withToken = $this->cache();
        self::assertTrue($withToken->put('objects/2026-09/with-token.json', '{}'));

        $withoutToken = HttpRemoteCache::fromRemote($this->base);
        self::assertNotNull($withoutToken);
        $withoutToken->begin();

        self::assertFalse($withoutToken->put('objects/2026-09/without-token.json', '{}'));
        self::assertStringContainsString('401', (string) $withoutToken->lastError());
        self::assertNull($withoutToken->get('objects/2026-09/with-token.json'));
        self::assertFalse($withoutToken->has('objects/2026-09/with-token.json'));
    }

    public function testListingIsUnsupportedAndSaysSo(): void
    {
        $cache = $this->cache();
        $cache->begin();
        $cache->put('objects/2026-09/abc.json', '{}');

        self::assertSame([], $cache->keys('objects/'));
        self::assertSame('listing not supported', $cache->lastError());
    }

    public function testAnUnreachableHostDegradesInsteadOfThrowing(): void
    {
        $port = self::freePort();

        if ($port === null) {
            self::markTestSkipped('cannot bind a local TCP port');
        }

        $cache = HttpRemoteCache::fromRemote('http://127.0.0.1:' . $port . '/cache/');
        self::assertNotNull($cache);
        $cache->begin();

        self::assertNull($cache->get('objects/2026-09/abc.json'));
        self::assertFalse($cache->has('objects/2026-09/abc.json'));
        self::assertFalse($cache->put('objects/2026-09/abc.json', '{}'));
        self::assertNotNull($cache->lastError());
    }

    public function testAKeyThatWouldEscapeTheBaseIsRefusedBeforeAnyRequest(): void
    {
        $cache = $this->cache();
        $cache->begin();

        self::assertFalse($cache->put('../../etc/passwd', 'nope'));
        self::assertStringContainsString('unsafe key', (string) $cache->lastError());
    }

    private function cache(): HttpRemoteCache
    {
        $cache = HttpRemoteCache::fromRemote($this->base, self::TOKEN);

        self::assertNotNull($cache);
        $cache->begin();

        return $cache;
    }

    private function stopServer(): void
    {
        if ($this->server !== null) {
            $this->server->stop(1.0);
            $this->server = null;
        }
    }

    /** A port nothing is listening on: bound to 0, read back, released. */
    private static function freePort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

        if ($socket === false) {
            return null;
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false) {
            return null;
        }

        $colon = strrpos($name, ':');

        return $colon === false ? null : (int) substr($name, $colon + 1);
    }

    private static function waitForPort(int $port, float $timeout = 10.0): bool
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage, 0.2);

            if ($connection !== false) {
                fclose($connection);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }
}
