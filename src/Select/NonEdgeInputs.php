<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\BladeReferences;
use Manuglopez\Replay\Laravel\LaravelIntegration;
use Manuglopez\Replay\Laravel\MigrationPaths;
use Manuglopez\Replay\Laravel\Rules\BladeRule;
use Manuglopez\Replay\Laravel\Rules\MigrationRule;
use Manuglopez\Replay\Laravel\Rules\SchemaDumpRule;
use Manuglopez\Replay\Laravel\Rules\SiblingRule;
use Manuglopez\Replay\Laravel\SchemaDump;
use Manuglopez\Replay\Laravel\TableExtractor;
use Manuglopez\Replay\Laravel\TestSchemaDump;
use Manuglopez\Replay\PHPUnit\ConfigurationWriter;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Support\Paths;

/**
 * The non-edge input digest of a test file: the one place it is defined.
 *
 * A content key (`Cache\ContentKey`) covers the test file and every file it executed. The
 * rule chain also selects a test file for files that are not among those: a watched file it
 * reads, a migration of a table it uses, a template a template it renders may include, a new
 * sibling of a class it depends on, and a `.php` file nothing can attribute (the residue:
 * excluded from coverage by `<source><exclude>`, or, with `static_declaration_edges`, without
 * an edge). Those are the test file's non-edge inputs, and this class hashes them. A result
 * stamped with the key and this digest, both recomputed equal on the current tree, ran on the
 * same inputs as far as the rule chain can tell (`Select\StampAudit`).
 *
 * The inputs are grouped into **scopes**, named and versioned file-set predicates, as in
 * `docs/proposals/self-describing-objects.md` ("Non-edge inputs: they become part of the
 * object"), over the working tree: `git ls-files -co --exclude-standard`, minus what git
 * ignores, minus files that do not exist, minus what this package writes.
 *
 * | scope | the rule it stands for | members, for test file T | carried by T when |
 * |---|---|---|---|
 * | `watch:<pattern>@3` | `Rules\WatchRule`, configured patterns (additive) | files matching the pattern | T is under one of its targets |
 * | `unattributable@3` | `ResiduePatterns::isUnattributable()` (additive) | `.php` files `<source><exclude>` keeps out of coverage, whatever watch pattern also names them | T is under the residue targets |
 * | `blade@3` | `Laravel\Rules\BladeRule` (additive) | templates the templates T depends on reference, transitively | always |
 * | `migrations:pending@1` | `Laravel\Rules\MigrationRule`, `precise` | migrations the test connection's schema dump does not list (squashed ones are nobody's input) | T uses a database (`Graph::databaseTestFiles()`) |
 * | `migrations@3` | `Laravel\Rules\MigrationRule`, `conservative` | migrations whose tables intersect T's | always |
 * | `migrations:untouched@1` | `Laravel\Rules\MigrationRule`, `conservative` | migrations whose tables no test records | T records any table |
 * | `migrations:unknown@1` | `Laravel\Rules\MigrationRule`, `conservative` | migrations naming a table they cannot read | T uses a database |
 * | `migrations:unnarrowed@1` | `Laravel\Rules\MigrationRule` | migrations with no table to narrow by (`conservative`), or every one while the graph records no table | T is under the residue targets |
 * | `schema:<dump>@1` | `Laravel\Rules\SchemaDumpRule` | the normalised dump (`conservative`); with `per-table`, the rows, the statements no table owns and the blocks of what `SchemaDump::related()` ties to T's queried tables and to the build tables (every block when T's tables are not all known); the whole file when it does not parse, or while the graph records no table | T uses a database (under the residue targets, while the graph records no table) |
 * | `sibling:<dir>@3` | `Laravel\Rules\SiblingRule` | sibling candidates in `<dir>` no test has an edge to | T depends on a file in `<dir>` |
 * | `sibling-tree:<dir>@1` | `Laravel\Rules\SiblingRule`, a new subdirectory | sibling candidates whose own directory no test has an edge into, and whose nearest ancestor that one has is `<dir>` | T depends on a file under `<dir>` |
 * | `fallback:<pattern>@1` | `Rules\WatchRule`, fallback patterns (the Laravel `resources/views/**` and `database/migrations/**` while the Laravel rules run) | files matching the pattern that no rule claims | T is under one of its targets |
 * | `residue@3` | `ResiduePatterns`, `static_declaration_edges` only | `.php` files no test has an edge to that no rule claims | T is under the residue targets |
 *
 * **Every member set excludes T's own dependencies and T itself**: those are T's key inputs,
 * and the key already covers them. For the first four scopes what a scope holds for T is a
 * function of the tree and of T's own edges and tables, and of nothing another test records:
 * another test gaining or losing an edge never moves them; T gaining one moves that file from
 * its scope into its key, and re-runs T alone, once. That is consistent with selection because
 * the rules behind them are additive: a configured watch pattern, a file coverage cannot see,
 * a Blade reference apply to a changed file whatever other rule, or other test's edge,
 * already claimed it.
 *
 * The others stay relative to what the graph records, by what they mean. `SiblingRule`, the
 * fallback patterns and the flag's residue are about files nothing attributes yet: when such a
 * file gains its first edge (or a template gains a rendered ancestor), it leaves the scope,
 * and the test files that carried it re-run once — in the common case the pass that ran them
 * all for the new file anyway. `migrations:untouched@1` moves when some test records its
 * table for the first time. The steps that decide it are {@see self::inUniverse()} and
 * {@see self::claimedByBlade()}.
 *
 * ```
 * digest(T)       = "n4:" . xxh128("nonedge@4\n" . Σ sorted "<scope id>=<scope digest>\n")
 *                   over the scopes T carries that have at least one member
 * scope digest(T) = hex(⊕ xxh128(path . "\0" . ContentHash(path))) . ":" . |members|
 * ```
 *
 * A scope digest is an XOR over its members, so a scope shared by hundreds of test files is
 * hashed once and each test file's own dependencies are taken out of it in constant time per
 * dependency. A `schema:` scope's members are its blocks, each hashed with the dump's path and
 * its table, so that a test file's digest moves only for a block it carries. `n4` (0.13) is
 * the version token: anything that changes what a digest means changes
 * it, and a stamp with another version never matches. A null digest means it could not be
 * computed (git failed): nothing is stamped with it and nothing stamped validates against it.
 *
 * One instance per graph per pass. Every file is hashed through the pass's {@see FileHashes},
 * and the tree listing, the watch matches, the migration tables and the template references
 * are read once (the references persist, keyed by template content, under the state
 * directory). Call {@see self::refresh()} after the graph's edges or tables change
 * (`Cache\GraphUpdater`).
 */
