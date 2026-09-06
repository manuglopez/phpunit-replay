<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache\Remote;

/**
 * Content-addressed remote store shared between machines (SPEC.md §9, docs/INTERNALS.md
 * "Phase 3 contracts"). Keys: `graph/<project-key>/<branch>.json` and
 * `objects/<yyyy-mm>/<k>.json`. Implementations never throw for environmental failures:
 * they report through {@see lastError()} and the caller warns and continues locally.
 */
interface RemoteCache
{
    /** Called once per run before the first read; may fetch/refresh a local mirror. */
    public function begin(): void;

    /** Called once per run after the last write; may push/flush. */
    public function end(): void;

    public function get(string $key): ?string;

    /** @return bool false when the write failed (see lastError()) */
    public function put(string $key, string $body): bool;

    public function has(string $key): bool;

    /** @return list<string> keys under a prefix (used by `prune --remote`) */
    public function keys(string $prefix): array;

    public function delete(string $key): bool;

    /** 'null' | 'file' | 'http' | 'git' */
    public function name(): string;

    public function lastError(): ?string;
}
