<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\Rules\BladeRule;
use Manuglopez\Replay\Laravel\Rules\MigrationRule;
use Manuglopez\Replay\Laravel\Rules\SchemaDumpRule;
use Manuglopez\Replay\Laravel\Rules\SiblingRule;
use Manuglopez\Replay\Laravel\Subscribers\ArmLaravelTrackersOnPreparationStarted;
use Manuglopez\Replay\Laravel\Subscribers\ArmLaravelTrackersOnPrepared;
use Manuglopez\Replay\Laravel\Subscribers\FlushUsesDatabaseOnExecutionFinished;
use Manuglopez\Replay\PHPUnit\ReplayState;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Select\Rule;
use PHPUnit\Event\Subscriber;

/**
 * Single entry point for the optional Laravel integration (SPEC.md §10, docs/INTERNALS.md
 * "Laravel"). Everything the wrapper/extension wiring needs lives here: the extra
 * `Select\Rule`s in SPEC order, the PHPUnit subscribers that arm the trackers and record
 * `uses_database.json`, and the post-processing step that widens a database test's tables to
 * every table any migration touches.
 */
final class LaravelIntegration
{
    /** Container class exists and the project has an `artisan` file at its root. */
    public static function shouldArm(string $projectRoot): bool
    {
        return class_exists(\Illuminate\Container\Container::class)
            && is_file(rtrim($projectRoot, '/') . '/artisan');
    }

    /**
     * @return array{migration: Rule, schema: Rule, sibling: Rule, blade: Rule} inserted per
     *         SPEC.md §7.2 order: Migration and SchemaDump first, Sibling/Blade after TestFile
     *         and before Watch. `$graph` is part of the contract for symmetry with the rest of
     *         the chain, but unused here — these rules read it from the `Context` each
     *         `apply()` call carries. `$projectRoot` and `$config` say where the migrations
     *         are and how they select (`MigrationPaths`, `migrations`); `$stateDir` is where
     *         `BladeRule` keeps its template references across runs.
     */
    public static function rules(Graph $graph, string $projectRoot, ?string $stateDir = null, ?Config $config = null): array
    {
        $config ??= Config::defaults();

        return [
            'migration' => new MigrationRule(MigrationPaths::for($projectRoot, $config), $config->migrations),
            'schema' => new SchemaDumpRule($config->schemaDump),
            'sibling' => new SiblingRule(),
            'blade' => new BladeRule(BladeRule::cacheFileIn($stateDir)),
        ];
    }

    /**
     * Convenience wrapper around {@see LaravelDetector::enabled()} and {@see self::rules()}
     * for the three call sites that need "the Laravel rules, or none" in one step
     * (`Select\RunListBuilder`/`Console\Runner\RunPipeline`, `PHPUnit\ReplayState::bootInProcess()`
     * via its `prepareReplay()`, `Console\Commands\ExplainCommand`).
     *
     * @return array{migration: Rule, schema: Rule, sibling: Rule, blade: Rule}|array{}
     */
    public static function rulesFor(Graph $graph, string $projectRoot, Config $config, ?string $stateDir = null): array
    {
        return LaravelDetector::enabled($projectRoot, $config) ? self::rules($graph, $projectRoot, $stateDir, $config) : [];
    }

    /** @return list<Subscriber> */
    public static function subscribers(Recorder $recorder, string $projectRoot): array
    {
        $usesDatabase = new UsesDatabaseCollector();
        ReplayState::collectUsesDatabase($usesDatabase);

        $arming = new ArmLaravelTrackersOnPrepared($recorder, $usesDatabase, $projectRoot);

        return [
            $arming,
            new FlushUsesDatabaseOnExecutionFinished(ReplayState::runWriter(), $usesDatabase),
            new ArmLaravelTrackersOnPreparationStarted($arming),
        ];
    }

    /**
     * The in-process extension's (`PHPUnit\ReplayExtension`, no wrapper): the same trackers
     * and the same uses-database collector, which `ReplayState::persistInProcess()` reads
     * directly, so nothing is written to a run directory. Before 0.13 the in-process path
     * registered none of them and recorded no table and no template edge.
     *
     * @return list<Subscriber>
     */
    public static function inProcessSubscribers(Recorder $recorder, string $projectRoot): array
    {
        $usesDatabase = new UsesDatabaseCollector();
        ReplayState::collectUsesDatabase($usesDatabase);

        $arming = new ArmLaravelTrackersOnPrepared($recorder, $usesDatabase, $projectRoot);

        return [$arming, new ArmLaravelTrackersOnPreparationStarted($arming)];
    }

    /**
     * `migrations => 'conservative'` only: widens the recorded tables of every database-using
     * test file (`$partial->usesDatabase`, written from `MigrationTables::usesDatabase()` while
     * the test classes were loaded) to include every table any migration in the project
     * touches — any migration might affect any database test (SPEC.md §10). The default,
     * `precise`, keeps the tables each test file recorded: which files use a database is
     * stored apart (`Cache\Graph::usesDatabase()`), so the rules can still reach them all.
     */
    public static function augment(RunPartial $partial, string $projectRoot, Config $config): RunPartial
    {
        if ($config->migrations !== 'conservative' || $partial->usesDatabase === []) {
            return $partial;
        }

        $migrationTables = MigrationTables::tablesOf($projectRoot);

        if ($migrationTables === []) {
            return $partial;
        }

        $tables = $partial->tables;

        foreach ($partial->usesDatabase as $testFile) {
            $existing = $tables[$testFile] ?? [];
            $merged = array_values(array_unique(array_merge($existing, $migrationTables)));
            sort($merged);
            $tables[$testFile] = $merged;
        }

        // Named arguments: RunPartial has grown fields since this call was first written
        // (notCacheable, SPEC.md §8; coverage, SPEC.md §3.2 last paragraph) and a positional
        // rebuild silently drops whichever one is newest — see
        // LaravelIntegrationTest::test_augment_preserves_not_cacheable_entries.
        return new RunPartial(
            edges: $partial->edges,
            results: $partial->results,
            tables: $tables,
            meta: $partial->meta,
            usesDatabase: $partial->usesDatabase,
            notCacheable: $partial->notCacheable,
            coverage: $partial->coverage,
        );
    }
}
