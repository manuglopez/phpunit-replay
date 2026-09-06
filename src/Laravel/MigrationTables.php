<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/Recorder.php
 * (`classUsesDatabase`) and https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia.php (`augmentDatabaseTestTables`).
 *
 * `tablesOf()` walks every migration under `database/migrations/**` and unions the tables
 * `TableExtractor::fromMigrationSource()` finds in each — the conservative rule (SPEC.md §10):
 * any migration might affect any database test. `usesDatabase()` answers whether a test class
 * (or one of its ancestors) uses one of Laravel's database-refreshing testing traits.
 */
final class MigrationTables
{
    private const DATABASE_TRAITS = [
        'Illuminate\\Foundation\\Testing\\RefreshDatabase' => true,
        'Illuminate\\Foundation\\Testing\\DatabaseMigrations' => true,
        'Illuminate\\Foundation\\Testing\\DatabaseTransactions' => true,
    ];

    /** @return list<string> Sorted, deduped table names referenced by any migration in the project. */
    public static function tablesOf(string $projectRoot): array
    {
        $migrationsDir = rtrim($projectRoot, '/') . '/database/migrations';

        if (! is_dir($migrationsDir)) {
            return [];
        }

        $tables = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($migrationsDir, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile() || ! str_ends_with(strtolower($fileInfo->getPathname()), '.php')) {
                continue;
            }

            $content = @file_get_contents($fileInfo->getPathname());

            if ($content === false) {
                continue;
            }

            foreach (TableExtractor::fromMigrationSource($content) as $table) {
                $tables[strtolower($table)] = true;
            }
        }

        $names = array_keys($tables);
        sort($names);

        return $names;
    }

    /**
     * True when $className, or one of its (non-internal) ancestors, uses
     * RefreshDatabase|DatabaseMigrations|DatabaseTransactions. Only ever true when the class
     * is already loaded in the current process (`class_exists($className, false)`) — the
     * only reliable way to check this is reflection over an autoloaded class.
     */
    public static function usesDatabase(string $className): bool
    {
        if (! class_exists($className, false)) {
            return false;
        }

        $reflection = new ReflectionClass($className);

        do {
            foreach (array_keys($reflection->getTraits()) as $traitName) {
                if (isset(self::DATABASE_TRAITS[$traitName])) {
                    return true;
                }
            }

            $reflection = $reflection->getParentClass();
        } while ($reflection !== false && ! $reflection->isInternal());

        return false;
    }
}
