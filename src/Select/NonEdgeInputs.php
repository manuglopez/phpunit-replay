<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\BladeReferences;
use Manuglopez\Replay\Laravel\LaravelIntegration;
use Manuglopez\Replay\Laravel\Rules\MigrationRule;
use Manuglopez\Replay\Laravel\Rules\SiblingRule;
use Manuglopez\Replay\PHPUnit\ConfigurationWriter;
use Manuglopez\Replay\Support\Paths;

/**
 * The non-edge input digest of a test file: the one place it is defined.
 *
 * A content key (`Cache\ContentKey`) covers the test file and every file it executed. The
 * rule chain also selects a test file for files it did NOT execute: a watched data file it
 * reads, a migration of a table it uses, a new sibling of a class it depends on, a Blade
 * partial, and (with `static_declaration_edges`) any `.php` file no test has an edge to.
 * Those are the test file's non-edge inputs, and this class hashes them. A result stamped
 * with the key and this digest, both recomputed equal on the current tree, ran on the same
 * inputs as far as the rule chain can tell (`Select\StampAudit`).
 *
 * The inputs are grouped into **scopes**, named and versioned file-set predicates, as in
 * `docs/proposals/self-describing-objects.md` ("Non-edge inputs: they become part of the
 * object"). Membership is computed over this working tree — `git ls-files -co
 * --exclude-standard`, minus what git ignores, minus files that do not exist — and relative
 * to the graph's universe, the files some test has an edge to ({@see Graph::isDependency()}):
 *
 * | scope | the rule it stands for | members | carried by |
 * |---|---|---|---|
 * | `migrations@1` | `Laravel\Rules\MigrationRule`, only while the graph has tables | migration files whose extracted tables are non-empty and intersect the test file's tables | that test file |
 * | `sibling:<dir>@1` | `Laravel\Rules\SiblingRule` | sibling-candidate `.php` files in `<dir>` outside the universe | test files with a dependency in `<dir>` |
 * | `blade@1` | `Laravel\Rules\BladeRule` | Blade templates outside the universe with an ancestor in it (`BladeReferences::ancestorsOfMany()`) | the test files depending on one of those ancestors |
 * | `watch:<pattern>@1` | `Rules\WatchRule` | files matching the pattern | test files under one of its targets |
 * | `residue@1` | `ResiduePatterns`, `static_declaration_edges` only | `.php` files that are not Blade nor test files | test files under the residue targets |
 *
 * Membership follows the order the rule chain consumes a changed file in, so that a file
 * counts exactly where the chain would have claimed it: Migration first (it runs before
 * PhpEdge, so a migration with tables is its input even when some test has an edge to it),
 * then the universe and the test files (PhpEdge and TestFile: covered by the key), then
 * Sibling where its directory has a dependent, Blade where the template has an ancestor some
 * test depends on, and whatever is left for Watch and the residue. That is the chain applied
 * to every file of the tree at once, so a digest moves only for a change the chain would
 * select the test file for. The one way it moves where the chain would not: a file leaves a
 * scope by entering the universe (a test gained an edge to it), which re-runs the test files
 * that carried it once.
 *
 * The universe step is {@see self::consumedByTheUniverse()}, on its own: if `WatchRule` ever
 * applies to files with edges too, that predicate changes, and so must {@see self::VERSION}.
 *
 * ```
 * digest(T)   = "n1:" . xxh128("nonedge@1\n" . Σ sorted "<scope id>=<scope digest>\n")
 *               over the scopes T carries that have at least one member
 * scope digest = xxh128(Σ sorted "<path>\0<ContentHash(path)>\n") over its members
 * ```
 *
 * `n1` is the version token: anything that changes what a digest means changes it, and a
 * stamp with another version never matches. A null digest means it could not be computed
 * (git failed): nothing is stamped with it and nothing stamped validates against it.
 *
 * One instance per graph per pass. Every file is hashed through the pass's {@see FileHashes},
 * and the tree listing, the watch matches and the migration tables are read once. Call
 * {@see self::refresh()} after the graph's edges or tables change (`Cache\GraphUpdater`).
 */
final class NonEdgeInputs
{
    public const VERSION = 'n1';

    private const MATERIAL = "nonedge@1\n";

    /** @var list<string>|null|false false until listed, null when git failed */
    private array|false|null $tree = false;

