<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use FilesystemIterator;
use Manuglopez\Replay\Support\Paths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * The schema dump a test database is built from, and the migrations it already holds.
 *
 * `migrate` (and so `RefreshDatabase`, `DatabaseMigrations`) loads the dump of the
 * connection it migrates — `database/schema/{connection}-schema.dump` when present, else
 * `-schema.sql`, never on SQL Server — and then runs only the migrations that dump's
 * `migrations` rows do not list. A migration those rows list is squashed: no test database
 * runs it.
 *
 * The test connection is resolved the way Laravel resolves `DB_CONNECTION` under PHPUnit:
 *
 * 1. a `<server>` entry of the PHPUnit configuration (the one the graph was recorded with,
 *    else `phpunit.xml`, `phpunit.xml.dist`, `phpunit.dist.xml`), which PHPUnit always writes
 *    into `$_SERVER`, the first place Laravel's `env()` reads;
 * 2. a forced `<env force="true">`, unless the process environment holds another value
 *    (still in `$_SERVER`): then it is ambiguous;
 * 3. the process environment, read when the pass runs;
 * 4. a plain `<env>`, which PHPUnit applies only when the process does not set it;
 * 5. one env file: `.env.{APP_ENV}` when `APP_ENV` (resolved the same way) names one that
 *    exists, which Laravel loads INSTEAD of `.env`; else `.env`;
 * 6. the literal default of `env('DB_CONNECTION', '…')` in `config/database.php`.
 *
 * With `bootstrap/cache/config.php` present, Laravel reads that and nothing else: its
 * `database.default` is the connection (its connection's driver, and its migrations table name,
 * are read from it too); a cached file that cannot be read makes the connection unknown.
 *
 * Anything this cannot see for certain makes nothing squashed: an ambiguous or interpolated
 * value, a test that migrates another connection or loads another dump (`--database`,
 * `--schema-path` anywhere under `tests/`), a `useDatabasePath()` in `bootstrap/`, a
 * migrations table not named `migrations`, a connection a test switches to at runtime.
 */
final class TestSchemaDump
{
    private const CONFIGURATIONS = ['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'];

    /** @var array<string, array{string, bool}> project root => [signature of the scanned files, result] */
    private static array $elsewhere = [];

    /** @var array<string, ?SchemaDump> "<dump>\0<stat>" => the parsed dump (the latest only) */
    private static array $parsed = [];

