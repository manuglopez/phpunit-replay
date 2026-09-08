<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Support;

/**
 * A stream wrapper that counts how many times a path is opened and can hand back a
 * different body on each open.
 *
 * The point is to observe something no real file can show deterministically: whether a
 * collaborator reads a path once or twice. `Analysis\FactsCache` used to hash the file with
 * `ContentHash::of()` and then let `DeclarationScanner::scan()` open it again, so a file
 * rewritten in between was stored with version B's facts under version A's hash. With one
 * read there is no window, and {@see self::$opens} is how a test says so.
 */
final class CountingStream
{
    public static int $opens = 0;

    /** @var list<string> body for open #0, #1, …; the last one repeats */
    public static array $versions = [''];

    /** @var resource|null set by PHP */
    public $context;

    private string $body = '';

    private int $position = 0;

    public static function register(string $scheme = 'counting'): void
    {
        self::$opens = 0;
        self::$versions = [''];

        if (in_array($scheme, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($scheme);
        }

        stream_wrapper_register($scheme, self::class);
    }

    public static function unregister(string $scheme = 'counting'): void
    {
        if (in_array($scheme, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($scheme);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->body = self::$versions[min(self::$opens, count(self::$versions) - 1)];
        $this->position = 0;
        self::$opens++;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->body);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $this->position = $offset;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->body), 'mode' => 0o100644];
    }

    /** @return array<int|string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['size' => strlen(self::$versions[0]), 'mode' => 0o100644];
    }
}
