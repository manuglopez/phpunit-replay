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
 * The git backend is resolved by name at runtime rather than referenced statically: it is
 * an independent unit and a build without it must still start with a warning instead of a
 * fatal error.
 */
final class RemoteCacheFactory
{
    /** @var string */
    private const GIT_BACKEND = 'Manuglopez\\Replay\\Cache\\Remote\\GitRemoteCache';

    public static function fromConfig(Config $config, string $stateDir): RemoteCache
    {
        $remote = $config->remote === null ? '' : trim($config->remote);

        if ($remote === '') {
            return new NullRemoteCache();
        }

        if (self::looksLikeGit($remote)) {
            return self::git($config, $stateDir);
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

    private static function git(Config $config, string $stateDir): RemoteCache
    {
        $factory = [self::GIT_BACKEND, 'fromConfig'];

        if (class_exists(self::GIT_BACKEND) && is_callable($factory)) {
            $cache = $factory($config, $stateDir);

            if ($cache instanceof RemoteCache) {
                return $cache;
            }
        }

        Warnings::warn('git remote backend not available');

        return new NullRemoteCache();
    }
}