    /** @var array<string, array<string, list<string>>> path => watch pattern => targets */
    private array $watchMatches = [];

    /** @var array<string, list<string>> migration path => lowercased tables */
    private array $migrationTables = [];

    /** @var array<string, true> tracked files git ignores, among the ones asked about */
    private array $ignored = [];

    /** @var array<string, true> every path {@see self::ignoredAmong()} has asked git about */
    private array $asked = [];

    /**
     * @var array{
     *     watch: array<string, array{digest: string, targets: list<string>}>,
     *     residue: ?string,
     *     blade: array<string, list<string>>,
     *     siblings: array<string, string>,
     *     migrations: array<string, list<string>>,
     *     members: array<string, true>,
     * }|null|false false until computed, null when the tree could not be listed
     */
    private array|false|null $state = false;

    /** @var array<string, list<string>> template => its ancestors, read once per pass */
    private array $bladeAncestors = [];

    /** @var array<string, ?string> */
    private array $digests = [];

    /** @var list<string> */
    private readonly array $residueTargets;

    private readonly ?string $stateDirRel;

    /**
     * @param array{migration?: Rule, sibling?: Rule, blade?: Rule} $extraRules the Laravel rules the
     *        pass runs (their presence is all that is read), as for {@see RunListBuilder}
     * @param WatchPatterns $watch the configured patterns only — never an instance
     *        {@see RunListBuilder::build()} has added the residue literals of a change set to
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
        ?string $stateDir = null,
    ) {
        $this->residueTargets = ResiduePatterns::targetsFor($testPaths);
        $this->stateDirRel = $stateDir !== null ? Paths::relative($projectRoot, $stateDir) : null;
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
    ): self {
        $watch = new WatchPatterns();
        $watch->useDefaults($projectRoot, $testPaths->directories());

        if ($config->watch !== []) {
            $watch->add($config->watch);
        }

        return new self(
            $graph,
            $testPaths,
            $watch,
            $projectRoot,
            $hashes,
            $git,
            LaravelIntegration::rulesFor($graph, $projectRoot, $config),
            $config->staticDeclarationEdges,
            $stateDir,
        );
    }

    /** The graph's edges or tables changed: scopes are recomputed, files are not re-read. */
    public function refresh(): void
    {
        $this->state = false;
        $this->digests = [];
    }

    /** Null when the working tree could not be listed. */
    public function digestFor(string $testFile): ?string
    {
        if (array_key_exists($testFile, $this->digests)) {
            return $this->digests[$testFile];
        }

        $state = $this->state();

        if ($state === null) {
            return $this->digests[$testFile] = null;
        }

        $scopes = [];

        foreach ($state['watch'] as $pattern => $scope) {
            if ($this->watch->testsUnderDirectories($scope['targets'], [$testFile]) !== []) {
                $scopes['watch:' . $pattern . '@1'] = $scope['digest'];
            }
        }

        if ($state['residue'] !== null && $this->watch->testsUnderDirectories($this->residueTargets, [$testFile]) !== []) {
            $scopes['residue@1'] = $state['residue'];
        }

        if (($state['blade'][$testFile] ?? []) !== []) {
            $scopes['blade@1'] = $this->membersDigest($state['blade'][$testFile]);
        }

        foreach ($this->graph->dependenciesOf($testFile) as $dependency) {
            $dir = dirname($dependency);

            if (isset($state['siblings'][$dir])) {
                $scopes['sibling:' . $dir . '@1'] = $state['siblings'][$dir];
            }
        }

        $migrations = $this->migrationsOf($testFile, $state['migrations']);

        if ($migrations !== []) {
            $scopes['migrations@1'] = $this->membersDigest($migrations);
        }

        ksort($scopes, SORT_STRING);

        $material = self::MATERIAL;

        foreach ($scopes as $id => $digest) {
            $material .= $id . '=' . $digest . "\n";
        }

        return $this->digests[$testFile] = self::VERSION . ':' . hash('xxh128', $material);
    }

    /**
     * Whether a change to `$relative` could change what some result was recorded against: a
     * test file, a file of the graph's universe, or a member of any scope. What a clean tree
     * means when a graph is about to be published (`Cache\GraphPublication`).
     */
    public function covers(string $relative): bool
    {
        if ($this->testPaths->isTestFile($relative) || $this->graph->isDependency($relative)) {
            return true;
        }

        $state = $this->state();

        return $state === null || isset($state['members'][$relative]);
    }