final class NonEdgeInputs
{
    public const VERSION = 'n4';

    private const MATERIAL = "nonedge@4\n";

    /** @var list<string>|null|false present files, false until listed, null when git failed */
    private array|false|null $tree = false;

    /** @var array<string, array<string, list<string>>> path => configured pattern => targets */
    private array $watchMatches = [];

    /** @var array<string, array<string, list<string>>> path => fallback pattern => targets */
    private array $fallbackMatches = [];

    /** @var array<string, array{tables: list<string>, unresolved: bool}> migration path => its tables, as read */
    private array $migrationTables = [];

    /** @var array<string, ?SchemaDump> "<dump>\0<content hash>" => the parsed dump, null when it does not parse */
    private array $dumps = [];

    /** @var array<string, list<string>>|null template => templates it references */
    private ?array $bladeMap = null;

    /** @var array<string, true> tracked files git ignores, among the ones asked about */
    private array $ignored = [];

    /** @var array<string, true> every path {@see self::ignoredAmong()} has asked git about */
    private array $asked = [];

    /** @var array<string, string> path => its member hash (binary xxh128) */
    private array $elements = [];

    /**
     * @var array{
     *     shared: array<string, array{acc: string, count: int, members: list<string>}>,
     *     memberOf: array<string, list<string>>,
     *     watchTargets: array<string, list<string>>,
     *     migrationsByTable: array<string, list<string>>,
     *     templates: array<string, true>,
     *     members: array<string, true>,
     *     databaseFiles: array<string, true>,
     *     dumps: array<string, array{whole: ?string, normalised: string, rows: string, global: ?string, blocks: array<string, string>, dump: ?SchemaDump, perTable: bool, carriedByAll: bool}>,
     *     bootstrap: list<string>,
     * }|null|false false until computed, null when the tree could not be listed
     */
    private array|false|null $state = false;

    /** @var array<string, ?string> */
    private array $digests = [];

    /** @var array<string, bool> scope id => every member stable ({@see FileHashes::stable()}) */
    private array $scopeStable = [];

