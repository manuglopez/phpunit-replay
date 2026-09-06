<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache\Remote;

/** No remote configured: every read misses, every write is dropped. */
final class NullRemoteCache implements RemoteCache
{
    public function begin(): void
    {
    }

    public function end(): void
    {
    }

    public function get(string $key): ?string
    {
        return null;
    }

    public function put(string $key, string $body): bool
    {
        return false;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function keys(string $prefix): array
    {
        return [];
    }

    public function delete(string $key): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'null';
    }

    public function lastError(): ?string
    {
        return null;
    }
}
