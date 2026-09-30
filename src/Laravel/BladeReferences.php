<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use FilesystemIterator;
use Manuglopez\Replay\Support\AtomicFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/Graph.php
 * (`bladeAncestorsFor` and its helpers, lines 1297-1510 of the referenced commit).
 *
 * Static (non-executed) Blade dependency walk: `ancestorsOf('resources/views/partials/x.blade.php', root)`
 * returns every other Blade file that references it, directly or transitively, via
 * `@include`/`@includeIf`/`@includeWhen`/`@includeUnless`/`@extends`/`@component`/`@each`,
 * `view('x')`/`View::make('x')`, or a matching `<x-name` component tag.
 */
final class BladeReferences
{
    /** Bumped whenever {@see self::sourceReferences()} changes what it recognises. */
    private const CACHE_VERSION = 'blade-references@1';

    /**
     * The templates `$roots` reference, directly or transitively, over a
     * {@see self::referenceMap()}: the inverse of {@see self::ancestorsOf()}.
     *
     * @param array<string, list<string>> $map
     * @param list<string> $roots
     * @return array<string, true>
     */
    public static function descendantsOf(array $map, array $roots): array
    {
        $seen = [];
        $queue = $roots;

        while ($queue !== []) {
            $current = array_pop($queue);

            foreach ($map[$current] ?? [] as $child) {
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return $seen;
    }

    /** @return list<string> Project-relative Blade files that statically depend on $bladeRel, directly or transitively. */
    public static function ancestorsOf(string $bladeRel, string $projectRoot): array
    {
        $allBladeFiles = self::allBladeFiles($projectRoot);

        if ($allBladeFiles === []) {
            return [];
        }

        $targets = [$bladeRel => true];
        $ancestors = [];
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($allBladeFiles as $candidate) {
                if (isset($targets[$candidate]) || isset($ancestors[$candidate])) {
                    continue;
                }

                $source = @file_get_contents(rtrim($projectRoot, '/') . '/' . $candidate);

                if ($source === false) {
                    continue;
                }

                foreach (array_keys($targets) as $target) {
                    if (self::sourceReferences($source, $target)) {
                        $ancestors[$candidate] = true;
                        $targets[$candidate] = true;
                        $changed = true;

                        break;
                    }
                }
            }
        }

        return array_keys($ancestors);
    }

    /**
     * {@see self::ancestorsOf()} for several templates at once, reading every template once
     * instead of once per target and per round (`Select\NonEdgeInputs` asks it of every
     * template the graph does not know, on every pass). Same fixpoint, same references, so
     * the same answer per template.
     *
     * @param list<string> $bladeRels project-relative
     * @return array<string, list<string>> template => its ancestors
     */
    public static function ancestorsOfMany(array $bladeRels, string $projectRoot): array
    {
        if ($bladeRels === []) {
            return [];
        }

        $sources = [];

        foreach (self::allBladeFiles($projectRoot) as $candidate) {
            $source = @file_get_contents(rtrim($projectRoot, '/') . '/' . $candidate);

            if ($source !== false) {
                $sources[$candidate] = $source;
            }
        }

        $out = [];

        foreach ($bladeRels as $bladeRel) {
            $targets = [$bladeRel => true];
            $ancestors = [];
            $changed = $sources !== [];

            while ($changed) {
                $changed = false;

                foreach ($sources as $candidate => $source) {
                    if (isset($targets[$candidate]) || isset($ancestors[$candidate])) {
                        continue;
                    }

                    foreach (array_keys($targets) as $target) {
                        if (self::sourceReferences($source, (string) $target)) {
                            $ancestors[$candidate] = true;
                            $targets[$candidate] = true;
                            $changed = true;

                            break;
                        }
                    }
                }
            }

            $out[$bladeRel] = array_map(strval(...), array_keys($ancestors));
        }

        return $out;
    }

    public static function isBladePath(string $rel): bool
    {
        return str_starts_with($rel, 'resources/views/') && str_ends_with($rel, '.blade.php');
    }

    private static function isBladeComponentPath(string $rel): bool
    {
        return str_starts_with($rel, 'resources/views/components/') && str_ends_with($rel, '.blade.php');
    }

    /** @return list<string> */
    private static function allBladeFiles(string $projectRoot): array
    {
        $views = rtrim($projectRoot, '/') . '/resources/views';

        if (! is_dir($views)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($views, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (! str_ends_with($path, '.blade.php')) {
                continue;
            }

            $files[] = str_replace('\\', '/', substr($path, strlen(rtrim($projectRoot, '/')) + 1));
        }

        sort($files);

        return $files;
    }

    private static function sourceReferences(string $source, string $targetBlade): bool
    {
        $view = self::viewNameForBlade($targetBlade);

        // Every pattern below contains the view name literally, or `x-` and a component name
        // case-insensitively: a source holding neither cannot match any of them. Exact, and it
        // is what keeps a whole-tree walk from compiling a regex per pair of templates.
        if (($view === null || ! str_contains($source, $view)) && ! self::mayNameComponent($source, $targetBlade)) {
            return false;
        }

        if ($view !== null) {
            $quoted = preg_quote($view, '#');

            if (preg_match('#@(include|includeIf|includeWhen|includeUnless|extends|component|each)\s*\([^)]*[\'"]' . $quoted . '[\'"]#', $source) === 1) {
                return true;
            }

            if (preg_match('#\b(view|View::make)\s*\(\s*[\'"]' . $quoted . '[\'"]#', $source) === 1) {
                return true;
            }
        }

        foreach (self::componentNamesForBlade($targetBlade) as $component) {
            $quoted = preg_quote($component, '#');

            if (preg_match('#<x-' . $quoted . '(?=[\s>/.:])#i', $source) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function mayNameComponent(string $source, string $targetBlade): bool
    {
        foreach (self::componentNamesForBlade($targetBlade) as $component) {
            if (stripos($source, 'x-' . $component) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every template under `resources/views` and the templates it references directly (the
     * relation {@see self::ancestorsOf()} closes over), read from `$cacheFile` where it can be.
     *
     * The references of a template are a function of its own bytes and of the set of template
     * paths (view and component names come from paths), so a cached entry is reused only when
     * the raw content hash of the template and the hash of the whole path list both match —
     * any edit, addition, removal or rename recomputes what it could have changed. Nothing
     * else is trusted from the file.
     *
     * @return array<string, list<string>> template => templates it references
     */
    public static function referenceMap(string $projectRoot, ?string $cacheFile = null): array
    {
        $templates = self::allBladeFiles($projectRoot);

        if ($templates === []) {
            return [];
        }

        $pathsKey = hash('xxh128', self::CACHE_VERSION . "\n" . implode("\n", $templates));
        $cached = [];

        if ($cacheFile !== null) {
            $data = json_decode((string) @file_get_contents($cacheFile), true);

            if (is_array($data) && ($data['paths'] ?? null) === $pathsKey && is_array($data['entries'] ?? null)) {
                $cached = $data['entries'];
            }
        }

        $map = [];
        $entries = [];
        $dirty = false;

        foreach ($templates as $template) {
            $source = @file_get_contents(rtrim($projectRoot, '/') . '/' . $template);

            if ($source === false) {
                continue;
            }

            $hash = hash('xxh128', $source);
            $entry = $cached[$template] ?? null;

            if (is_array($entry) && ($entry['h'] ?? null) === $hash && is_array($entry['r'] ?? null)) {
                $refs = array_values(array_filter($entry['r'], 'is_string'));
            } else {
                $refs = [];

                foreach ($templates as $target) {
                    if ($target !== $template && self::sourceReferences($source, $target)) {
                        $refs[] = $target;
                    }
                }

                $dirty = true;
            }

            $map[$template] = $refs;
            $entries[$template] = ['h' => $hash, 'r' => $refs];
        }

        if ($cacheFile !== null && ($dirty || count($entries) !== count($cached))) {
            $json = json_encode(['paths' => $pathsKey, 'entries' => $entries], JSON_UNESCAPED_SLASHES);

            if ($json !== false) {
                AtomicFile::write($cacheFile, $json);
            }
        }

        return $map;
    }

    private static function viewNameForBlade(string $rel): ?string
    {
        if (! self::isBladePath($rel)) {
            return null;
        }

        $tail = substr($rel, strlen('resources/views/'));
        $tail = substr($tail, 0, -strlen('.blade.php'));

        return str_replace('/', '.', $tail);
    }

    /** @return list<string> */
    private static function componentNamesForBlade(string $rel): array
    {
        if (! self::isBladeComponentPath($rel)) {
            return [];
        }

        $tail = substr($rel, strlen('resources/views/components/'));
        $tail = substr($tail, 0, -strlen('.blade.php'));
        $name = str_replace('/', '.', $tail);

        if ($name === '') {
            return [];
        }

        $names = [$name, str_replace('_', '-', $name)];

        if (str_ends_with($name, '.index') && $name !== '.index') {
            $base = substr($name, 0, -strlen('.index'));

            $names[] = $base;
            $names[] = str_replace('_', '-', $base);
        }

        return array_values(array_unique($names));
    }
}