    /** @var list<string> */
    private readonly array $residueTargets;

    private readonly ?string $stateDirRel;

    private readonly ResiduePatterns $residue;

    /**
     * @param array{migration?: Rule, schema?: Rule, sibling?: Rule, blade?: Rule} $extraRules the
     *        Laravel rules the pass runs, as for {@see RunListBuilder}: their presence, and the
     *        migration rule's paths and mode
     * @param WatchPatterns $watch the configured patterns only — never an instance
     *        {@see RunListBuilder::build()} has added the residue of a change set to
     * @param SourceScope|null $scope the coverage scope, for the `unattributable@3` scope
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly TestPaths $testPaths,
        private readonly WatchPatterns $watch,
        private readonly string $projectRoot,
        private readonly FileHashes $hashes,
        private readonly Git $git,
        private readonly array $extraRules = [],
        private readonly bool $staticDeclarationEdges = false,
        private readonly ?string $stateDir = null,
        ?SourceScope $scope = null,
    ) {
        $this->residueTargets = ResiduePatterns::targetsFor($testPaths);
        $this->stateDirRel = $stateDir !== null ? Paths::relative($projectRoot, $stateDir) : null;
        $this->residue = new ResiduePatterns($graph, $testPaths, false, $scope);
    }

    /** The configured watch patterns and Laravel rules of this project, as a pass builds them. */
    public static function forProject(
        Graph $graph,
        string $projectRoot,
        Config $config,
        TestPaths $testPaths,
        FileHashes $hashes,
        Git $git,
        ?string $stateDir = null,
        ?SourceScope $scope = null,
    ): self {
        $watch = WatchPatterns::forProject($projectRoot, $testPaths->directories(), $config);

        return new self(
            $graph,
            $testPaths,
            $watch,
            $projectRoot,
            $hashes,
            $git,
            LaravelIntegration::rulesFor($graph, $projectRoot, $config, $stateDir),
            $config->staticDeclarationEdges,
            $stateDir,
            $scope,
        );
    }

    /** The graph's edges or tables changed: scopes are recomputed, files are not re-read. */
    public function refresh(): void
    {
        $this->state = false;
        $this->digests = [];
        $this->scopeStable = [];
    }

    /** Null when the working tree could not be listed. */
    public function digestFor(string $testFile): ?string
    {
        if (array_key_exists($testFile, $this->digests)) {
            return $this->digests[$testFile];
        }

        $scopes = $this->scopesOf($testFile);

        if ($scopes === null) {
            return $this->digests[$testFile] = null;
        }

        ksort($scopes, SORT_STRING);
        $material = self::MATERIAL;

        foreach ($scopes as $id => $scope) {
            $material .= $id . '=' . bin2hex($scope['acc']) . ':' . $scope['count'] . "\n";
        }

        return $this->digests[$testFile] = self::VERSION . ':' . hash('xxh128', $material);
    }

    /**
     * {@see self::digestFor()}, for a stamp: null when a file that went into it may not be the
     * content the tests ran on ({@see FileHashes::stable()}), so that nothing records a digest
     * the tree did not have while they ran.
     */
    public function stampFor(string $testFile): ?string
    {
        $digest = $this->digestFor($testFile);
        $scopes = $digest === null ? null : $this->scopesOf($testFile);

        if ($scopes === null) {
            return null;
        }

        foreach ($scopes as $id => $scope) {
            foreach ($scope['members'] ?? [] as $member) {
                if (! $this->hashes->stable($member)) {
                    return null;
                }
            }

            if (! ($this->scopeStable[$id] ??= $this->allStable($id))) {
                return null;
            }
        }

        return $digest;
    }

    /**
     * Whether a change to `$relative` — an edit, an addition or a deletion — could change what
     * some result was recorded against: a test file, a file of the graph's universe, or a path
     * some scope would hold if the file existed. Decided from the path alone, so a deleted
     * file answers the same as the file did. What a clean tree means when a graph is about to
     * be published (`Cache\GraphPublication`).
     */
    public function covers(string $relative): bool
    {
        if ($this->testPaths->isTestFile($relative) || $this->graph->isDependency($relative) || $this->watchMatches($relative) !== []) {
            return true;
        }

        if (isset($this->extraRules['migration']) && $this->migrationPaths()->covers($relative)) {
            return true;
        }

        if (isset($this->extraRules['schema']) && SchemaDump::isDumpPath($relative)) {
            return true;
        }

        if (isset($this->extraRules['blade']) && BladeReferences::isBladePath($relative)) {
            return true;
        }

        if (isset($this->extraRules['sibling']) && SiblingRule::isSiblingCandidate($relative)) {
            return true;
        }

        if ($this->staticDeclarationEdges && ResiduePatterns::hasResidueShape($relative, $this->testPaths)) {
            return true;
        }

        return $this->residue->isUnattributable($relative);
    }

