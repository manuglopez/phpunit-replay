<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Manuglopez\Replay\Support\Paths;
use Throwable;

/**
 * The schema dump a test database is built from, and the migrations it already holds.
 *
 * `migrate` (and so `RefreshDatabase`, `DatabaseMigrations`) loads the dump of the
 * connection it migrates — `database/schema/{connection}-schema.dump` when present, else
 * `-schema.sql` — and then runs only the migrations that dump's `migrations` rows do not
 * list. A migration those rows list is squashed: no test database runs it.
 *
 * The test connection is read, in this order, from the `DB_CONNECTION` `<env>`/`<server>`
 * of the PHPUnit configuration (the one the graph was recorded with, else `phpunit.xml`,
 * `phpunit.xml.dist`, `phpunit.dist.xml`), `.env.testing`, `.env`, and the literal default of
 * `env('DB_CONNECTION', '...')` in `config/database.php`. When none says, nothing is
 * presumed squashed. Not seen: a `--schema-path` a test passes `migrate` itself, a
 * connection a test switches to at runtime, a migrations table not named `migrations`.
 */
final class TestSchemaDump
{
    private const CONFIGURATIONS = ['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'];

    public static function connection(string $projectRoot, ?string $phpunitConfiguration): ?string
    {
        $candidates = $phpunitConfiguration !== null && $phpunitConfiguration !== ''
            ? [$phpunitConfiguration, ...self::CONFIGURATIONS]
            : self::CONFIGURATIONS;

        foreach ($candidates as $configuration) {
            $absolute = Paths::isAbsolute($configuration) ? $configuration : Paths::join($projectRoot, $configuration);

            if (is_file($absolute)) {
                $fromXml = self::fromPhpunitXml($absolute);

                if ($fromXml !== null) {
                    return $fromXml;
                }

                break;
            }
        }

        foreach (['.env.testing', '.env'] as $env) {
            $fromEnv = self::fromEnvFile(Paths::join($projectRoot, $env));

            if ($fromEnv !== null) {
                return $fromEnv;
            }
        }

        $database = @file_get_contents(Paths::join($projectRoot, 'config/database.php'));

        if ($database !== false && preg_match('/env\(\s*[\'"]DB_CONNECTION[\'"]\s*,\s*[\'"]([\w.-]+)[\'"]\s*\)/', $database, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** The project-relative dump the test connection loads, or null when it has none (or is unknown). */
    public static function path(string $projectRoot, ?string $phpunitConfiguration): ?string
    {
        $connection = self::connection($projectRoot, $phpunitConfiguration);

        if ($connection === null) {
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
     * @return array<string, true>|null
     */
    public static function squashed(string $projectRoot, ?string $phpunitConfiguration): ?array
    {
        $path = self::path($projectRoot, $phpunitConfiguration);

        if ($path === null) {
            return null;
        }

        $content = @file_get_contents(Paths::join($projectRoot, $path));
        $dump = $content === false ? null : SchemaDump::parse($content);
        $names = $dump?->migrations();

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

    private static function fromPhpunitXml(string $file): ?string
    {
        $content = @file_get_contents($file);

        if ($content === false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($content);

            if ($xml === false) {
                return null;
            }

            foreach (['env', 'server'] as $element) {
                foreach ($xml->xpath('/phpunit/php/' . $element . '[@name="DB_CONNECTION"]') ?: [] as $node) {
                    $value = (string) ($node['value'] ?? '');

                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        } catch (Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return null;
    }

    private static function fromEnvFile(string $file): ?string
    {
        $content = @file_get_contents($file);

        if ($content === false || preg_match('/^\s*(?:export\s+)?DB_CONNECTION\s*=\s*(.*)$/m', $content, $m) !== 1) {
            return null;
        }

        $value = trim($m[1]);

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $end = strpos($value, $value[0], 1);
            $value = $end === false ? '' : substr($value, 1, $end - 1);
        } else {
            $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
        }

        return $value === '' ? null : $value;
    }
}
