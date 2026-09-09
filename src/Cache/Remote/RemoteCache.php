<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache\Remote;

/**
 * Content-addressed remote store shared between machines (SPEC.md §9, docs/INTERNALS.md
 * "Phase 3 contracts"). Keys: `graph/<project-key>/<branch>.json` and
 * `objects/<yyyy-mm>/<k>.json`. Implementations never throw for environmental failures:
 * they report through {@see lastError()} and the caller warns and continues locally.
 *
 * Bug fix — the contract `put()`/`delete()` returning `true` actually make: it means
 * "accepted", not "durably in the remote". For `FilesystemRemoteCache`/`HttpRemoteCache`
 * those are the same thing (each write is synchronous). For `GitRemoteCache` they are not:
 * `put()`/`delete()` only stage the local mirror's working tree, and the write does not
 * reach the shared remote until `end()` completes with `lastError() === null` (a push can
 * still fail there, after every earlier call already returned `true`). A caller that keeps
 * its own local record of "this key is now in the remote" — {@see
 * \Manuglopez\Replay\Cache\Remote\ObjectStore}'s publish marker is the one this package has —
 * must gate that record on `end()`'s outcome, never on `put()`'s return value alone; see
 * {@see \Manuglopez\Replay\Cache\Remote\ObjectStore::confirmPublished()}.
 */
interface RemoteCache
{
    /** Called once per run before the first read; may fetch/refresh a local mirror. */
    public function begin(): void;

    /** Called once per run after the last write; may push/flush. */
    public function end(): void;

    public function get(string $key): ?string;

    /**
     * @return bool false when the write failed (see lastError()); true means "accepted" —
     *         see this interface's own docblock for why that is not always "durable" yet
     */
    public function put(string $key, string $body): bool;

    public function has(string $key): bool;

    /** @return list<string> keys under a prefix (used by `prune --remote`) */
    public function keys(string $prefix): array;

    /** @return bool same caveat as {@see self::put()}: true means "accepted", not necessarily pushed yet */
    public function delete(string $key): bool;

    /** 'null' | 'file' | 'http' | 'git' */
    public function name(): string;

    public function lastError(): ?string;
}
