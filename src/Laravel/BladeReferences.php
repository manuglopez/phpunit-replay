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
 * `@include`/`@includeIf`/`@includeWhen`/`@includeUnless`/`@includeFirst`/`@extends`/`@component`/`@each`,
 * `view('x')`/`View::make('x')`, or a matching `<x-name` component tag.
 */
final class BladeReferences
{
    /** Bumped whenever {@see self::sourceReferences()} changes what it recognises. */
    private const CACHE_VERSION = 'blade-references@2';

    /**
     * This process's own copy of what {@see self::referenceMap()} resolved, by the same key a
     * cache file uses: root, path list hash, and per template its raw content hash. Two callers
     * in one pass (`Rules\BladeRule`, `Select\NonEdgeInputs`) resolve the tree once, and an
     * edited template is resolved again since its content hash moved.
     *
     * @var array<string, array<string, array{h: string, r: list<string>}>>
     */
    private static array $memo = [];

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

    /**
     * Project-relative Blade files that statically depend on `$bladeRel`, directly or
     * transitively, sorted: the reverse closure of {@see self::referenceMap()}. A template not
     * in the map — deleted, or not written yet — is looked up against every template's source.
     *
     * @return list<string>
     */
    public static function ancestorsOf(string $bladeRel, string $projectRoot, ?string $cacheFile = null): array
    {
        return self::ancestorsOfEach([$bladeRel], $projectRoot, $cacheFile)[$bladeRel] ?? [];
    }

    /**
     * {@see self::ancestorsOf()} for every template of `$bladeRels` over ONE resolution of the
     * tree (`Rules\BladeRule`, with the pass's changed templates): the map is listed, read and
     * validated once, and its reverse index built once, however many templates changed.
     *
     * @param list<string> $bladeRels
     * @return array<string, list<string>> template => its ancestors, sorted
     */
    public static function ancestorsOfEach(array $bladeRels, string $projectRoot, ?string $cacheFile = null): array
    {
        $out = array_fill_keys($bladeRels, []);

        if ($bladeRels === []) {
            return $out;
        }

        $map = self::referenceMap($projectRoot, $cacheFile);

        if ($map === []) {
            return $out;
        }

        $referrers = [];

        foreach ($map as $source => $targets) {
            foreach ($targets as $target) {
                $referrers[$target][] = (string) $source;
            }
        }

        foreach ($bladeRels as $bladeRel) {
            $queue = [];

            if (isset($map[$bladeRel])) {
                $queue = $referrers[$bladeRel] ?? [];
            } else {
                foreach (array_keys($map) as $candidate) {
                    $source = @file_get_contents(rtrim($projectRoot, '/') . '/' . $candidate);

                    if ($source !== false && self::sourceReferences($source, $bladeRel)) {
                        $queue[] = (string) $candidate;
                    }
                }
            }

            $ancestors = [];

            while ($queue !== []) {
                $current = array_pop($queue);

                if ($current === $bladeRel || isset($ancestors[$current])) {
                    continue;
                }

                $ancestors[$current] = true;

                foreach ($referrers[$current] ?? [] as $referrer) {
                    $queue[] = $referrer;
                }
            }

            $list = array_map(strval(...), array_keys($ancestors));
            sort($list);
            $out[$bladeRel] = $list;
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

            if (preg_match('#@(include|includeIf|includeWhen|includeUnless|includeFirst|extends|component|each)\s*\([^)]*[\'"]' . $quoted . '[\'"]#', $source) === 1) {
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
        $memoKey = $projectRoot . "\0" . $pathsKey;
        $cached = self::$memo[$memoKey] ?? [];

        if ($cached === [] && $cacheFile !== null) {
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

        self::$memo = [$memoKey => $entries];

        if ($cacheFile !== null && ($dirty || count($entries) !== count($cached) || ! is_file($cacheFile))) {
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
