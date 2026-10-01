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

        self::assertSame([], $this->remembered(), 'its ctime is this second: racily clean, never cached');
    }

    public function test_a_remembered_hash_is_reused_only_while_the_stat_is_unchanged(): void
    {
        TempDir::write($this->root . '/src/A.php', "<?php\nfinal class A {}\n");
        // The file's ctime has to be two seconds older than the read, by the file system's own
        // clock, which the cache probes: waited for, not faked.
        sleep(3);
        $later = null;

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

    public function test_a_process_clock_ahead_of_the_file_system_does_not_make_a_fresh_file_look_old(): void
    {
        // A network file system whose server runs behind this machine: by this process's clock
        // the file was changed long ago, by the file system's it was changed just now.
        TempDir::write($this->root . '/src/A.php', "<?php\nfinal class A {}\n");
        $ahead = static fn (): int => time() + 60;

        $hashes = new FileHashes($this->root, $this->cache, $ahead);
        $hashes->of('src/A.php');
        $hashes->save();

        self::assertSame([], $this->remembered(), 'racily clean by the file system\'s clock: not remembered');
        self::assertSame([], glob($this->root . '/.phpunit-replay-clock-*') ?: [], 'the clock probe leaves nothing behind');
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

    public function test_a_file_rewritten_with_the_same_bytes_during_the_run_is_stable(): void
    {
        // A test that rewrites its own fixture must not unstamp every test the fixture's watch
        // pattern maps to: stability is the content, not the timestamps.
        TempDir::write($this->root . '/tests/Fixtures/data.json', "{}\n");
        touch($this->root . '/tests/Fixtures/data.json', time() - 100);

        $hashes = new FileHashes($this->root);
        $hashes->of('tests/Fixtures/data.json');
        $hashes->markRunStart(['tests/Fixtures/data.json']);

        TempDir::write($this->root . '/tests/Fixtures/data.json', "{}\n");
        self::assertTrue($hashes->stable('tests/Fixtures/data.json'));

        $other = new FileHashes($this->root);
        $other->of('tests/Fixtures/data.json');
        $other->markRunStart(['tests/Fixtures/data.json']);
        TempDir::write($this->root . '/tests/Fixtures/data.json', "{\"changed\": true}\n");
        self::assertFalse($other->stable('tests/Fixtures/data.json'), 'the content really moved');
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

    public function test_the_clock_probe_never_writes_into_the_project_root(): void
    {
        $project = TempDir::make('file-hashes-project');
        $stateDir = $this->root . '/state';

        try {
            TempDir::write($project . '/src/A.php', "<?php\nfinal class A {}\n");
            // Any entry created or removed in the root would move its mtime to now.
            touch($project, time() - 100);
            clearstatcache();
            $listing = scandir($project);
            $mtime = filemtime($project);

            foreach ([new FileHashes($project, $stateDir . '/content-hashes.json'), new FileHashes($project)] as $hashes) {
                $hashes->of('src/A.php');
                $hashes->markRunStart(['src/A.php']);
                $hashes->stable('src/A.php');
                $hashes->save();
            }

            clearstatcache();
            self::assertSame($listing, scandir($project), 'nothing left in the root');
            self::assertSame($mtime, filemtime($project), 'nothing even created and removed in the root');

            // It probed in the state directory, on the same device, and kept the verdict.
            $data = json_decode((string) file_get_contents($stateDir . '/content-hashes.json'), true);
            self::assertIsArray($data);
            self::assertSame([(string) stat($project)['dev'] => true], (array) ($data['devices'] ?? []));
            self::assertSame([], glob($stateDir . '/' . FileHashes::PROBE_PREFIX . '*') ?: []);
        } finally {
            TempDir::remove($project);
        }
    }

    public function test_an_entry_on_a_device_whose_ctime_can_be_forged_is_never_served(): void
    {
        TempDir::write($this->root . '/src/A.php', "<?php\nfinal class A {}\n");
        sleep(3);

        $first = new FileHashes($this->root, $this->cache);
        $first->of('src/A.php');
        $first->save();

        $data = json_decode((string) file_get_contents($this->cache), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('src/A.php', $data['entries']);
        $data['entries']['src/A.php'][5] = 'forged';
        // As a FAT, exFAT or sshfs mount would have been judged on its first probe.
        $data['devices'] = [(string) stat($this->root)['dev'] => false];
        file_put_contents($this->cache, json_encode($data));

        self::assertSame(ContentHash::of($this->root . '/src/A.php'), (new FileHashes($this->root, $this->cache))->of('src/A.php'));
    }

    public function test_probe_files_are_recognised_wherever_they_are(): void
    {
        self::assertTrue(FileHashes::isProbeFile('.phpunit-replay-clock-0a1b2c3d'));
        self::assertTrue(FileHashes::isProbeFile('sub/.phpunit-replay-clock-0a1b2c3d'));
        self::assertFalse(FileHashes::isProbeFile('.phpunit-replay.xml'));
    }

    /** @return array<string, mixed> the entries the cache file holds, none when there is none */
    private function remembered(): array
    {
        $data = json_decode((string) @file_get_contents($this->cache), true);

        return is_array($data) && is_array($data['entries'] ?? null) ? $data['entries'] : [];
    }
}
