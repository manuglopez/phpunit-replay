<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Rules;

use Manuglopez\Replay\Laravel\TableExtractor;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\ResiduePatterns;
use Manuglopez\Replay\Select\Rule;
use Manuglopez\Replay\Support\Paths;

/**
 * Laravel-only rule (SPEC.md §7.2.1): a changed `.php` file under `database/migrations/` is parsed
 * with `TableExtractor::fromMigrationSource()`; every test file whose recorded tables
 * (`Graph::testTables()`) intersect the migration's tables is affected. It narrows only when
 * the graph has at least one recorded table and the migration yields some; otherwise it
 * selects every test (a migration that cannot be read, parses to zero tables, or a graph
 * with no tables yet), since nothing else stands in for it (`WatchDefaults\Laravel`).
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

        foreach ($context->remaining as $rel) {
            if (! self::isMigrationPath($rel)) {
                continue;
            }

            $tables = self::tablesForMigration($rel, $context->projectRoot);

            // Nothing to narrow by: a migration with no readable table (deleted, raw
            // statements, a data migration), or a graph with no table recorded yet. Every
            // test runs, which is what the `database/migrations/**` watch default this rule
            // replaces did for it (`Select\WatchDefaults\Laravel`).
            if ($tables === [] || $testTables === []) {
                $targets = ResiduePatterns::targetsFor($context->testPaths);

                foreach ($context->watch->testsUnderDirectories($targets, $context->graph->allTestFiles()) as $testFile) {
                    $context->selection->add($testFile, new Reason($this->name(), $rel, 'no tables to narrow by'));
                }

                $context->consume($rel);

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

    /** Shared with `Select\NonEdgeInputs`, whose `migrations@1` scope is this rule's claim. */
    public static function isMigrationPath(string $rel): bool
    {
        return str_starts_with($rel, 'database/migrations/') && str_ends_with($rel, '.php');
    }

    /** @return list<string> */
    public static function tablesForMigration(string $rel, string $projectRoot): array
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