    /** @param array<string, string>|null $environment the process environment; null reads `getenv()` */
    public static function connection(string $projectRoot, ?string $phpunitConfiguration, ?array $environment = null): ?string
    {
        // A cached configuration is all Laravel reads: no env file, no env() default.
        $cached = self::cachedConfiguration($projectRoot);

        if ($cached !== false) {
            $default = is_array($cached) && is_array($cached['database'] ?? null) ? ($cached['database']['default'] ?? null) : null;

            return is_string($default) && $default !== '' ? $default : null;
        }

        $environment ??= self::processEnvironment();
        $xml = self::phpunitVariables($projectRoot, $phpunitConfiguration);
        $resolved = self::resolve('DB_CONNECTION', $xml, $environment);

        if ($resolved !== false) {
            return $resolved;
        }

        $appEnv = self::resolve('APP_ENV', $xml, $environment);

        if ($appEnv === null) {
            return null;
        }

        $envFile = '.env';

        if (is_string($appEnv) && $appEnv !== '' && is_file(Paths::join($projectRoot, '.env.' . $appEnv))) {
            $envFile = '.env.' . $appEnv;
        }

        $fromFile = self::fromEnvFile(Paths::join($projectRoot, $envFile));

        if ($fromFile !== false) {
            return $fromFile;
        }

        $database = @file_get_contents(Paths::join($projectRoot, 'config/database.php'));

        if ($database !== false && preg_match('/[\'"]default[\'"]\s*=>\s*env\(\s*[\'"]DB_CONNECTION[\'"]\s*,\s*[\'"]([\w.-]+)[\'"]\s*\)/', $database, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * The project-relative dump the test connection loads, or null when it has none, it is
     * unknown, or the project migrates in a way this cannot follow ({@see self} docblock).
     *
     * @param array<string, string>|null $environment
     */
    public static function path(string $projectRoot, ?string $phpunitConfiguration, ?array $environment = null): ?string
    {
        $connection = self::connection($projectRoot, $phpunitConfiguration, $environment);

        if ($connection === null || self::isSqlServer($projectRoot, $connection) || ! self::migrationsTableIsTheDefault($projectRoot) || self::migratesElsewhere($projectRoot)) {
            return null;
        }

        foreach (['dump', 'sql'] as $extension) {
            $rel = 'database/schema/' . $connection . '-schema.' . $extension;

            if (is_file(Paths::join($projectRoot, $rel))) {
                return $rel;
            }
        }

        return null;
    }

    /**
     * The migration names (file basenames without `.php`) the test connection's dump lists,
     * or null when there is no such dump or its rows cannot be read: then every migration is
     * presumed to run.
     *
     * @param array<string, string>|null $environment
     * @return array<string, true>|null
     */
    public static function squashed(string $projectRoot, ?string $phpunitConfiguration, ?array $environment = null): ?array
    {
        $path = self::path($projectRoot, $phpunitConfiguration, $environment);

        if ($path === null) {
            return null;
        }

        $absolute = Paths::join($projectRoot, $path);
        $stat = @stat($absolute);
        $key = $absolute . "\0" . ($stat === false ? '' : $stat['mtime'] . ':' . $stat['size'] . ':' . $stat['ino']);

        // Once per content in a process: the rule and the digest both ask, several times a pass.
        if (! array_key_exists($key, self::$parsed)) {
            $content = @file_get_contents($absolute);
            self::$parsed = [$key => $content === false ? null : SchemaDump::parse($content)];
        }

        $names = self::$parsed[$key]?->migrations();

        if ($names === null || $names === []) {
            return null;
        }

        return array_fill_keys($names, true);
    }

    /** The name a migration file is recorded under in the `migrations` table. */
    public static function migrationName(string $rel): string
    {
        return basename($rel, '.php');
    }

    /**
     * `$name` as Laravel sees it before any env file: a string, null when ambiguous, false
     * when nothing sets it.
     *
     * @param array{server: array<string, string>, forced: array<string, string>, env: array<string, string>} $xml
     * @param array<string, string> $environment
     */
    private static function resolve(string $name, array $xml, array $environment): string|false|null
    {
        if (isset($xml['server'][$name])) {
            return $xml['server'][$name];
        }

        $process = $environment[$name] ?? null;

        if (isset($xml['forced'][$name])) {
            return $process === null || $process === $xml['forced'][$name] ? $xml['forced'][$name] : null;
        }

        return $process ?? $xml['env'][$name] ?? false;
    }

    /** @return array<string, string> */
    private static function processEnvironment(): array
    {
        $out = [];

        foreach (['DB_CONNECTION', 'APP_ENV'] as $name) {
            $value = getenv($name);

            if (is_string($value)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @return array{server: array<string, string>, forced: array<string, string>, env: array<string, string>} */
    private static function phpunitVariables(string $projectRoot, ?string $phpunitConfiguration): array
    {
        $out = ['server' => [], 'forced' => [], 'env' => []];
        $candidates = $phpunitConfiguration !== null && $phpunitConfiguration !== ''
            ? [$phpunitConfiguration, ...self::CONFIGURATIONS]
            : self::CONFIGURATIONS;

        foreach ($candidates as $configuration) {
            $absolute = Paths::isAbsolute($configuration) ? $configuration : Paths::join($projectRoot, $configuration);

            if (! is_file($absolute)) {
                continue;
            }

            $content = @file_get_contents($absolute);

            if ($content === false) {
                return $out;
            }

            $previous = libxml_use_internal_errors(true);

            try {
                $xml = simplexml_load_string($content);

                if ($xml === false) {
                    return $out;
                }

                foreach (['server', 'env'] as $element) {
                    foreach ($xml->xpath('/phpunit/php/' . $element) ?: [] as $node) {
                        $name = (string) ($node['name'] ?? '');

                        if ($name === '') {
                            continue;
                        }

                        $value = (string) ($node['value'] ?? '');
                        $force = in_array(strtolower((string) ($node['force'] ?? '')), ['true', '1'], true);
                        $bucket = $element === 'server' ? 'server' : ($force ? 'forced' : 'env');
                        $out[$bucket][$name] = $value;
                    }
                }
            } catch (Throwable) {
                return $out;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }

            return $out;
        }

        return $out;
    }

    /** The file's `DB_CONNECTION`: a string, null when it cannot be read for certain, false when unset. */
    private static function fromEnvFile(string $file): string|false|null
    {
        $content = @file_get_contents($file);

        if ($content === false || preg_match('/^\s*(?:export\s+)?DB_CONNECTION\s*=\s*(.*)$/m', $content, $m) !== 1) {
            return false;
        }

        $value = trim($m[1]);

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $end = strpos($value, $value[0], 1);
            $value = $end === false ? '' : substr($value, 1, $end - 1);
        } else {
            $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
        }

        if (str_contains($value, '$')) {
            return null;
        }

        return $value === '' ? false : $value;
    }

    private static function isSqlServer(string $projectRoot, string $connection): bool
    {
        if ($connection === 'sqlsrv') {
            return true;
        }

        $cached = self::cachedConfiguration($projectRoot);

        if ($cached !== false) {
            $driver = self::dig($cached, 'database', 'connections', $connection, 'driver');

            return $driver === 'sqlsrv';
        }

        $database = @file_get_contents(Paths::join($projectRoot, 'config/database.php'));

        return $database !== false
            && preg_match('/[\'"]' . preg_quote($connection, '/') . '[\'"]\s*=>\s*\[[^\]]*?[\'"]driver[\'"]\s*=>\s*[\'"]sqlsrv[\'"]/s', $database) === 1;
    }

    /**
     * Whether the `migrations` rows are the migration table's: a cached configuration can say
     * the project renamed it (`database.migrations`, a string or `['table' => …]`).
     */
    private static function migrationsTableIsTheDefault(string $projectRoot): bool
    {
        $cached = self::cachedConfiguration($projectRoot);

        if ($cached === false) {
            return true;
        }

        $setting = is_array($cached) && is_array($cached['database'] ?? null) ? ($cached['database']['migrations'] ?? 'migrations') : null;
        $table = is_array($setting) ? ($setting['table'] ?? null) : $setting;

        return $table === 'migrations';
    }

    /** `$values[$key1][$key2]…`, or null when any level is missing or not an array. */
    private static function dig(mixed $values, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (! is_array($values) || ! array_key_exists($key, $values)) {
                return null;
            }

            $values = $values[$key];
        }

        return $values;
    }

    /**
     * `bootstrap/cache/config.php`: false when there is none, null when it cannot be read.
     *
     * @return array<mixed>|false|null
     */
    private static function cachedConfiguration(string $projectRoot): array|false|null
    {
        $file = Paths::join($projectRoot, 'bootstrap/cache/config.php');

        if (! is_file($file)) {
            return false;
        }

        try {
            $values = (static fn (string $path): mixed => require $path)($file);
        } catch (Throwable) {
            return null;
        }

        return is_array($values) ? $values : null;
    }

    /**
     * A test migrating another connection or loading another dump, or a moved `database_path()`.
     * The scan reads every file under `tests/`: its result is kept for the process while no
     * file there or in `bootstrap/` changed (a stat of each).
     */
    private static function migratesElsewhere(string $projectRoot): bool
    {
        $files = [...self::phpFiles($projectRoot, 'bootstrap', 'bootstrap/cache/'), ...self::phpFiles($projectRoot, 'tests', 'tests/Fixtures/')];
        $signature = $projectRoot;

        foreach ($files as $file) {
            $stat = @stat($file);
            $signature .= "\0" . $file . ($stat === false ? '' : ':' . $stat['mtime'] . ':' . $stat['size']);
        }

        $signature = hash('xxh128', $signature);

        if (isset(self::$elsewhere[$projectRoot]) && self::$elsewhere[$projectRoot][0] === $signature) {
            return self::$elsewhere[$projectRoot][1];
        }

        $result = self::scanForElsewhere($projectRoot);
        self::$elsewhere[$projectRoot] = [$signature, $result];

        return $result;
    }

    private static function scanForElsewhere(string $projectRoot): bool
    {
        foreach (self::phpFiles($projectRoot, 'bootstrap', 'bootstrap/cache/') as $file) {
            $content = @file_get_contents($file);

            if ($content !== false && str_contains($content, 'useDatabasePath')) {
                return true;
            }
        }

        foreach (self::phpFiles($projectRoot, 'tests', 'tests/Fixtures/') as $file) {
            $content = @file_get_contents($file);

            if ($content !== false && preg_match('/[\'"]--(?:database|schema-path)[\'"]/', $content) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function phpFiles(string $projectRoot, string $dir, string $skip): array
    {
        $root = rtrim(Paths::normalizeSeparators($projectRoot), '/');
        $absolute = $root . '/' . $dir;

        if (! is_dir($absolute)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $info */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)) as $info) {
            $path = Paths::normalizeSeparators($info->getPathname());

            if ($info->isFile() && str_ends_with($path, '.php') && ! str_starts_with($path, $root . '/' . $skip)) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
