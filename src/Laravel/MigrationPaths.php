<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use FilesystemIterator;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Support\Json;
use Manuglopez\Replay\Support\Paths;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Where a Laravel project keeps its migrations (`Rules\MigrationRule`, `Select\NonEdgeInputs`,
 * the `database/migrations/**` fallback of `Select\WatchDefaults\Laravel`). The union of:
 *
 * - `database/migrations`, which `migrate` always runs;
 * - the `migration_paths` config key;
 * - every literal `loadMigrationsFrom()` argument in `app/Providers/**` and `bootstrap/**`
 *   (not `bootstrap/cache`, which the framework generates): a string, an array of them,
 *   `database_path('...')`, `base_path('...')`, and `__DIR__` / `dirname(__DIR__[, n])`
 *   concatenated with strings. An argument built any other way (a variable, a method call,
 *   `config()`) is ignored, never guessed: list such a directory in `migration_paths`;
 * - `database/migrations/tenant` when `stancl/tenancy` is in `composer.lock` (its documented
 *   default for `tenants:migrate`).
 *
 * `spatie/laravel-multitenancy` documents no default path of its own (its docs pass
 * `--path=database/migrations/landlord` or `.../tenant` to `migrate`), and both are under
 * `database/migrations` anyway. A path outside the project, or under `vendor/`, is dropped.
 * Each entry is a directory, matched recursively, or a single `.php` file (Laravel's migrator
 * accepts both).
 */
