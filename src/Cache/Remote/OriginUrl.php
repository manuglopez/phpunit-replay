<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache\Remote;

use Manuglopez\Replay\Cache\ProjectKey;
use Manuglopez\Replay\Support\Paths;

/**
 * A project's `origin` URL, split into the parts needed to propose a *sibling* repository next
 * to it — the cache repository `Console\Commands\RemoteInitCommand` sets up (DECISIONS.md
 * D-038, D-045).
 *
 * Deliberately not {@see ProjectKey::normalizeOriginUrl()}: that one folds an origin down to
 * the single lowercased `host/owner/name` identity the shared cache keys itself by, which is
 * exactly what a key wants and exactly what a *URL* cannot be rebuilt from — it drops the
 * transport (ssh vs https), the user, the port and the original case, all of which a proposed
 * sibling has to keep if git is to reach it with the credentials the developer already has.
 * The identity is still what {@see self::identity()} reports, by calling that same method, so
 * the two can never drift.
 *
 * Accepted forms are the ones {@see GitRemoteCache} documents: `git@host:owner/name.git`,
 * `https://host/owner/name.git`, `ssh://[user@]host[:port]/owner/name(.git)`, the `git+`
 * prefixed variants of those, and the local `file:///path/name.git` / `/path/name.git` bare
 * repository forms. Every path segment before the last one stays in `owner`, so a GitLab
 * subgroup (`group/subgroup/name`) survives instead of being flattened into the name.
 *
 * Never throws: an origin this cannot make sense of is a `null` from {@see self::parse()} and
 * the caller explains itself (docs/INTERNALS.md conventions).
 */
final readonly class OriginUrl
{
    private function __construct(
        /** The origin URL as configured, trimmed — never rewritten. */
        public string $url,
        /** `''` for a local path or a hostless `file://` URL. */
        public string $host,
        /** Everything before the last path segment, `''` when there is nothing. */
        public string $owner,
        /** The last path segment, without a `.git` suffix. */
        public string $name,
        /**
         * Everything a URL of this shape has before its `owner/name` path: `git@host:`,
         * `https://host/`, `ssh://git@host:2222/`, `file:///`, `/`. Rebuilding from this
         * rather than from a shape enum is what keeps a port, a user and a `git+`-free
         * scheme intact in {@see self::sibling()}.
         */
        private string $prefix,
    ) {
    }

    public static function parse(string $url): ?self
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // scheme://[user@][host][:port]/owner/name(.git) — https, ssh, git, and the hostless
        // file:/// form. The `git+` prefix this package accepts is stripped here for the same
        // reason GitRemoteCache strips it before handing a URL to git: git does not know it.
        if (preg_match('#^(?:git\+)?([a-z][a-z0-9+.-]*)://(?:([^@/]+)@)?([^/:]*)(?::(\d+))?/(.+)$#i', $url, $match) === 1) {
            $prefix = strtolower($match[1]) . '://'
                . ($match[2] !== '' ? $match[2] . '@' : '')
                . $match[3]
                . ($match[4] !== '' ? ':' . $match[4] : '')
                . '/';

            return self::build($url, $match[3], $prefix, $match[5]);
        }

        // A local bare repository: /path/name.git, or C:/path/name.git. Checked before the
        // scp-like form below, which would otherwise read a Windows drive letter as a host.
        if (Paths::isAbsolute($url)) {
            $normalized = Paths::normalizeSeparators($url);

            if (preg_match('#^([A-Za-z]:/|/)(.*)$#', $normalized, $match) !== 1) {
                return null;
            }

            return self::build($url, '', $match[1], $match[2]);
        }

        // The scp-like [user@]host:owner/name(.git). The lookahead rejects a scheme URL whose
        // own `://` would otherwise read as host `https` plus a path.
        if (preg_match('#^([\w.-]+@)?([\w.-]+):(?!/)(.+)$#', $url, $match) === 1) {
            return self::build($url, $match[2], $match[1] . $match[2] . ':', $match[3]);
        }

        return null;
    }

    /**
     * The same repository under another `owner`/`name` on the same host, over the same
     * transport. Always ends in `.git`, and not for looks: an `https://host/owner/name` with
     * no suffix is routed to {@see HttpRemoteCache} by {@see RemoteCacheFactory::looksLikeGit()},
     * so a proposed cache URL without it would silently configure the wrong backend.
     */
    public function sibling(string $owner, string $name): string
    {
        $path = $owner === '' ? $name : rtrim($owner, '/') . '/' . $name;

        return $this->prefix . $path . '.git';
    }

    /** This same repository, as a URL the git backend is guaranteed to recognise ({@see self::sibling()}). */
    public function canonical(): string
    {
        return $this->sibling($this->owner, $this->name);
    }

    /** `host/owner/name` lowercased: what the shared project key hashes ({@see ProjectKey::shared()}). */
    public function identity(): string
    {
        return ProjectKey::normalizeOriginUrl($this->url);
    }

    private static function build(string $url, string $host, string $prefix, string $path): ?self
    {
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = trim($path, '/');

        if (str_ends_with(strtolower($path), '.git')) {
            $path = rtrim(substr($path, 0, -4), '/');
        }

        if ($path === '') {
            return null;
        }

        $slash = strrpos($path, '/');
        $owner = $slash === false ? '' : substr($path, 0, $slash);
        $name = $slash === false ? $path : substr($path, $slash + 1);

        if ($name === '') {
            return null;
        }

        return new self($url, $host, $owner, $name, $prefix);
    }
}
