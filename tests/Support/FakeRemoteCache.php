<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Support;

use Manuglopez\Replay\Cache\Remote\RemoteCache;

/**
 * A fully in-memory {@see RemoteCache} whose `put()` and `end()` outcomes are independently
 * controllable — what `ObjectStore`'s `confirmPublished()` regression tests need that no real
 * backend can give directly: `GitRemoteCache`'s own "staged, not yet pushed" shape, where
 * every `put()` in a run succeeds (the local mirror write does) and only `end()` can still
 * fail (a rejected push, after retries). `put()` here plays the same role as `GitRemoteCache`
 * writing into its mirror working tree: it always succeeds and is immediately visible to
 * `get()`/`has()`/`keys()`, regardless of whether a later `end()` reports a failure.
 */
final class FakeRemoteCache implements RemoteCache
{
    /** @var array<string, string> */
    public array $stored = [];

    /** Set before end() to make it report a failed push (e.g. a rejected git push). */
    public ?string $endError = null;

    public int $endCalls = 0;

    private ?string $lastError = null;

    public function begin(): void
    {
        $this->lastError = null;
    }

    public function end(): void
    {
        $this->endCalls++;
        $this->lastError = $this->endError;
    }

    public function get(string $key): ?string
    {
        return $this->stored[$key] ?? null;
    }

    public function put(string $key, string $body): bool
    {
        $this->stored[$key] = $body;

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->stored[$key]);
    }

    /** @return list<string> */
    public function keys(string $prefix): array
    {
        return array_values(array_filter(
            array_keys($this->stored),
            static fn (string $key): bool => str_starts_with($key, $prefix),
        ));
    }

    public function delete(string $key): bool
    {
        unset($this->stored[$key]);

        return true;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