    /**
     * @return array{
     *     watch: array<string, array{digest: string, targets: list<string>}>,
     *     residue: ?string,
     *     blade: array<string, list<string>>,
     *     siblings: array<string, string>,
     *     migrations: array<string, list<string>>,
     *     members: array<string, true>,
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

        $migrationsActive = isset($this->extraRules['migration']) && $this->graph->testTables() !== [];
        $siblingsActive = isset($this->extraRules['sibling']);
        $bladeActive = isset($this->extraRules['blade']);
        $dependentDirs = $siblingsActive ? $this->dependentDirectories() : [];

        $migrations = [];
        $siblings = [];
        $blade = [];
        $residue = [];
        $watch = [];

        $present = [];

        foreach ($tree as $rel) {
            // Rules\TestFileRule claims an existing test file for itself; its hash is in `k`.
            if (! $this->excluded($rel) && ! $this->testPaths->isTestFile($rel) && is_file(Paths::join($this->projectRoot, $rel))) {
                $present[] = $rel;
            }
        }

        $bladeClaims = $bladeActive ? $this->bladeClaims($present) : [];

        foreach ($present as $rel) {

            // Laravel\Rules\MigrationRule runs before PhpEdge and consumes a migration it can
            // read tables from, whether or not any test uses them.
            if ($migrationsActive && MigrationRule::isMigrationPath($rel)) {
                $tables = $this->migrationTables[$rel] ??= array_map(
                    strtolower(...),
                    MigrationRule::tablesForMigration($rel, $this->projectRoot),
                );

                if ($tables !== []) {
                    $migrations[$rel] = $tables;

                    continue;
                }
            }

            if ($this->consumedByTheUniverse($rel)) {
                continue;
            }

            if ($siblingsActive && SiblingRule::isSiblingCandidate($rel) && isset($dependentDirs[dirname($rel)])) {
                $siblings[dirname($rel)][] = $rel;

                continue;
            }

            // Laravel\Rules\BladeRule consumes a template only when an ancestor of it has
            // dependents; one with none falls through to WatchRule.
            if (isset($bladeClaims[$rel])) {
                $blade[$rel] = $bladeClaims[$rel];

                continue;
            }

            if ($this->staticDeclarationEdges && ResiduePatterns::hasResidueShape($rel, $this->testPaths)) {
                $residue[] = $rel;
            }

            foreach ($this->watchMatches($rel) as $pattern => $targets) {
                $watch[$pattern]['targets'] = $targets;
                $watch[$pattern]['members'][] = $rel;
            }
        }

        $ignored = $this->ignoredAmong([
            ...array_keys($migrations),
            ...array_merge([], ...array_values($siblings)),
            ...array_map(strval(...), array_keys($blade)),
            ...$residue,
            ...array_merge([], ...array_map(static fn (array $scope): array => $scope['members'], array_values($watch))),
        ]);

        /** @var array<string, true> $members */
        $members = [];

        $watchScopes = [];

        foreach ($watch as $pattern => $scope) {
            $kept = self::without($scope['members'], $ignored, $members);

            if ($kept !== []) {
                $watchScopes[(string) $pattern] = ['digest' => $this->membersDigest($kept), 'targets' => $scope['targets']];
            }
        }

        $siblingScopes = [];

        foreach ($siblings as $dir => $paths) {
            $kept = self::without($paths, $ignored, $members);

            if ($kept !== []) {
                $siblingScopes[(string) $dir] = $this->membersDigest($kept);
            }
        }

        /** @var array<string, list<string>> $keptMigrations */
        $keptMigrations = [];

        foreach ($migrations as $path => $tables) {
            $path = (string) $path;

            if (! isset($ignored[$path])) {
                $keptMigrations[$path] = $tables;
                $members[$path] = true;
            }
        }

        /** @var array<string, list<string>> $bladeByTest */
        $bladeByTest = [];

        foreach ($blade as $path => $testFiles) {
            $path = (string) $path;

            if (isset($ignored[$path])) {
                continue;
            }

            $members[$path] = true;

            foreach ($testFiles as $testFile) {
                $bladeByTest[$testFile][] = $path;
            }
        }

        $keptResidue = self::without($residue, $ignored, $members);