final readonly class MigrationPaths
{
    private const SCANNED = ['app/Providers', 'bootstrap'];

    /** @param list<string> $paths normalised, sorted, unique */
    private function __construct(private array $paths)
    {
    }

    public static function for(string $projectRoot, Config $config): self
    {
        return self::of([...Config::DEFAULT_MIGRATION_PATHS, ...$config->migrationPaths, ...self::detected($projectRoot)], $projectRoot);
    }

    /** @param list<string> $paths project-relative (absolute ones under `$projectRoot` are accepted) */
    public static function of(array $paths, ?string $projectRoot = null): self
    {
        $out = [];

        foreach ($paths as $path) {
            $normalised = self::normalise($path, $projectRoot);

            if ($normalised !== null) {
                $out[$normalised] = true;
            }
        }

        $list = array_map(strval(...), array_keys($out));
        sort($list);

        return new self($list);
    }

    public static function default(): self
    {
        return self::of(Config::DEFAULT_MIGRATION_PATHS);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** A `.php` file under one of the paths (or one of them, when it is a file). */
    public function isMigration(string $rel): bool
    {
        return str_ends_with($rel, '.php') && $this->covers($rel);
    }

    /** Any file under one of the paths: what the fallback patterns name. */
    public function covers(string $rel): bool
    {
        foreach ($this->paths as $path) {
            if ($rel === $path || str_starts_with($rel, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One watch pattern per outermost path: `<dir>/**` for a directory, the path itself for a
     * file. A path under another one adds nothing.
     *
     * @return list<string>
     */
    public function fallbackPatterns(): array
    {
        $patterns = [];

        foreach ($this->paths as $path) {
            foreach ($this->paths as $other) {
                if ($other !== $path && str_starts_with($path, $other . '/')) {
                    continue 2;
                }
            }

            $patterns[] = str_ends_with($path, '.php') ? $path : $path . '/**';
        }

        sort($patterns);

        return $patterns;
    }

    /**
     * The paths read from the project itself: `loadMigrationsFrom()` literals and the tenancy
     * conventions. Never throws; a file that cannot be read or parsed is skipped.
     *
     * @return list<string>
     */
    public static function detected(string $projectRoot): array
    {
        $root = rtrim(Paths::normalizeSeparators((string) (realpath($projectRoot) ?: $projectRoot)), '/');
        $found = [];

        foreach (self::scannedFiles($root) as $file) {
            $source = @file_get_contents($file);

            if ($source === false || ! str_contains($source, 'loadMigrationsFrom')) {
                continue;
            }

            foreach (self::literalArguments($source, dirname($file)) as $path) {
                $normalised = self::normalise($path, $root);

                if ($normalised !== null) {
                    $found[$normalised] = true;
                }
            }
        }

        if (self::locked($root, 'stancl/tenancy')) {
            $found['database/migrations/tenant'] = true;
        }

        $list = array_map(strval(...), array_keys($found));
        sort($list);

        return $list;
    }

    /** @return list<string> absolute */
    private static function scannedFiles(string $root): array
    {
        $files = [];

        foreach (self::SCANNED as $dir) {
            $absolute = $root . '/' . $dir;

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $info */
            foreach ($iterator as $info) {
                $path = Paths::normalizeSeparators($info->getPathname());

                if ($info->isFile() && str_ends_with($path, '.php') && ! str_starts_with($path, $root . '/bootstrap/cache/')) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> absolute or project-relative paths */
    private static function literalArguments(string $source, string $directory): array
    {
        try {
            $ast = (new ParserFactory())->createForHostVersion()->parse($source);
        } catch (Throwable) {
            return [];
        }

        if ($ast === null) {
            return [];
        }

        $paths = [];

        foreach ((new NodeFinder())->find($ast, static fn (Node $node): bool => ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall)
            && $node->name instanceof Node\Identifier
            && strtolower($node->name->toString()) === 'loadmigrationsfrom') as $call) {
            /** @var Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call */
            $first = $call->getArgs()[0] ?? null;

            if (! $first instanceof Arg) {
                continue;
            }

            $values = $first->value instanceof Expr\Array_
                ? array_map(static fn (?Node\ArrayItem $item): ?Expr => $item?->value, $first->value->items)
                : [$first->value];

            foreach ($values as $value) {
                $path = $value === null ? null : self::literal($value, $directory);

                if ($path !== null) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /** A path an expression always evaluates to, or null when it is not a literal one. */
    private static function literal(Expr $expr, string $directory): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Dir) {
            return $directory;
        }

        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = self::literal($expr->left, $directory);
            $right = self::literal($expr->right, $directory);

            return $left === null || $right === null ? null : $left . $right;
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name) {
            $function = strtolower($expr->name->toString());
            $args = $expr->getArgs();

            if ($function === 'dirname' && isset($args[0])) {
                $inner = self::literal($args[0]->value, $directory);
                $levels = isset($args[1]) && $args[1]->value instanceof Node\Scalar\Int_ ? $args[1]->value->value : 1;

                return $inner === null || $levels < 1 ? null : dirname($inner, $levels);
            }

            if ($function === 'database_path' || $function === 'base_path') {
                $prefix = $function === 'database_path' ? 'database' : '';

                if ($args === []) {
                    return $prefix === '' ? null : $prefix;
                }

                $inner = self::literal($args[0]->value, $directory);

                if ($inner === null) {
                    return null;
                }

                return ltrim($prefix . '/' . ltrim($inner, '/'), '/');
            }
        }

        return null;
    }

    /** Project-relative, `.`/`..` resolved, no trailing slash; null outside the project or under vendor/. */
    private static function normalise(string $path, ?string $projectRoot): ?string
    {
        $path = Paths::normalizeSeparators(trim($path));

        if ($path === '') {
            return null;
        }

        if (Paths::isAbsolute($path)) {
            if ($projectRoot === null) {
                return null;
            }

            $root = rtrim(Paths::normalizeSeparators((string) (realpath($projectRoot) ?: $projectRoot)), '/');
            $resolved = self::resolveDots($path);

            if ($resolved === null || ! str_starts_with($resolved, $root . '/')) {
                return null;
            }

            $path = substr($resolved, strlen($root) + 1);
        } else {
            $resolved = self::resolveDots('/' . $path);

            if ($resolved === null) {
                return null;
            }

            $path = ltrim($resolved, '/');
        }

        if ($path === '' || $path === 'vendor' || str_starts_with($path, 'vendor/')) {
            return null;
        }

        return rtrim($path, '/');
    }

    /** An absolute path with `.` and `..` resolved; null when `..` climbs above the root. */
    private static function resolveDots(string $absolute): ?string
    {
        $out = [];

        foreach (explode('/', $absolute) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($out === []) {
                    return null;
                }

                array_pop($out);

                continue;
            }

            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }

    private static function locked(string $root, string $package): bool
    {
        $lock = @file_get_contents($root . '/composer.lock');

        if ($lock === false) {
            return false;
        }

        $data = Json::decodeArray($lock);

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($data[$section] ?? null) ? $data[$section] : [] as $entry) {
                if (is_array($entry) && ($entry['name'] ?? null) === $package) {
                    return true;
                }
            }
        }

        return false;
    }
}