    /**
     * The scopes `$testFile` carries that hold something, each as the XOR of its members'
     * hashes and their count; the ones held for this test file alone also list their members.
     *
     * @return array<string, array{acc: string, count: int, members?: list<string>}>|null
     */
    private function scopesOf(string $testFile): ?array
    {
        $state = $this->state();

        if ($state === null) {
            return null;
        }

        $own = [];

        foreach ([...$this->graph->dependenciesOf($testFile), $testFile] as $path) {
            $own[$path] = true;
        }

        $tables = array_fill_keys($this->graph->testTables()[$testFile] ?? [], true);
        $underResidueTargets = $this->watch->testsUnderDirectories($this->residueTargets, [$testFile]) !== [];
        $usesDatabase = isset($state['databaseFiles'][$testFile]);
        $scopes = [];

        foreach ($state['shared'] as $id => $scope) {
            if ($this->carries($testFile, (string) $id, $state, $own, $underResidueTargets, $tables !== [], $usesDatabase)) {
                $scopes[$id] = ['acc' => $scope['acc'], 'count' => $scope['count']];
            }
        }

        // Take the test file's own key inputs out of every shared scope that holds them. Not
        // out of `migrations:pending@1`: what it says is whether a test database runs the
        // migration, which the dump's rows decide and no key covers. The test that first ran
        // the migration has an edge to it, and must see it become squashed too.
        foreach (array_keys($own) as $path) {
            foreach ($state['memberOf'][(string) $path] ?? [] as $id) {
                if (isset($scopes[$id]) && $id !== 'migrations:pending@1') {
                    $scopes[$id]['acc'] ^= $this->elements[(string) $path];
                    $scopes[$id]['count']--;
                }
            }
        }

        // MigrationRule, narrowed by the tables this test file was recorded touching.
        $byTable = [];

        foreach ($tables === [] ? [] : $state['migrationsByTable'] as $path => $migrationTables) {
            foreach ($migrationTables as $table) {
                if (isset($tables[$table]) || isset($tables[TableExtractor::BOOTSTRAP . $table]) || isset($tables[TableExtractor::UNKNOWN])) {
                    if (! isset($own[$path])) {
                        $byTable[] = (string) $path;
                    }

                    break;
                }
            }
        }

        if ($byTable !== []) {
            $scopes['migrations@3'] = ['acc' => $this->accumulate($byTable)['acc'], 'count' => count($byTable), 'members' => $byTable];
        }

        // SchemaDumpRule: what of the dump this test file's selection depends on.
        foreach ($state['dumps'] as $dump => $info) {
            $dump = (string) $dump;

            if (isset($own[$dump]) || ! ($info['carriedByAll'] ? $underResidueTargets : $usesDatabase)) {
                continue;
            }

            $parts = $this->schemaParts($testFile, $info, $state['bootstrap']);
            $acc = str_repeat("\0", 16);

            foreach ($parts as $part) {
                $acc ^= $part;
            }

            $scopes['schema:' . $dump . '@1'] = ['acc' => $acc, 'count' => count($parts), 'members' => [$dump]];
        }

        // BladeRule: what the templates this test file renders may include.
        if ($state['templates'] !== []) {
            $roots = [];

            foreach (array_keys($own) as $path) {
                if (isset($state['templates'][(string) $path])) {
                    $roots[] = (string) $path;
                }
            }

            $blade = [];

            foreach (array_keys(BladeReferences::descendantsOf($this->bladeMap ?? [], $roots)) as $template) {
                if (! isset($own[$template]) && isset($state['templates'][$template])) {
                    $blade[] = (string) $template;
                }
            }

            if ($blade !== []) {
                $scopes['blade@3'] = ['acc' => $this->accumulate($blade)['acc'], 'count' => count($blade), 'members' => $blade];
            }
        }

        return array_filter($scopes, static fn (array $scope): bool => $scope['count'] > 0);
    }

