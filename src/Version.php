<?php

declare(strict_types=1);

namespace Manuglopez\Replay;

use Composer\InstalledVersions;
use OutOfBoundsException;

/**
 * Package version, shown by `phpunit-replay --version` and stamped into every graph's
 * `generator` field (`Cache\Graph::encode()`).
 *
 * Derived from Composer's runtime API rather than written here, because a hand-written
 * constant was wrong for eight consecutive releases: it still read `0.1.0-dev` in v0.8.0, so
 * every graph ever written — including every one published to a shared remote cache — claimed
 * a generator it was not. That field exists to answer "which version recorded this baseline?",
 * which is exactly the question asked once a graph turns out to be contaminated, and v0.8.0
 * shipped on precisely that premise. A value that cannot go stale is worth more here than a
 * tidy one.
 *
 * Returns what Composer reports, verbatim and unmassaged: `v0.8.1` for a tagged install,
 * `dev-main` for a checkout of this repository itself. `getReference()` is deliberately not
 * appended — for the root package Composer reports the reference recorded when the autoloader
 * was last dumped, not the working tree's HEAD, so it is stale exactly where a commit id would
 * be the only useful part.
 */
final class Version
{
    /**
     * Reported when Composer's runtime API cannot describe this package: a hand-rolled
     * autoloader, or a PHAR built without the installed-versions map.
     *
     * Deliberately not a version number. An honest "unknown" beats a stale digit, which is
     * the failure this class exists to end — a fallback that has to be remembered is the same
     * fallback that was forgotten eight times.
     */
    public const UNKNOWN = 'unknown';

    private const PACKAGE = 'manuglopez/phpunit-replay';

    public static function id(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return self::UNKNOWN;
        }

        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (OutOfBoundsException) {
            // The package is not in the installed map — a partial or hand-assembled vendor
            // tree. Not an error worth propagating from a version accessor.
            return self::UNKNOWN;
        }

        return $version ?? self::UNKNOWN;
    }
}
