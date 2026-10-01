<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Rules;

use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Laravel\SchemaDump;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\ResiduePatterns;
use Manuglopez\Replay\Select\Rule;
use Manuglopez\Replay\Support\Paths;

/**
 * Laravel-only rule: a changed schema dump (`database/schema/{connection}-schema.sql|.dump`,
 * {@see SchemaDump::isDumpPath()}). `RefreshDatabase` builds the test database from it before
 * any migration runs, coverage never credits it to a test, and it is not a migration, so
 * before 0.13 nothing claimed it and a change to it ran nothing.
 *
 * The dump at the change set's base (`Context::$base`, read with `git show`) and the one in
 * the working tree are split into per-table blocks ({@see SchemaDump}) and compared:
 *
 * - a table whose block was added, removed or changed selects the test files whose recorded
 *   tables (`Cache\Graph::testTables()`) contain it, and every database test file that
 *   recorded no table at all (nothing says it does not use that table);
 * - a changed table no test records, or a changed statement no table owns (a function, a
 *   type), selects every database test file (`Cache\Graph::databaseTestFiles()`);
 * - a change to the `migrations` rows alone selects nothing beyond what the migration files
 *   changed with it select themselves (`MigrationRule`), and `explain` says so.
 *
 * Conservative where it cannot compare: a dump that does not parse (a `pg_dump` archive
 * included), no version at the base (a new dump, a change set without a base) selects every
 * database test file; a graph with no table recorded yet, every test. The rule always
 * consumes the dump: nothing after it could say more.
 */
final class SchemaDumpRule implements Rule
{
    public function name(): string
    {
        return 'SchemaDump';
    }

    public function apply(Context $context): void
    {
        foreach ($context->remaining as $rel) {
            if (! SchemaDump::isDumpPath($rel)) {
                continue;
            }

            $context->consume($rel);
            $this->select($context, $rel);
        }
    }

    private function select(Context $context, string $rel): void
    {
        $testTables = $context->graph->testTables();

        if ($testTables === []) {
            $targets = ResiduePatterns::targetsFor($context->testPaths);

            foreach ($context->watch->testsUnderDirectories($targets, $context->graph->allTestFiles()) as $testFile) {
                $context->selection->add($testFile, new Reason($this->name(), $rel, 'no tables to narrow by'));
            }

            return;
        }

        $absolute = Paths::join($context->projectRoot, $rel);
        $newContent = is_file($absolute) ? @file_get_contents($absolute) : null;
        $new = $newContent === null ? SchemaDump::none() : ($newContent === false ? null : SchemaDump::parse($newContent));

        if ($new === null) {
            $this->selectDatabaseTests($context, $rel, 'cannot be read: every database test');

            return;
        }

        $oldContent = $context->base === null ? null : (new Git($context->projectRoot))->show($context->base, $rel);

        if ($oldContent === null) {
            $this->selectDatabaseTests($context, $rel, 'no earlier version to compare: every database test');

            return;
        }

        $old = SchemaDump::parse($oldContent);

        if ($old === null) {
            $this->selectDatabaseTests($context, $rel, 'cannot be read: every database test');

            return;
        }

        $changed = SchemaDump::changedTables($old, $new);

        if (SchemaDump::globalChanged($old, $new)) {
            $this->selectDatabaseTests($context, $rel, 'a statement no table owns changed: every database test');
        }

        if ($changed === []) {
            if (! SchemaDump::globalChanged($old, $new)) {
                $context->selection->note(new Reason($this->name(), $rel, 'only migration rows changed, no table'));
            }

            return;
        }

        $changedSet = array_fill_keys($changed, true);
        $recorded = [];

        foreach ($testTables as $testFile => $tables) {
            $hit = [];

            foreach ($tables as $table) {
                $recorded[$table] = true;

                if (isset($changedSet[$table])) {
                    $hit[] = $table;
                }
            }

            if ($hit !== []) {
                $context->selection->add((string) $testFile, new Reason($this->name(), $rel, implode(', ', $hit)));
            }
        }

        $unrecorded = array_values(array_filter($changed, static fn (string $table): bool => ! isset($recorded[$table])));

        if ($unrecorded !== []) {
            $this->selectDatabaseTests($context, $rel, 'tables no test records (' . implode(', ', $unrecorded) . '): every database test');
        }

        foreach ($context->graph->databaseTestFiles() as $testFile) {
            if (! isset($testTables[$testFile])) {
                $context->selection->add($testFile, new Reason($this->name(), $rel, implode(', ', $changed) . ': a database test with no recorded table'));
            }
        }
    }

    private function selectDatabaseTests(Context $context, string $rel, string $detail): void
    {
        foreach ($context->graph->databaseTestFiles() as $testFile) {
            $context->selection->add($testFile, new Reason($this->name(), $rel, $detail));
        }
    }
}