    /**
     * @param array{watchTargets: array<string, list<string>>} $state
     * @param array<string, true> $own
     */
    private function carries(string $testFile, string $id, array $state, array $own, bool $underResidueTargets, bool $recordsTables, bool $usesDatabase): bool
    {
        if (isset($state['watchTargets'][$id])) {
            return $this->watch->testsUnderDirectories($state['watchTargets'][$id], [$testFile]) !== [];
        }

        if (str_starts_with($id, 'sibling-tree:')) {
            $dir = substr($id, strlen('sibling-tree:'), -strlen('@1'));

            foreach (array_keys($own) as $path) {
                if (str_starts_with((string) $path, $dir . '/') && (string) $path !== $testFile) {
                    return true;
                }
            }

            return false;
        }

        if ($id === 'migrations:pending@1' || $id === 'migrations:unknown@1') {
            return $usesDatabase;
        }

        if (str_starts_with($id, 'sibling:')) {
            $dir = substr($id, strlen('sibling:'), -strlen('@3'));

            foreach (array_keys($own) as $path) {
                if (dirname((string) $path) === $dir && (string) $path !== $testFile) {
                    return true;
                }
            }

            return false;
        }

        if ($id === 'migrations:untouched@1') {
            return $recordsTables;
        }

        // residue@3, unattributable@3, migrations:unnarrowed@1: the residue targets.
        return $underResidueTargets;
    }

