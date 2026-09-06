<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use FilesystemIterator;
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
