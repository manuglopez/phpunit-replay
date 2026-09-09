<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Rules;

use Manuglopez\Replay\Laravel\TableExtractor;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Rule;
use Manuglopez\Replay\Support\Paths;

/**
 * Laravel-only rule (SPEC.md §7.2.1): a changed `.php` file under `database/migrations/` is parsed
 * with `TableExtractor::fromMigrationSource()`; every test file whose recorded tables
 * (`Graph::testTables()`) intersect the migration's tables is affected. Only runs at all when
 * the graph has at least one recorded table (`si hay test_tables`) — otherwise every migration
 * path is left unconsumed for `WatchRule`. A migration that cannot be read, or one that parses
 * to zero tables, is also left unconsumed ("no parseable → cae a WatchRule").
 */
final class MigrationRule implements Rule
{
    public function name(): string
    {
        return 'Migration';
    }

    public function apply(Context $context): void
    {
        $testTables = $context->graph->testTables();

        if ($testTables === []) {
            return;
        }

        foreach ($context->remaining as $rel) {
            if (! $this->isMigrationPath($rel)) {
                continue;
            }

            $tables = $this->tablesForMigration($rel, $context->projectRoot);

            if ($tables === []) {
                continue;
            }

            $lowerTables = array_map(strtolower(...), $tables);

            foreach ($testTables as $testFile => $testFileTables) {
                foreach ($testFileTables as $table) {
                    if (in_array($table, $lowerTables, true)) {
                        $context->selection->add($testFile, new Reason($this->name(), $rel, implode(', ', $tables)));

                        break;
                    }
                }
            }

            $context->consume($rel);
        }
    }

    private function isMigrationPath(string $rel): bool
    {
        return str_starts_with($rel, 'database/migrations/') && str_ends_with($rel, '.php');
    }

    /** @return list<string> */
    private function tablesForMigration(string $rel, string $projectRoot): array
    {
        $absolute = Paths::join($projectRoot, $rel);

        if (! is_file($absolute)) {
            return [];
        }

        $content = @file_get_contents($absolute);

        if ($content === false) {
            return [];
        }

        return TableExtractor::fromMigrationSource($content);
    }
}
