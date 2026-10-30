<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\Rules\BladeRule;
use Manuglopez\Replay\Laravel\Rules\MigrationRule;
use Manuglopez\Replay\Laravel\Rules\SchemaDumpRule;
use Manuglopez\Replay\Laravel\Rules\SiblingRule;
use Manuglopez\Replay\Laravel\Rules\TranslationRule;
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
     * @return array{migration: Rule, schema: Rule, sibling: Rule, blade: Rule, translation?: Rule} inserted per
     *         SPEC.md §7.2 order: Migration and SchemaDump first, Sibling/Blade/Translation
     *         after TestFile and before Watch. `$graph` is part of the contract for symmetry
     *         with the rest of the chain, but unused here — these rules read it from the
     *         `Context` each `apply()` call carries. `$projectRoot` and `$config` say where the
     *         migrations are and how they select (`MigrationPaths`, `migrations`); `$stateDir`
     *         is where `BladeRule` keeps its template references across runs.
     *         `TranslationRule` only with `translations => 'per-key'` (the default): with
     *         `conservative` no key is recorded, and `lang/**` stays a watch default
     *         (`Select\WatchDefaults\Laravel`) that runs every test.
     */
    public static function rules(Graph $graph, string $projectRoot, ?string $stateDir = null, ?Config $config = null): array
    {
        $config ??= Config::defaults();

        $rules = [
            'migration' => new MigrationRule(MigrationPaths::for($projectRoot, $config), $config->migrations),
            'schema' => new SchemaDumpRule($config->schemaDump),
            'sibling' => new SiblingRule(),
            'blade' => new BladeRule(BladeRule::cacheFileIn($stateDir)),
        ];

        if ($config->translations === 'per-key') {
            $rules['translation'] = new TranslationRule();
        }

        return $rules;
    }

    /**
     * Convenience wrapper around {@see LaravelDetector::enabled()} and {@see self::rules()}
     * for the three call sites that need "the Laravel rules, or none" in one step
     * (`Select\RunListBuilder`/`Console\Runner\RunPipeline`, `PHPUnit\ReplayState::bootInProcess()`
     * via its `prepareReplay()`, `Console\Commands\ExplainCommand`).
     *
     * @return array{migration: Rule, schema: Rule, sibling: Rule, blade: Rule, translation?: Rule}|array{}
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

        $arming = new ArmLaravelTrackersOnPrepared($recorder, $usesDatabase, $projectRoot, self::tracksTranslations($projectRoot));

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

        $arming = new ArmLaravelTrackersOnPrepared($recorder, $usesDatabase, $projectRoot, self::tracksTranslations($projectRoot));

        return [$arming, new ArmLaravelTrackersOnPreparationStarted($arming)];
    }

    /**
     * Whether the translation keys each test resolves are recorded: `translations` is
     * `per-key` (the default; `conservative` arms nothing, `lang/**` keeps selecting every
     * test) and the Laravel integration is on (`laravel => 'off'` arms nothing). Read from
     * `phpunit-replay.php`, like `Cache\Fingerprint` does, so the wrapper's child, the
     * in-process extension and the fingerprint always agree.
     */
    private static function tracksTranslations(string $projectRoot): bool
    {
        $config = Config::load($projectRoot);

        return $config->translations === 'per-key' && LaravelDetector::enabled($projectRoot, $config);
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
        if ($config->translations === 'per-key') {
            $partial = self::withoutLanguageEdges($partial);
        }

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
            translations: $partial->translations,
        );
    }

    /**
     * `translations => 'per-key'` only: removes the coverage edges to language files
     * (`LangFiles::classify() !== null`) before they reach the graph.
     *
     * Laravel's `FileLoader` `require`s `lang/<locale>/<group>.php`, so coverage credits the
     * group file to every test that loaded it, the fallback locale's included. Kept, that
     * edge makes `Select\Rules\PhpEdge` select every such test on any change to the group,
     * and it is part of `Cache\ContentKey`, so the result of a test the per-key selection
     * left out would not be served either: per key would degrade to per file. Language files
     * are covered by `Rules\TranslationRule` (selection) and by the `trans@1`, `lang@1` and
     * `lang:uncomparable@1` scopes of `Select\NonEdgeInputs` (stamp) instead.
     *
     * One exception keeps the edge, so a file read some other way is never lost: a PHP
     * group file that the test loaded without its translator resolving any key of that
     * group in that locale (nor `*`) — a test that `require`s the file itself, or a loader
     * this package cannot see. Such a test keeps today's per-file behaviour for that file.
     * `vendor` and `other` files always go: the rule selects every test for any change to
     * them.
     */
    private static function withoutLanguageEdges(RunPartial $partial): RunPartial
    {
        $edges = [];
        $dropped = false;

        foreach ($partial->edges as $testFile => $sources) {
            $kept = [];

            foreach ($sources as $source) {
                $path = LangFiles::classify($source);

                if ($path === null || self::readOutsideTheTranslator($path, $partial->translations[$testFile] ?? [])) {
                    $kept[] = $source;

                    continue;
                }

                $dropped = true;
            }

            $edges[$testFile] = $kept;
        }

        if (! $dropped) {
            return $partial;
        }

        return new RunPartial(
            edges: $edges,
            results: $partial->results,
            tables: $partial->tables,
            meta: $partial->meta,
            usesDatabase: $partial->usesDatabase,
            notCacheable: $partial->notCacheable,
            coverage: $partial->coverage,
            translations: $partial->translations,
        );
    }

    /**
     * Whether a test that executed the PHP group file `$path` resolved no key of that group
     * in that locale (`$entries`, its `<locale>:<key>` translations) and is not marked `*`.
     *
     * @param list<string> $entries
     */
    private static function readOutsideTheTranslator(LangPath $path, array $entries): bool
    {
        if ($path->kind !== LangPath::PHP || in_array('*', $entries, true)) {
            return false;
        }

        $prefix = $path->locale . ':';

        foreach ($entries as $entry) {
            if (str_starts_with($entry, $prefix) && LangFiles::matches(substr($entry, strlen($prefix)), (string) $path->group)) {
                return false;
            }
        }

        return true;
    }
}
