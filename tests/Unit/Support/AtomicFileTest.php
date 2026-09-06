<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Support;

use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class AtomicFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::make('atomic-file');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testWriteCreatesParentDirectories(): void
    {
        $path = $this->dir . '/nested/deeper/file.json';

        self::assertTrue(AtomicFile::write($path, '{"a":1}'));
        self::assertSame('{"a":1}', file_get_contents($path));
    }

    public function testWriteLeavesNoTmpFileBehind(): void
    {
        $path = $this->dir . '/file.json';

        self::assertTrue(AtomicFile::write($path, 'content'));

        $entries = scandir($this->dir);
        self::assertIsArray($entries);

        $tmpFiles = array_filter($entries, static fn (string $entry): bool => str_ends_with($entry, '.tmp'));

        self::assertSame([], array_values($tmpFiles));
    }

    public function testWriteOverwritesExistingFile(): void
    {
        $path = $this->dir . '/file.txt';

        self::assertTrue(AtomicFile::write($path, 'first'));
        self::assertTrue(AtomicFile::write($path, 'second'));
        self::assertSame('second', file_get_contents($path));
    }

    public function testReadReturnsNullForMissingFile(): void
    {
        self::assertNull(AtomicFile::read($this->dir . '/does-not-exist.json'));
    }

    public function testReadReturnsWrittenContent(): void
    {
        $path = $this->dir . '/file.txt';
        AtomicFile::write($path, 'hello');

        self::assertSame('hello', AtomicFile::read($path));
    }
}