    /**
     * @return array{
     *     shared: array<string, array{acc: string, count: int, members: list<string>}>,
     *     memberOf: array<string, list<string>>,
     *     watchTargets: array<string, list<string>>,
     *     migrationsByTable: array<string, list<string>>,
     *     templates: array<string, true>,
     *     members: array<string, true>,
     *     databaseFiles: array<string, true>,
     *     dumps: array<string, array{whole: ?string, normalised: string, rows: string, global: ?string, blocks: array<string, string>, dump: ?SchemaDump, perTable: bool, carriedByAll: bool}>,
     *     bootstrap: list<string>,
     * }|null
     */
    private function state(): ?array
    {
        if ($this->state !== false) {
            return $this->state;
        }

        $tree = $this->tree();

        if ($tree === null) {
            return $this->state = null;
        }

        $migrationsActive = isset($this->extraRules['migration']);
        $testTables = $this->graph->testTables();
        $recordedTables = [];

        foreach ($testTables as $tables) {
            foreach ($tables as $table) {
                if ($table !== TableExtractor::UNKNOWN) {
                    $recordedTables[ltrim($table, TableExtractor::BOOTSTRAP)] = true;
                }
            }
        }

        $migrationRule = $this->extraRules['migration'] ?? null;
        $migrationPaths = $this->migrationPaths();
        $conservative = $migrationRule instanceof MigrationRule && $migrationRule->conservative();
        $squashed = $migrationsActive && ! $conservative && $testTables !== []
            ? TestSchemaDump::squashed($this->projectRoot, $this->graph->configuration())
            : null;
        $schemaActive = isset($this->extraRules['schema']);
        $dumpPaths = [];

        $siblingsActive = isset($this->extraRules['sibling']);
        $dependentDirs = $siblingsActive ? $this->dependentDirectories() : [];
        $dependencyTree = $siblingsActive ? SiblingRule::dependencyTree($this->graph) : [];
        $bladeActive = isset($this->extraRules['blade']);

        if ($bladeActive) {
            $this->bladeMap ??= BladeReferences::referenceMap($this->projectRoot, BladeRule::cacheFileIn($this->stateDir));
        }

        $claimedByBlade = $bladeActive ? $this->claimedByBlade() : [];

        /** @var array<string, list<string>> $sets scope id => members */
        $sets = [];
        $watchTargets = [];
        $byTable = [];
        $templates = [];

        foreach ($tree as $rel) {
            // Rules\WatchRule: additive, whoever else claims the file (a test file included).
            foreach ($this->watchMatches($rel) as $pattern => $targets) {
                $id = 'watch:' . $pattern . '@3';
                $watchTargets[$id] = $targets;
                $sets[$id][] = $rel;
            }

            if ($bladeActive && BladeReferences::isBladePath($rel)) {
                $templates[$rel] = true;
            }

            if ($this->testPaths->isTestFile($rel)) {
                continue;
            }

            // A file coverage cannot see: additive, whatever else claims it or has an edge to it.
            if ($this->residue->isUnattributable($rel)) {
                $sets['unattributable@3'][] = $rel;
            }

            // Laravel\Rules\MigrationRule runs first and consumes every `.php` migration.
            if ($migrationsActive && $migrationPaths->isMigration($rel)) {
                if ($testTables === []) {
                    $sets['migrations:unnarrowed@1'][] = $rel;

                    continue;
                }

                if (! $conservative) {
                    // A squashed migration is no test's input: no test database runs it.
                    if ($squashed === null || ! isset($squashed[TestSchemaDump::migrationName($rel)])) {
                        $sets['migrations:pending@1'][] = $rel;
                    }

                    continue;
                }

                $read = $this->migrationTables[$rel] ??= MigrationRule::tablesForMigration($rel, $this->projectRoot);
                $tables = array_map(strtolower(...), $read['tables']);

                if ($read['unresolved']) {
                    $sets['migrations:unknown@1'][] = $rel;
                } elseif ($tables === []) {
                    $sets['migrations:unnarrowed@1'][] = $rel;
                } elseif (array_intersect_key(array_flip($tables), $recordedTables) === []) {
                    $sets['migrations:untouched@1'][] = $rel;
                } else {
                    $byTable[$rel] = $tables;
                }

                continue;
            }

            // Laravel\Rules\SchemaDumpRule consumes every schema dump.
            if ($schemaActive && SchemaDump::isDumpPath($rel)) {
                $dumpPaths[] = $rel;

                continue;
            }

            if ($this->inUniverse($rel)) {
                continue;
            }

            if ($siblingsActive && SiblingRule::isSiblingCandidate($rel)) {
                if (isset($dependentDirs[dirname($rel)])) {
                    $sets['sibling:' . dirname($rel) . '@3'][] = $rel;

                    continue;
                }

                $ancestor = SiblingRule::nearestAncestorWithEdges($rel, $dependencyTree);

                // Additive (the rule does not consume it): the residue and fallbacks below still see it.
                if ($ancestor !== null) {
                    $sets['sibling-tree:' . $ancestor . '@1'][] = $rel;
                }
            }

            if ($bladeActive && isset($claimedByBlade[$rel])) {
                continue;
            }

            // What no rule claimed: the fallback patterns, and the flag's residue.
            if ($this->staticDeclarationEdges && ResiduePatterns::hasResidueShape($rel, $this->testPaths)) {
                $sets['residue@3'][] = $rel;
            }

            foreach ($this->fallbackMatches($rel) as $pattern => $targets) {
                $id = 'fallback:' . $pattern . '@1';
                $watchTargets[$id] = $targets;
                $sets[$id][] = $rel;
            }
        }

        $ignored = $this->ignoredAmong([
            ...array_merge([], ...array_values($sets)),
            ...array_map(strval(...), array_keys($byTable)),
            ...array_map(strval(...), array_keys($templates)),
            ...$dumpPaths,
        ]);

        /** @var array<string, true> $members */
        $members = [];
        $shared = [];
        $memberOf = [];

        foreach ($sets as $id => $paths) {
            $kept = [];

            foreach ($paths as $path) {
                if (! isset($ignored[$path])) {
                    $kept[] = $path;
                    $members[$path] = true;
                    $memberOf[$path][] = (string) $id;
                }
            }

            if ($kept !== []) {
                $shared[(string) $id] = [...$this->accumulate($kept), 'members' => $kept];
            }
        }

        /** @var array<string, list<string>> $keptByTable */
        $keptByTable = [];

        foreach ($byTable as $path => $tables) {
            $path = (string) $path;

            if (! isset($ignored[$path])) {
                $keptByTable[$path] = $tables;
                $members[$path] = true;
            }
        }

        /** @var array<string, true> $keptTemplates */
        $keptTemplates = [];

        foreach (array_keys($templates) as $template) {
            if (! isset($ignored[(string) $template])) {
                $keptTemplates[(string) $template] = true;
            }
        }


        $dumps = [];

        foreach ($dumpPaths as $dump) {
            if (! isset($ignored[$dump])) {
                $dumps[$dump] = $this->dumpInfo($dump, $testTables === []);
                $members[$dump] = true;
            }
        }

        return $this->state = [
            'shared' => $shared,
            'memberOf' => $memberOf,
            'watchTargets' => $watchTargets,
            'migrationsByTable' => $keptByTable,
            'templates' => $keptTemplates,
            'members' => $members,
            'databaseFiles' => array_fill_keys($this->graph->databaseTestFiles(), true),
            'dumps' => $dumps,
            'bootstrap' => $this->graph->bootstrapTables(),
        ];
    }

