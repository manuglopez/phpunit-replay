<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache\Remote;

use Manuglopez\Replay\Config;
use Manuglopez\Replay\Console\Runner\Warnings;

/**
 * Picks the backend a `remote` setting asks for (docs/INTERNALS.md "Phase 3 contracts —
 * distribution"):
 *
 *   null / ''                                       → {@see NullRemoteCache} (remote disabled)
 *   git+ssh://…, git+https://…, ssh://…,
 *   git@host:org/repo, anything ending in `.git`    → GitRemoteCache
 *   file:///path, /absolute/path                    → {@see FilesystemRemoteCache}
 *   http(s)://host/prefix/                          → {@see HttpRemoteCache}
 *   anything else                                   → NullRemoteCache + a warning
 *
 * The git backend ({@see GitRemoteCache}) ships in the same package, so it is referenced
 * statically like every other backend.
 */
final class RemoteCacheFactory
{
    public static function fromConfig(Config $config, string $stateDir): RemoteCache
    {
        $remote = $config->remote === null ? '' : trim($config->remote);

        if ($remote === '') {
            return new NullRemoteCache();
        }

        if (self::looksLikeGit($remote)) {
            return GitRemoteCache::fromConfig($config, $stateDir);
        }

        $filesystem = FilesystemRemoteCache::fromRemote($remote);

        if ($filesystem !== null) {
            return $filesystem;
        }

        $http = HttpRemoteCache::fromRemote($remote, $config->remoteToken);

        if ($http !== null) {
            return $http;
        }

        Warnings::warn('remote: unsupported remote "' . $remote . '"; continuing without a remote cache');

        return new NullRemoteCache();
    }

    /**
     * `git+ssh://`, `git+https://`, `ssh://`, the scp-like `git@host:org/repo` form, or any
     * URL whose path ends in `.git` (SPEC.md §9, DECISIONS.md D-038).
     */
    public static function looksLikeGit(string $remote): bool
    {
        $remote = trim($remote);

        if ($remote === '') {
            return false;
        }

        if (stripos($remote, 'git+') === 0 || stripos($remote, 'ssh://') === 0 || stripos($remote, 'git://') === 0) {
            return true;
        }

        if (preg_match('#^[\w.-]+@[\w.-]+:#', $remote) === 1) {
            return true;
        }

        return str_ends_with(rtrim($remote, '/'), '.git');
    }
}
