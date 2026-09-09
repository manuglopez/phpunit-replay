<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Registry;
use Throwable;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/SourceScope.php
 */
final class SourceScope
{
    /** @var array<string, bool> */
    private array $containsCache = [];

    private const TOP_LEVEL_NOISE = [
        'vendor',
        'node_modules',
        '.git',
        '.idea',
        '.vscode',
        '.github',
        '.phpunit.cache',
        '.cache',
        '.phpunit-replay',
    ];

    private const NESTED_NOISE = [
        'storage/framework',
        'storage/logs',
        'bootstrap/cache',
    ];

    /**
     * @param list<string> $includes Absolute, normalised directory paths.
     * @param list<string> $excludes Absolute, normalised directory paths.
     */
    public function __construct(
        private readonly array $includes,
        private readonly array $excludes,
    ) {
    }

    public static function fromProjectRoot(string $projectRoot, ?Configuration $configuration = null): self
    {
        $phpunitIncludes = [];
        $phpunitExcludes = [];

        $configuration ??= self::currentConfiguration();

        if ($configuration !== null) {
            $source = $configuration->source();

            foreach ($source->includeDirectories() as $dir) {
                $phpunitIncludes[] = self::normalise($dir->path());
            }

            foreach ($source->excludeDirectories() as $dir) {
                $phpunitExcludes[] = self::normalise($dir->path());
            }
        }

        $rootIncludes = self::topLevelProjectDirs($projectRoot);

        $includes = array_values(array_unique([...$phpunitIncludes, ...$rootIncludes]));
        $excludes = array_values(array_unique([
            ...$phpunitExcludes,
            ...self::nestedNoiseDirs($projectRoot),
        ]));

        if ($includes === []) {
            $includes = [self::normalise($projectRoot)];
        }

        return new self($includes, $excludes);
    }

    /**
     * True when $absoluteFile lies inside one of the fixed directories this class always
     * excludes (`bootstrap/cache`, `storage/framework`, `storage/logs`), resolved under
     * $projectRoot, regardless of any PHPUnit `<source>` configuration or `<source>
     * <exclude>` entries. A narrow, dependency-free check for a caller that has a project
     * root but no reason to construct (and thread through) a full scope instance — see
     * `Laravel\BladeTracker`'s belt-and-braces fallback for when `config('view.compiled')`
     * cannot be read: it needs "is this the framework's own noise directory", not the full
     * includes/excludes decision {@see self::contains()} makes.
     */
    public static function isNestedNoisePath(string $projectRoot, string $absoluteFile): bool
    {
        $real = @realpath($absoluteFile);
        $candidate = self::normalise($real === false ? $absoluteFile : $real);

        foreach (self::nestedNoiseDirs($projectRoot) as $dir) {
            if ($candidate === $dir || str_starts_with($candidate, $dir . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    public function contains(string $absoluteFile): bool
    {
        if (isset($this->containsCache[$absoluteFile])) {
            return $this->containsCache[$absoluteFile];
        }

        $real = @realpath($absoluteFile);
        $candidate = $real === false ? $absoluteFile : $real;
        $candidate = self::normalise($candidate);

        foreach ($this->excludes as $excluded) {
            if ($this->startsWithDir($candidate, $excluded)) {
                return $this->containsCache[$absoluteFile] = false;
            }
        }

        foreach ($this->includes as $included) {
            if ($this->startsWithDir($candidate, $included)) {
                return $this->containsCache[$absoluteFile] = true;
            }
        }

        return $this->containsCache[$absoluteFile] = false;
    }

    /** @return list<string> */
    public function includes(): array
    {
        return $this->includes;
    }

    private static function currentConfiguration(): ?Configuration
    {
        try {
            return Registry::get();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function topLevelProjectDirs(string $projectRoot): array
    {
        $entries = @scandir($projectRoot);

        if ($entries === false) {
            return [];
        }

        $out = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (in_array($entry, self::TOP_LEVEL_NOISE, true)) {
                continue;
            }

            if ($entry !== '' && $entry[0] === '.') {
                continue;
            }

            $abs = $projectRoot . DIRECTORY_SEPARATOR . $entry;

            if (! is_dir($abs)) {
                continue;
            }

            $out[] = self::normalise(@realpath($abs) ?: $abs);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function nestedNoiseDirs(string $projectRoot): array
    {
        $out = [];

        foreach (self::NESTED_NOISE as $relative) {
            $abs = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $out[] = self::normalise(@realpath($abs) ?: $abs);
        }

        return $out;
    }

    private static function normalise(string $path): string
    {
        return rtrim($path, '/\\');
    }

    private function startsWithDir(string $candidate, string $dir): bool
    {
        if ($candidate === $dir) {
            return true;
        }

        return str_starts_with($candidate, $dir . DIRECTORY_SEPARATOR);
    }
}