    /**
     * What a schema dump contributes, per {@see SchemaDumpRule}: the whole file while the graph
     * records no table (every test's input) or when it does not parse (every database test's);
     * otherwise its normalised statements (`conservative`), or its rows, its statements no
     * table owns and each table's block, hashed apart (`per-table`).
     *
     * @return array{whole: ?string, normalised: string, rows: string, global: ?string, blocks: array<string, string>, dump: ?SchemaDump, perTable: bool, carriedByAll: bool}
     */
    private function dumpInfo(string $dump, bool $noTables): array
    {
        $parsed = $noTables ? null : $this->parsedDump($dump);
        $rule = $this->extraRules['schema'] ?? null;
        $perTable = $rule instanceof SchemaDumpRule && $rule->perTable();

        if ($parsed === null) {
            return ['whole' => $this->element($dump), 'normalised' => '', 'rows' => '', 'global' => null, 'blocks' => [], 'dump' => null, 'perTable' => $perTable, 'carriedByAll' => $noTables];
        }

        $blocks = [];

        foreach ($parsed->tables() as $table) {
            $blocks[$table] = hash('xxh128', $dump . "\0" . $table . "\0" . $parsed->block($table), true);
        }

        return [
            'whole' => null,
            'normalised' => hash('xxh128', $dump . "\0normalised\0" . $parsed->normalisedHash(), true),
            'rows' => hash('xxh128', $dump . "\0rows\0" . $parsed->rowsHash(), true),
            'global' => $parsed->global() === '' ? null : hash('xxh128', $dump . "\0\0" . $parsed->global(), true),
            'blocks' => $blocks,
            'dump' => $parsed,
            'perTable' => $perTable,
            'carriedByAll' => false,
        ];
    }

    /**
     * The parts of a dump `$testFile` carries, mirroring {@see SchemaDumpRule}: per test file,
     * from its own recorded tables and the tables the database is built with only, never from
     * what another test file records.
     *
     * @param array{whole: ?string, normalised: string, rows: string, global: ?string, blocks: array<string, string>, dump: ?SchemaDump, perTable: bool, carriedByAll: bool} $info
     * @param list<string> $bootstrap
     * @return list<string>
     */
    private function schemaParts(string $testFile, array $info, array $bootstrap): array
    {
        if ($info['whole'] !== null) {
            return [$info['whole']];
        }

        if (! $info['perTable'] || $info['dump'] === null) {
            return [$info['normalised']];
        }

        $parts = [$info['rows']];

        if ($info['global'] !== null) {
            $parts[] = $info['global'];
        }

        $queried = $this->graph->queriedTables($testFile);
        $tables = array_keys($info['blocks']);

        if (! $this->graph->tablesUnknown($testFile) && $queried !== []) {
            $reached = SchemaDump::related([...$queried, ...$bootstrap], $info['dump']);

            if ($reached !== [SchemaDump::EVERY_TABLE]) {
                $tables = $reached;
            }
        }

        foreach ($tables as $table) {
            if (isset($info['blocks'][$table])) {
                $parts[] = $info['blocks'][$table];
            }
        }

        return $parts;
    }

    /** Parsed once per instance and content: {@see self::refresh()} keeps it. */
    private function parsedDump(string $dump): ?SchemaDump
    {
        $key = $dump . "\0" . ($this->hashes->of($dump) ?? '');

        if (! array_key_exists($key, $this->dumps)) {
            $content = @file_get_contents(Paths::join($this->projectRoot, $dump));
            $this->dumps[$key] = $content === false ? null : SchemaDump::parse($content);
        }

        return $this->dumps[$key];
    }

    private function migrationPaths(): MigrationPaths
    {
        $rule = $this->extraRules['migration'] ?? null;

        return $rule instanceof MigrationRule ? $rule->paths() : MigrationPaths::default();
    }

    /**
     * `Rules\PhpEdgeRule`'s step, for the scopes that are about unattributed files
     * (`SiblingRule`, the fallback patterns, the flag's residue): a file some test has an edge
     * to is attributed, and leaves them.
     */
    private function inUniverse(string $rel): bool
    {
        return $this->graph->isDependency($rel);
    }

