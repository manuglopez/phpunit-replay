<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Support\Paths;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/Storage.php
 *
 * Port of `Storage::projectKey()` / `Storage::originIdentity()`. Two deviations from Pest:
 *
 * - `.git` may be a *file* rather than a directory when `$projectRoot` is a git worktree
 *   (its content is `gitdir: <path>/.git/worktrees/<name>`). This class follows that pointer,
 *   then follows the worktree's `commondir` file to the main repository's git directory, so
 *   worktrees of the same repository share the same project key as the main checkout.
 * - `normalizeOriginUrl()` adds a third pattern for `file://` remotes with no host
 *   (`file:///abs/path`), which Pest's two original patterns do not match (they require a
 *   non-empty host segment) and would otherwise fall through to a raw, unnormalised lowercase
 *   string.
 */
final class ProjectKey
{
    /** slug(basename(root)) . '-' . substr(sha256(originIdentity ?? realpath), 0, 16) */
    public static function for(string $projectRoot): string
    {
        $origin = self::originIdentity($projectRoot);
        $realpath = @realpath($projectRoot);
        $input = $origin ?? ($realpath === false ? $projectRoot : $realpath);

        $hash = substr(hash('sha256', $input), 0, 16);
        $slug = self::slug(basename($projectRoot));

        return $slug . '-' . $hash;
    }

    /** "github.com/org/repo" lowercased, no scheme/user/.git. Null when there is no origin remote. */
    public static function originIdentity(string $projectRoot): ?string
    {
        $url = self::rawOriginUrl($projectRoot);

        return $url === null ? null : self::normalizeOriginUrl($url);
    }

    public static function normalizeOriginUrl(string $url): string
    {
        $url = trim($url);

        // git@host:org/repo(.git)
        if (preg_match('#^[\w.-]+@([\w.-]+):([\w./-]+?)(?:\.git)?/?$#', $url, $m) === 1) {
            return strtolower($m[1] . '/' . $m[2]);
        }

        // scheme://[user@]host[:port]/org/repo(.git) — https, ssh, git
        if (preg_match('#^[a-z]+://(?:[^@/]+@)?([^/:]+)(?::\d+)?/([\w./-]+?)(?:\.git)?/?$#i', $url, $m) === 1) {
            return strtolower($m[1] . '/' . $m[2]);
        }

        // file:///absolute/path (no host)
        if (preg_match('#^file://(/[\w./-]+?)(?:\.git)?/?$#i', $url, $m) === 1) {
            return strtolower(ltrim($m[1], '/'));
        }

        return strtolower($url);
    }

    private static function rawOriginUrl(string $projectRoot): ?string
    {
        $config = self::gitConfigPath($projectRoot);

        if ($config === null || ! is_file($config)) {
            return null;
        }

        $raw = @file_get_contents($config);

        if ($raw === false) {
            return null;
        }

        if (preg_match('/\[remote "origin"\][^\[]*?url\s*=\s*(\S+)/s', $raw, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    /** Resolves the config file, following worktree indirection ('.git' as a file) if needed. */
    private static function gitConfigPath(string $projectRoot): ?string
    {
        $dotGit = $projectRoot . '/.git';

        if (is_dir($dotGit)) {
            return $dotGit . '/config';
        }

        if (! is_file($dotGit)) {
            return null;
        }

        $contents = @file_get_contents($dotGit);

        if ($contents === false) {
            return null;
        }

        if (preg_match('/^gitdir:\s*(.+)$/m', trim($contents), $m) !== 1) {
            return null;
        }

        $gitDir = self::resolveAgainst($projectRoot, trim($m[1]));
        $commonDirFile = $gitDir . '/commondir';

        if (is_file($commonDirFile)) {
            $commonDirContents = @file_get_contents($commonDirFile);

            if ($commonDirContents !== false) {
                $commonDir = self::resolveAgainst($gitDir, trim($commonDirContents));

                return $commonDir . '/config';
            }
        }

        return $gitDir . '/config';
    }

    /** Resolves `$path` against `$base` when it is relative, realpath()-ing the result when possible. */
    private static function resolveAgainst(string $base, string $path): string
    {
        $joined = Paths::isAbsolute($path) ? $path : $base . '/' . $path;
        $resolved = @realpath($joined);

        return $resolved === false ? rtrim(Paths::normalizeSeparators($joined), '/') : Paths::normalizeSeparators($resolved);
    }

    private static function slug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }
}
