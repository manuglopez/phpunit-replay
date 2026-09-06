<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/Fingerprint.php
 *
 * Ported structural file list per SPEC §4.5 (Pest's vite/js/package-lock handling is dropped
 * entirely — this package does not track frontend build tooling).
 *
 * Trackedness check: a structural file is only hashed when it is tracked by git. This is
 * checked with `git ls-files --error-unmatch <file>` (symfony/process, 5s timeout) rather
 * than Symfony Finder's `ignoreVCSIgnored()` (Pest's original approach): Finder only excludes
 * files matched by `.gitignore`, so a brand-new file that was never `git add`ed but also isn't
 * gitignored would still be treated as "tracked" — the opposite of what SPEC §4.5 requires.
 * When the project root is not a git repository at all (no `.git` directory or file), every
 * file is treated as tracked, since there is no index to distrust.
 */
final readonly class Fingerprint
{
    public const int SCHEMA_VERSION = 1;

    /** @var array<string, string> structural key => project-relative file */
    private const array STRUCTURAL_FILES = [
        'composer_lock' => 'composer.lock',
        'phpunit_xml' => 'phpunit.xml',
        'phpunit_xml_dist' => 'phpunit.xml.dist',
        'replay_config' => 'phpunit-replay.php',
    ];

    /**
     * @return array{structural: array<string, int|string|null>, environmental: array<string, string|null>}
     */
    public static function compute(string $projectRoot, string $driver): array
    {
        $structural = ['schema' => self::SCHEMA_VERSION];

        foreach (self::STRUCTURAL_FILES as $key => $relative) {
            $structural[$key] = self::trackedHash($projectRoot, $relative);
        }

        return [
            'structural' => $structural,
            'environmental' => [
                'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'driver' => $driver,
                'os' => PHP_OS_FAMILY,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function structuralMatches(array $a, array $b): bool
    {
        $aStructural = self::bucket($a, 'structural');
        $bStructural = self::bucket($b, 'structural');

        ksort($aStructural);
        ksort($bStructural);

        return $aStructural === $bStructural;
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $current
     * @return list<string>
     */
    public static function structuralDrift(array $stored, array $current): array
    {
        return self::detectDrift(
            self::bucket($stored, 'structural'),
            self::bucket($current, 'structural'),
            'schema',
        );
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $current
     * @return list<string>
     */
    public static function environmentalDrift(array $stored, array $current): array
    {
        return self::detectDrift(
            self::bucket($stored, 'environmental'),
            self::bucket($current, 'environmental'),
        );
    }

    /**
     * Canonical JSON of the structural bucket (ksort, JSON_UNESCAPED_SLASHES) — input to the content key.
     *
     * @param array<string, mixed> $fingerprint
     */
    public static function canonicalStructural(array $fingerprint): string
    {
        $structural = self::bucket($fingerprint, 'structural');
        ksort($structural);

        return json_encode($structural, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return list<string>
     */
    private static function detectDrift(array $a, array $b, ?string $skipKey = null): array
    {
        $drifts = [];

        foreach ($a as $key => $value) {
            if ($key === $skipKey) {
                continue;
            }

            if (($b[$key] ?? null) !== $value) {
                $drifts[] = $key;
            }
        }

        foreach ($b as $key => $value) {
            if ($key === $skipKey) {
                continue;
            }

            if (! array_key_exists($key, $a) && $value !== null) {
                $drifts[] = $key;
            }
        }

        return array_values(array_unique($drifts));
    }

    /**
     * @param array<string, mixed> $fingerprint
     * @return array<string, mixed>
     */
    private static function bucket(array $fingerprint, string $key): array
    {
        $raw = $fingerprint[$key] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $normalised = [];

        foreach ($raw as $k => $v) {
            if (is_string($k)) {
                $normalised[$k] = $v;
            }
        }

        return $normalised;
    }

    private static function trackedHash(string $projectRoot, string $relative): ?string
    {
        $absolute = $projectRoot . '/' . $relative;

        if (! is_file($absolute) || ! self::isTrackedByGit($projectRoot, $relative)) {
            return null;
        }

        return ContentHash::of($absolute);
    }

    private static function isTrackedByGit(string $projectRoot, string $relative): bool
    {
        if (! is_dir($projectRoot . '/.git') && ! is_file($projectRoot . '/.git')) {
            return true;
        }

        $process = new Process(['git', 'ls-files', '--error-unmatch', $relative], $projectRoot);
        $process->setTimeout(5.0);

        try {
            $process->run();
        } catch (ExceptionInterface) {
            return false;
        }

        return $process->isSuccessful();
    }
}