    /**
     * `Laravel\Rules\BladeRule`'s consumption: the templates it finds a test for — those a
     * template some test depends on references, transitively — never reach the fallbacks.
     *
     * @return array<string, true>
     */
    private function claimedByBlade(): array
    {
        $rendered = [];

        foreach (array_keys($this->bladeMap ?? []) as $template) {
            if ($this->graph->isDependency((string) $template)) {
                $rendered[] = (string) $template;
            }
        }

        return BladeReferences::descendantsOf($this->bladeMap ?? [], $rendered);
    }

    /** @return array<string, list<string>> */
    private function fallbackMatches(string $rel): array
    {
        return $this->fallbackMatches[$rel] ??= $this->watch->fallbackMatches($rel);
    }

    /**
     * @param list<string> $paths
     * @return array{acc: string, count: int}
     */
    private function accumulate(array $paths): array
    {
        $acc = str_repeat("\0", 16);

        foreach ($paths as $path) {
            $acc ^= $this->element($path);
        }

        return ['acc' => $acc, 'count' => count($paths)];
    }

    private function element(string $path): string
    {
        return $this->elements[$path] ??= hash('xxh128', $path . "\0" . ($this->hashes->of($path) ?? ''), true);
    }

    private function allStable(string $id): bool
    {
        $state = $this->state();

        foreach ($state['shared'][$id]['members'] ?? [] as $member) {
            if (! $this->hashes->stable($member)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Directories holding at least one dependency of some test file: where
     * `SiblingRule` finds a sibling to stand on, and so consumes a new file.
     *
     * @return array<string, true>
     */
    private function dependentDirectories(): array
    {
        $dirs = [];

        foreach ($this->graph->allTestFiles() as $testFile) {
            foreach ($this->graph->dependenciesOf($testFile) as $dependency) {
                $dirs[dirname($dependency)] = true;
            }
        }

        return $dirs;
    }

    /** @return array<string, list<string>> */
    private function watchMatches(string $rel): array
    {
        return $this->watchMatches[$rel] ??= $this->watch->matches($rel);
    }

    /**
     * `ls-files --exclude-standard` already leaves out untracked files git ignores; a tracked
     * file matching an ignore pattern is still listed, and `Change\ChangedFiles` drops it from
     * every diff (`check-ignore --no-index`), so it is not an input the chain could react to
     * either. Asked once per path per pass. Fails open, like every other caller: a git error
     * keeps the file, which can only cost a re-run.
     *
     * @param list<string> $paths
     * @return array<string, true>
     */
    private function ignoredAmong(array $paths): array
    {
        $unknown = [];

        foreach ($paths as $path) {
            if (! isset($this->asked[$path])) {
                $unknown[$path] = true;
                $this->asked[$path] = true;
            }
        }

        if ($unknown !== []) {
            foreach (array_keys($this->git->ignored(array_map(strval(...), array_keys($unknown))) ?? []) as $path) {
                $this->ignored[(string) $path] = true;
            }
        }

        return $this->ignored;
    }

    /**
     * The present files of the tree, listed once per instance: the tree as the pass found it
     * when it first asked, like every hash in it.
     *
     * @return list<string>|null
     */
    private function tree(): ?array
    {
        if ($this->tree !== false) {
            return $this->tree;
        }

        $listed = $this->git->workingTreeFiles();

        if ($listed === null) {
            return $this->tree = null;
        }

        $present = [];

        foreach ($listed as $rel) {
            if (! $this->excluded($rel) && is_file(Paths::join($this->projectRoot, $rel))) {
                $present[] = $rel;
            }
        }

        return $this->tree = $present;
    }

    /**
     * What this package itself writes into the tree during a pass: the generated PHPUnit
     * configuration, the state directory when it lives inside the project, and a clock probe
     * a crash left behind (`FileHashes` never writes one in the tree; this is the backstop).
     */
    private function excluded(string $rel): bool
    {
        if (basename($rel) === ConfigurationWriter::TEMP_BASENAME || FileHashes::isProbeFile($rel)) {
            return true;
        }

        return $this->stateDirRel !== null && $this->stateDirRel !== '' && str_starts_with($rel, rtrim($this->stateDirRel, '/') . '/');
    }
}
