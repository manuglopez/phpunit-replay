<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\PHPUnit\ReplayState;
use Manuglopez\Replay\PHPUnit\Subscribers\ArmLaravelTrackersOnPrepared;
use Manuglopez\Replay\PHPUnit\Subscribers\FlushUsesDatabaseOnExecutionFinished;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Select\Rule;
use Manuglopez\Replay\Select\Rules\BladeRule;
use Manuglopez\Replay\Select\Rules\MigrationRule;
use Manuglopez\Replay\Select\Rules\SiblingRule;
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
     * @return array{migration: Rule, sibling: Rule, blade: Rule} inserted per SPEC.md §7.2
     *         order: Migration first, Sibling/Blade after TestFile and before Watch. `$graph`
     *         and `$projectRoot` are part of the contract for symmetry with the rest of the
     *         chain, but unused here — these rules read both from the `Context` each
     *         `apply()` call carries, not at construction time.
     */
    public static function rules(Graph $graph, string $projectRoot): array
    {
        return [
            'migration' => new MigrationRule(),
            'sibling' => new SiblingRule(),
            'blade' => new BladeRule(),
        ];
    }

    /**
     * Convenience wrapper around {@see LaravelDetector::enabled()} and {@see self::rules()}
     * for the three call sites that need "the Laravel rules, or none" in one step
     * (`Select\RunListBuilder`/`Console\Runner\RunPipeline`, `PHPUnit\ReplayState::bootInProcess()`
     * via its `prepareReplay()`, `Console\Commands\ExplainCommand`).
     *
     * @return array{migration: Rule, sibling: Rule, blade: Rule}|array{}
     */
    public static function rulesFor(Graph $graph, string $projectRoot, Config $config): array
    {
        return LaravelDetector::enabled($projectRoot, $config) ? self::rules($graph, $projectRoot) : [];
    }

    /** @return list<Subscriber> */
    public static function subscribers(Recorder $recorder): array
    {
        $usesDatabase = new UsesDatabaseCollector();

        return [
            new ArmLaravelTrackersOnPrepared($recorder, $usesDatabase),
            new FlushUsesDatabaseOnExecutionFinished(ReplayState::runWriter(), $usesDatabase),
        ];
    }

    /**
     * Widens the recorded tables of every database-using test file (`$partial->usesDatabase`,
     * written from `MigrationTables::usesDatabase()` while the test classes were loaded) to
     * include every table any migration in the project touches — conservative: any migration
     * might affect any database test (SPEC.md §10).
     */
    public static function augment(RunPartial $partial, string $projectRoot): RunPartial
    {
        if ($partial->usesDatabase === []) {
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
