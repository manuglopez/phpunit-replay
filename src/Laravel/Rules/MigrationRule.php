<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Rules;

use Manuglopez\Replay\Laravel\MigrationPaths;
use Manuglopez\Replay\Laravel\TableExtractor;
use Manuglopez\Replay\Laravel\TestSchemaDump;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\ResiduePatterns;
use Manuglopez\Replay\Select\Rule;
use Manuglopez\Replay\Support\Paths;

/**
 * Laravel-only rule (SPEC.md §7.2.1). It claims every changed `.php` file under a migration
 * path (`Laravel\MigrationPaths`: `database/migrations`, `migration_paths`, the literal
 * `loadMigrationsFrom()` calls, the tenancy conventions); any other file there (a `.sql` file
 * a migration reads) is left to the fallback patterns for those paths, which run everything
 * (`Select\WatchDefaults\Laravel`). While the graph has recorded no table at all, a migration
 * runs every test, in either mode.
 *
 * `migrations => 'precise'` (the default) decides by whether the test database runs it:
 *
 * - **squashed**: the `migrations` rows of the schema dump the test connection loads list it
 *   (`Laravel\TestSchemaDump`). No test database runs it, so it selects nothing on its own
 *   account, and `explain` says so. Regenerating the dump is a dump change
 *   (`SchemaDumpRule`). Rows that cannot be read squash nothing;
 * - **pending**: anything else. Every fresh test database runs it whichever tables it names,
 *   and one that throws breaks them all, so it selects every test file that uses a database
 *   (`Cache\Graph::databaseTestFiles()`).
 *
 * `migrations => 'conservative'` keeps 0.12's rule, over the tables every database test was
 * widened to (`LaravelIntegration::augment()`): the test files whose recorded tables intersect
 * the migration's; every test that records a table when no test records one of them; every
 * test when it names none; every database test when it names a table it cannot read
 * (`Schema::create($tableNames['roles'])`, `DB::table($table)`), whatever else it names.
 */
final class MigrationRule implements Rule
{
    private readonly MigrationPaths $paths;

    /** @var array<string, true>|false|null false until read; null when nothing is squashed */
    private array|false|null $squashed = false;

    private ?string $squashedInto = null;

    public function __construct(?MigrationPaths $paths = null, private readonly string $mode = 'precise')
    {
        $this->paths = $paths ?? MigrationPaths::default();
    }

    public function name(): string
    {
        return 'Migration';
    }

    public function paths(): MigrationPaths
    {
        return $this->paths;
    }

    public function conservative(): bool
    {
        return $this->mode === 'conservative';
    }

    public function apply(Context $context): void
    {
        $testTables = $context->graph->testTables();
        $this->squashed = false;

        foreach ($context->remaining as $rel) {
            if (! $this->paths->isMigration($rel)) {
                continue;
            }

            $context->consume($rel);

            // A graph with no table recorded yet says nothing about who uses a database.
            if ($testTables === []) {
                $this->selectEveryTest($context, $rel, 'no tables to narrow by');

                continue;
            }

            if ($this->conservative()) {
                $this->byTable($context, $rel, $testTables);

                continue;
            }

            $squashedInto = $this->squashedInto($context, $rel);

            if ($squashedInto !== null) {
                $context->selection->note(new Reason($this->name(), $rel, 'squashed into ' . $squashedInto . ', not run by tests'));

                continue;
            }

            $this->selectDatabaseTests($context, $rel, 'pending migration: every database test');
        }
    }

    /** The dump a migration is squashed into, or null when the test database runs it. */
    private function squashedInto(Context $context, string $rel): ?string
    {
        if ($this->squashed === false) {
            $this->squashed = TestSchemaDump::squashed($context->projectRoot, $context->graph->configuration());
            $this->squashedInto = $this->squashed === null ? null : TestSchemaDump::path($context->projectRoot, $context->graph->configuration());
        }

        return $this->squashed !== null && isset($this->squashed[TestSchemaDump::migrationName($rel)]) ? $this->squashedInto : null;
    }

    /** @param array<string, list<string>> $testTables */
    private function byTable(Context $context, string $rel, array $testTables): void
    {
        $read = self::tablesForMigration($rel, $context->projectRoot);
        $tables = $read['tables'];

        if ($read['unresolved']) {
            $this->selectDatabaseTests($context, $rel, 'tables it cannot name: every database test');

            return;
        }

        // Nothing to narrow by: a migration with no readable table (deleted, raw statements,
        // a data migration). Every test runs.
        if ($tables === []) {
            $this->selectEveryTest($context, $rel, 'no tables to narrow by');

            return;
        }

        $lowerTables = array_map(strtolower(...), $tables);
        $matched = false;

        foreach ($testTables as $testFile => $testFileTables) {
            foreach ($testFileTables as $table) {
                // `*`: tables nobody could name; `@t`: written while the database was built.
                if ($table === TableExtractor::UNKNOWN || in_array(ltrim($table, TableExtractor::BOOTSTRAP), $lowerTables, true)) {
                    $context->selection->add((string) $testFile, new Reason($this->name(), $rel, implode(', ', $tables)));
                    $matched = true;

                    break;
                }
            }
        }

        // Tables no test records (a new `widgets` table): nobody queries them yet, and the
        // migration still runs for every test that migrates a database.
        if (! $matched) {
            foreach (array_keys($testTables) as $testFile) {
                $context->selection->add((string) $testFile, new Reason($this->name(), $rel, 'tables no test records: every database test'));
            }
        }
    }

    private function selectEveryTest(Context $context, string $rel, string $detail): void
    {
        $targets = ResiduePatterns::targetsFor($context->testPaths);

        foreach ($context->watch->testsUnderDirectories($targets, $context->graph->allTestFiles()) as $testFile) {
            $context->selection->add($testFile, new Reason($this->name(), $rel, $detail));
        }
    }

    private function selectDatabaseTests(Context $context, string $rel, string $detail): void
    {
        foreach ($context->graph->databaseTestFiles() as $testFile) {
            $context->selection->add($testFile, new Reason($this->name(), $rel, $detail));
        }
    }

    /** @return array{tables: list<string>, unresolved: bool} */
    public static function tablesForMigration(string $rel, string $projectRoot): array
    {
        $absolute = Paths::join($projectRoot, $rel);
        $content = is_file($absolute) ? @file_get_contents($absolute) : false;

        if ($content === false) {
            return ['tables' => [], 'unresolved' => false];
        }

        return TableExtractor::migrationTables($content);
    }
}
