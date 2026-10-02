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
 * {@see SchemaDump::isDumpPath()}). `RefreshDatabase` builds the test database from it, then
 * runs only the migrations its `migrations` rows do not list. Coverage never credits it to a
 * test, and it is not a migration, so before 0.13 nothing claimed it and a change to it ran
 * nothing.
 *
 * The dump at the change set's base (`Context::$base`, read with `git show`) is compared with
 * the working tree's, statement by statement ({@see SchemaDump}: comments and whitespace do
 * not count). With `schema_dump => 'conservative'` (the default), any other change selects
 * every test file that uses a database (`Cache\Graph::databaseTestFiles()`): a dump is the
 * whole database every such test runs on, and what a change to it reaches through foreign
 * keys, triggers, views, routines and the data migrations it squashes is more than a diff can
 * say for certain.
 *
 * `schema_dump => 'per-table'` narrows, table by table:
 *
 * - the `migrations` rows changed: which migrations run in a test database changed (a data
 *   migration squashed by regenerating the dump stops running, since a dump holds no data), so
 *   every database test file;
 * - a statement no table owns changed (a function, a type), or a tie a trigger or routine
 *   makes cannot be followed: every database test file;
 * - otherwise the changed tables, closed over what ties them to others
 *   ({@see SchemaDump::related()}: foreign keys both ways, a trigger's or a view's tables), in
 *   the old dump and the new one. Every database test file when that reaches a table the
 *   database is built with (`Cache\Graph::bootstrapTables()`: a migration's or a seeder's
 *   writes); else the files whose recorded tables meet it, plus every database test file with
 *   no recorded table or with tables nobody could name.
 *
 * Either way, where it cannot compare: a dump that does not parse (a `pg_dump -Fc` archive
 * included), no version at the base, or no base selects every database test file; a graph
 * with no table recorded yet, every test. The rule always consumes the dump.
 */
final class SchemaDumpRule implements Rule
{
    public function __construct(private readonly string $mode = 'conservative')
    {
    }

    public function name(): string
    {
        return 'SchemaDump';
    }

    public function perTable(): bool
    {
        return $this->mode === 'per-table';
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
        if ($context->graph->testTables() === []) {
            $targets = ResiduePatterns::targetsFor($context->testPaths);

            foreach ($context->watch->testsUnderDirectories($targets, $context->graph->allTestFiles()) as $testFile) {
                $context->selection->add($testFile, new Reason($this->name(), $rel, 'no tables to narrow by'));
            }

            return;
        }

        $absolute = Paths::join($context->projectRoot, $rel);
        $newContent = is_file($absolute) ? @file_get_contents($absolute) : null;
        $new = $newContent === null ? SchemaDump::none() : ($newContent === false ? null : SchemaDump::parse($newContent));
        $oldContent = $context->base === null ? null : (new Git($context->projectRoot))->show($context->base, $rel);

        if ($oldContent === null) {
            $this->selectDatabaseTests($context, $rel, 'no earlier version to compare: every database test');

            return;
        }

        $old = SchemaDump::parse($oldContent);

        if ($new === null || $old === null) {
            $this->selectDatabaseTests($context, $rel, 'cannot be read: every database test');

            return;
        }

        if ($old->normalisedHash() === $new->normalisedHash()) {
            $context->selection->note(new Reason($this->name(), $rel, 'comments, whitespace or migration ids only'));

            return;
        }

        if (! $this->perTable()) {
            $this->selectDatabaseTests($context, $rel, 'changed: every database test');

            return;
        }

        if ($old->rowsHash() !== $new->rowsHash()) {
            $this->selectDatabaseTests($context, $rel, 'migration rows changed: which migrations run changed, every database test');

            return;
        }

        if (SchemaDump::globalChanged($old, $new)) {
            $this->selectDatabaseTests($context, $rel, 'a statement no table owns changed: every database test');

            return;
        }

        $changed = SchemaDump::changedTables($old, $new);
        $reached = SchemaDump::related($changed, $old, $new);

        if ($reached === [SchemaDump::EVERY_TABLE]) {
            $this->selectDatabaseTests($context, $rel, 'a trigger or routine nobody can follow: every database test');

            return;
        }

        $reachedSet = array_fill_keys($reached, true);
        $bootstrap = array_values(array_filter($context->graph->bootstrapTables(), static fn (string $table): bool => isset($reachedSet[$table])));

        if ($bootstrap !== []) {
            $this->selectDatabaseTests($context, $rel, 'tables the database is built with (' . implode(', ', $bootstrap) . '): every database test');

            return;
        }

        $graph = $context->graph;

        foreach ($graph->databaseTestFiles() as $testFile) {
            $queried = $graph->queriedTables($testFile);

            if ($graph->tablesUnknown($testFile) || $queried === []) {
                $context->selection->add($testFile, new Reason($this->name(), $rel, implode(', ', $changed) . ': a database test whose tables are not all known'));

                continue;
            }

            $hit = array_values(array_filter($queried, static fn (string $table): bool => isset($reachedSet[$table])));

            if ($hit !== []) {
                $context->selection->add($testFile, new Reason($this->name(), $rel, implode(', ', $hit)));
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
