<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/WatchPatterns.php
 *
 * Glob-based fallback matcher (SPEC.md §7.2.6): maps a changed file to the test
 * directories a "watch" pattern says it affects. Supports `**` globbing, `!exclude`
 * tokens, and skips VCS directories and dotfiles unless the pattern itself targets them.
 */
final class WatchPatterns
{
    /** @var list<string> */
    private const VCS_DIRS = ['.git', '.svn', '.hg'];

    /** @var array<string, list<string>> raw pattern → project-relative test dirs/files */
    private array $patterns = [];

    /** @var array<string, array{include: string, excludes: list<string>, allowDotfiles: bool}> */
    private array $parsed = [];

    /** @param list<string> $testDirectories */
    public function useDefaults(string $projectRoot, array $testDirectories): void
    {
        $defaults = [
            new WatchDefaults\Php(),
            new WatchDefaults\Laravel(),
            new WatchDefaults\Symfony(),
        ];

        foreach ($defaults as $default) {
            if (! $default->applicable($projectRoot)) {
                continue;
            }

            $this->add($default->defaults($projectRoot, $testDirectories));
        }
    }

    /** @param array<string, string|list<string>> $patterns pattern → dir or list of dirs */
    public function add(array $patterns): void
    {
        foreach ($patterns as $pattern => $dirs) {
            $dirs = is_array($dirs) ? $dirs : [$dirs];

            $this->patterns[$pattern] = array_values(array_unique(
                array_merge($this->patterns[$pattern] ?? [], $dirs),
            ));
        }
    }

    /** @return array<string, list<string>> */
    public function patterns(): array
    {
        return $this->patterns;
    }

    /** @return array<string, list<string>> pattern → dirs, for every pattern matching $changedFile */
    public function matches(string $changedFile): array
    {
        $matched = [];

        foreach ($this->patterns as $pattern => $dirs) {
            if ($this->keyMatches($pattern, $changedFile)) {
                $matched[$pattern] = $dirs;
            }
        }

        return $matched;
    }

    /**
     * @param  string  $projectRoot  Absolute path (kept for API parity; matching is path-only).
     * @param  list<string>  $changed  project-relative
     * @return list<string> project-relative test dirs/files
     */
    public function matchedDirectories(string $projectRoot, array $changed): array
    {
        if ($this->patterns === []) {
            return [];
        }

        $matched = [];

        foreach ($changed as $file) {
            foreach ($this->matches($file) as $dirs) {
                foreach ($dirs as $dir) {
                    $matched[$dir] = true;
                }
            }
        }

        return array_keys($matched);
    }

    /**
     * @param  list<string>  $directories  project-relative dirs/files
     * @param  list<string>  $allTestFiles  project-relative test files from the graph
     * @return list<string>
     */
    public function testsUnderDirectories(array $directories, array $allTestFiles): array
    {
        if ($directories === []) {
            return [];
        }

        $affected = [];

        foreach ($allTestFiles as $testFile) {
            foreach ($directories as $target) {
                if ($testFile === $target) {
                    $affected[] = $testFile;

                    break;
                }

                $prefix = rtrim($target, '/') . '/';

                if (str_starts_with($testFile, $prefix)) {
                    $affected[] = $testFile;

                    break;
                }
            }
        }

        return $affected;
    }

    private function keyMatches(string $key, string $file): bool
    {
        $rule = $this->parse($key);

        if ($rule['include'] === '' || ! $this->globMatches($rule['include'], $file)) {
            return false;
        }

        $file = str_replace('\\', '/', $file);

        if ($this->touchesVcs($file)) {
            return false;
        }

        if (! $rule['allowDotfiles'] && $this->touchesDotfile($file)) {
            return false;
        }

        foreach ($rule['excludes'] as $exclude) {
            if ($this->excludeMatches($exclude, $file)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{include: string, excludes: list<string>, allowDotfiles: bool} */
    private function parse(string $key): array
    {
        if (isset($this->parsed[$key])) {
            return $this->parsed[$key];
        }

        $tokens = preg_split('/\s+/', trim($key)) ?: [];

        $include = '';
        $excludes = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if ($token[0] === '!') {
                $excludes[] = substr($token, 1);

                continue;
            }

            if ($include === '') {
                $include = $token;
            }
        }

        return $this->parsed[$key] = [
            'include' => $include,
            'excludes' => $excludes,
            'allowDotfiles' => $this->patternTargetsDotfiles($include),
        ];
    }

    private function patternTargetsDotfiles(string $pattern): bool
    {
        foreach (explode('/', str_replace('\\', '/', $pattern)) as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return true;
            }
        }

        return false;
    }

    private function touchesVcs(string $file): bool
    {
        foreach (explode('/', $file) as $segment) {
            if (in_array($segment, self::VCS_DIRS, true)) {
                return true;
            }
        }

        return false;
    }

    private function touchesDotfile(string $file): bool
    {
        foreach (explode('/', $file) as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return true;
            }
        }

        return false;
    }

    private function excludeMatches(string $exclude, string $file): bool
    {
        $pattern = str_contains($exclude, '/') ? $exclude : '**/' . $exclude;

        if ($this->globMatches($pattern, $file)) {
            return true;
        }

        return $this->globMatches($exclude, basename($file));
    }

    private function globMatches(string $pattern, string $file): bool
    {
        $pattern = str_replace('\\', '/', $pattern);
        $file = str_replace('\\', '/', $file);

        $regex = '';
        $len = strlen($pattern);
        $i = 0;

        while ($i < $len) {
            $c = $pattern[$i];

            if ($c === '*' && isset($pattern[$i + 1]) && $pattern[$i + 1] === '*') {
                $regex .= '.*';
                $i += 2;

                if (isset($pattern[$i]) && $pattern[$i] === '/') {
                    $i++;
                }
            } elseif ($c === '*') {
                $regex .= '[^/]*';
                $i++;
            } elseif ($c === '?') {
                $regex .= '[^/]';
                $i++;
            } else {
                $regex .= preg_quote($c, '#');
                $i++;
            }
        }

        return (bool) preg_match('#^' . $regex . '$#', $file);
    }
}
