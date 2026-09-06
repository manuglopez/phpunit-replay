<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Support\Paths;

/**
 * Resolves the local state directory a project's cache lives in (SPEC §4.1).
 */
final class StateDirectory
{
    /**
     * $configured: absolute, or relative to $projectRoot, or null. When null: `$HOME` (or
     * `$USERPROFILE`) + `/.phpunit-replay/<ProjectKey::for($projectRoot)>`; when there is no
     * usable home directory, `<projectRoot>/.phpunit-replay`.
     */
    public static function resolve(?string $configured, string $projectRoot): string
    {
        if ($configured !== null && $configured !== '') {
            $normalized = Paths::normalizeSeparators($configured);

            return Paths::isAbsolute($normalized) ? $normalized : Paths::join($projectRoot, $normalized);
        }

        $home = self::homeDir();

        if ($home === null) {
            return Paths::join($projectRoot, '.phpunit-replay');
        }

        return Paths::join($home, '.phpunit-replay/' . ProjectKey::for($projectRoot));
    }

    private static function homeDir(): ?string
    {
        foreach (['HOME', 'USERPROFILE'] as $key) {
            $value = getenv($key);

            if (is_string($value) && $value !== '' && is_dir($value)) {
                return rtrim(Paths::normalizeSeparators($value), '/');
            }
        }

        return null;
    }
}
