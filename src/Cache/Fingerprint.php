<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Coverage\CoverageFormat;
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
 *
 * ## The two buckets
 *
 * `structural` describes the PROJECT and feeds the content key (`Cache\ContentKey`): a change
 * to any of it makes the whole graph unusable. `environmental` describes the MACHINE and only
 * invalidates the cached RESULTS (`RunPipeline::reconcile()` / `ReplayState`): a change there
 * leaves the dependency edges standing but throws away the recorded outcomes, because those
 * were observed under conditions that no longer hold.
 *
 * `SCHEMA_VERSION` is deliberately NOT the knob for an environmental change: it sits in the
 * structural bucket, `canonicalStructural()` feeds it into every content key, and bumping it
 * therefore discards every graph on every machine. It describes the shape of this array, and
 * only moves when that shape does.
 *
 * ## `static_declaration_edges`
 *
 * The `static_declaration_edges` config flag changes what an edge *means*
 * (`Analysis\StaticEdges`), so a graph recorded with it on and a graph recorded with it off
 * must never be mixed: the edges are not comparable, and neither are the keys computed from
 * them. That makes it `structural`, not `environmental` — `environmental` only throws away
 * the recorded *results* and keeps the edges standing, which is precisely the wrong half.
 *
 * It is added to the bucket ONLY when the flag is on. Adding it unconditionally, even as
 * `false`, would change `canonicalStructural()` for every project on earth and invalidate
 * every existing cache on every machine the moment this version shipped — the same blast
 * radius as bumping `SCHEMA_VERSION`, for a feature nobody asked for yet. Absent-vs-present
 * still drifts in both directions (`detectDrift()` walks both sides), so flipping the flag
 * discards the graph deliberately, in exactly one direction at a time.
 */
final readonly class Fingerprint
{
    /** @var int */
    public const SCHEMA_VERSION = 1;

    /** @var array<string, string> structural key => project-relative file */
    private const STRUCTURAL_FILES = [
        'composer_lock' => 'composer.lock',
        'phpunit_xml' => 'phpunit.xml',
        'phpunit_xml_dist' => 'phpunit.xml.dist',
        'replay_config' => 'phpunit-replay.php',
    ];

    /**
     * @return array{structural: array<string, bool|int|string|null>, environmental: array<string, string|null>}
     */
    public static function compute(string $projectRoot, string $driver, bool $staticDeclarationEdges = false): array
    {
        $structural = ['schema' => self::SCHEMA_VERSION];

        foreach (self::STRUCTURAL_FILES as $key => $relative) {
            $structural[$key] = self::trackedHash($projectRoot, $relative);
        }

        if ($staticDeclarationEdges) {
            $structural['static_declaration_edges'] = true;
        }

        return [
            'structural' => $structural,
            'environmental' => [
                'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'driver' => $driver,
                'os' => PHP_OS_FAMILY,
                // A recorded result is only replayable while its stored coverage snapshot is
                // still readable, and php-code-coverage changes both the `--coverage-php`
                // serialization format and the shape of the coverage data itself between
                // majors ({@see CoverageFormat}). Without this key nothing noticed: a cache
                // recorded under one format was read back under another as an empty or
                // unreadable snapshot, i.e. as silently missing coverage.
                'coverage' => CoverageFormat::id(),
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
