<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\ContentHash;
use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The pass-to-pass hash cache must never answer with a stale hash, and a stamp must never
 * carry the hash of content the tests may not have run on (`Cache\FileHashes`).
 */
final class FileHashesTest extends TestCase
{
    private string $root;

    private string $cache;

    protected function setUp(): void
    {
        $this->root = TempDir::make('file-hashes');
        $this->cache = $this->root . '/state/content-hashes.json';
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_a_file_changed_recently_is_not_remembered_across_passes(): void
    {
        TempDir::write($this->root . '/src/A.php', "<?php\nfinal class A {}\n");

        $first = new FileHashes($this->root, $this->cache);
        $first->of('src/A.php');
        $first->save();

        self::assertFileDoesNotExist($this->cache, 'its ctime is this second: racily clean, never cached');
    }

    public function test_a_remembered_hash_is_reused_only_while_the_stat_is_unchanged(): void
    {
        TempDir::write($this->root . '/src/A.php', "<?php\nfinal class A {}\n");
        $later = static fn (): int => time() + 10;

        $first = new FileHashes($this->root, $this->cache, $later);
        $hash = $first->of('src/A.php');
        $first->save();
        self::assertFileExists($this->cache);

        // Forge the cached hash: a cache hit must be the only way it could come back.
        $data = json_decode((string) file_get_contents($this->cache), true);
        self::assertIsArray($data);
        $data['entries']['src/A.php'][5] = 'forged';
        file_put_contents($this->cache, json_encode($data));

        self::assertSame('forged', (new FileHashes($this->root, $this->cache, $later))->of('src/A.php'), 'unchanged stat: served from the cache');

        // Any change moves ctime, whatever it does to mtime or size.
        TempDir::write($this->root . '/src/A.php', "<?php\nfinal class B {}\n");
        touch($this->root . '/src/A.php', 1_000_000_000);

        $fresh = (new FileHashes($this->root, $this->cache, $later))->of('src/A.php');
        self::assertSame(ContentHash::of($this->root . '/src/A.php'), $fresh);
        self::assertNotSame($hash, $fresh);
    }

    public function test_a_cache_from_another_version_is_ignored(): void
    {
        TempDir::write($this->root . '/src/A.php', "<?php\nfinal class A {}\n");
        TempDir::write($this->cache, json_encode(['v' => 'content-hash@0/1.0', 'entries' => ['src/A.php' => [1, 1, 1, 1, 1, 'forged']]]) ?: '');

        self::assertSame(ContentHash::of($this->root . '/src/A.php'), (new FileHashes($this->root, $this->cache))->of('src/A.php'));
    }

    public function test_a_file_changed_after_it_was_read_is_not_stable(): void
    {
        TempDir::write($this->root . '/src/A.php', "<?php\nfinal class A {}\n");
        $hashes = new FileHashes($this->root);
        $hashes->of('src/A.php');

        self::assertTrue($hashes->stable('src/A.php'));

        $other = new FileHashes($this->root);
        $other->of('src/A.php');
        TempDir::write($this->root . '/src/A.php', "<?php\nfinal class A { public int \$x = 1; }\n");

        self::assertFalse($other->stable('src/A.php'));
    }

    public function test_a_file_first_read_after_the_run_started_is_stable_only_if_it_changed_before(): void
    {
        TempDir::write($this->root . '/src/Old.php', "<?php\n");
        TempDir::write($this->root . '/src/New.php', "<?php\n");
        touch($this->root . '/src/Old.php', time() - 3600);

        // Old's ctime is recent too (it was just written): read at the start, as the tree listing
        // hands it over; New is not listed, so it is first read after the tests began.
        $hashes = new FileHashes($this->root);
        $hashes->markRunStart(['src/Old.php']);

        $hashes->of('src/New.php');

        self::assertTrue($hashes->stable('src/Old.php'), 'read before the tests ran, unchanged since');
        self::assertFalse($hashes->stable('src/New.php'), 'changed in the second the tests started: cannot tell');
    }
}