        return $this->state = [
            'watch' => $watchScopes,
            'residue' => $keptResidue === [] ? null : $this->membersDigest($keptResidue),
            'blade' => $bladeByTest,
            'siblings' => $siblingScopes,
            'migrations' => $keptMigrations,
            'members' => $members,
        ];
    }

    /**
     * The migrations `MigrationRule` would select this test file for: every one whose tables
     * intersect the ones the test file was recorded touching.
     *
     * @param array<string, list<string>> $migrations
     * @return list<string>
     */
    private function migrationsOf(string $testFile, array $migrations): array
    {
        $tables = $this->graph->testTables()[$testFile] ?? [];

        if ($tables === [] || $migrations === []) {
            return [];
        }

        $uses = array_fill_keys($tables, true);
        $claimed = [];

        foreach ($migrations as $path => $migrationTables) {
            foreach ($migrationTables as $table) {
                if (isset($uses[$table])) {
                    $claimed[] = $path;

                    break;
                }
            }
        }

        return $claimed;
    }

    /**
     * `$paths` minus what git ignores, each kept one recorded in `$members`.
     *
     * @param list<string> $paths
     * @param array<string, true> $ignored
     * @param array<string, true> $members
     * @return list<string>
     */
    private static function without(array $paths, array $ignored, array &$members): array
    {
        $kept = [];

        foreach ($paths as $path) {
            if (! isset($ignored[$path])) {
                $kept[] = $path;
                $members[$path] = true;
            }
        }

        return $kept;
    }

    /** @param list<string> $paths */
    private function membersDigest(array $paths): string
    {
        sort($paths, SORT_STRING);

        $material = '';

        foreach ($paths as $path) {
            $material .= $path . "\0" . ($this->hashes->of($path) ?? '') . "\n";
        }

        return hash('xxh128', $material);
    }

    /**
     * `Rules\PhpEdgeRule`'s step: a file of the universe is in the key of every test that
     * depends on it, selects no other, and is consumed before any rule after it sees it. The
     * one place that decision lives, because it is the one a change to what `WatchRule` sees
     * would move (and with it {@see self::VERSION}).
     */
    private function consumedByTheUniverse(string $rel): bool
    {
        return $this->graph->isDependency($rel);
    }

    /**
     * What `BladeRule` would select each template outside the universe for: the test files
     * depending on one of its ancestors that is in it. A template with no such ancestor is
     * absent, and falls through to the watch patterns as it does in the chain.
     *
     * @param list<string> $present
     * @return array<string, list<string>> template => test files
     */
    private function bladeClaims(array $present): array
    {
        $unknown = [];

        foreach ($present as $rel) {
            if (BladeReferences::isBladePath($rel) && ! $this->consumedByTheUniverse($rel)) {
                $unknown[] = $rel;
            }
        }

        $missing = array_values(array_filter($unknown, fn (string $rel): bool => ! isset($this->bladeAncestors[$rel])));

        if ($missing !== []) {
            $this->bladeAncestors = [...$this->bladeAncestors, ...BladeReferences::ancestorsOfMany($missing, $this->projectRoot)];
        }

        $claims = [];

        foreach ($unknown as $rel) {
            $testFiles = [];

            foreach ($this->bladeAncestors[$rel] ?? [] as $ancestor) {
                if ($this->graph->isDependency($ancestor)) {
                    foreach ($this->graph->testFilesDependingOn($ancestor) as $testFile) {
                        $testFiles[$testFile] = true;
                    }
                }
            }

            if ($testFiles !== []) {
                $claims[$rel] = array_map(strval(...), array_keys($testFiles));
            }
        }

        return $claims;
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
            foreach (array_keys($this->git->ignored(array_keys($unknown)) ?? []) as $path) {
                $this->ignored[$path] = true;
            }
        }

        return $this->ignored;
    }

    /** @return list<string>|null */
    private function tree(): ?array
    {
        if ($this->tree === false) {
            $this->tree = $this->git->workingTreeFiles();
        }

        return $this->tree;
    }

    /**
     * What this package itself writes into the tree during a pass: the generated PHPUnit
     * configuration, and the state directory when it lives inside the project.
     */
    private function excluded(string $rel): bool
    {
        if (basename($rel) === ConfigurationWriter::TEMP_BASENAME) {
            return true;
        }

        return $this->stateDirRel !== null && $this->stateDirRel !== '' && str_starts_with($rel, rtrim($this->stateDirRel, '/') . '/');
    }
}
