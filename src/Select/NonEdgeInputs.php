<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\BladeReferences;
use Manuglopez\Replay\Laravel\LaravelDetector;
use Manuglopez\Replay\Laravel\LaravelIntegration;
use Manuglopez\Replay\Laravel\Rules\MigrationRule;
use Manuglopez\Replay\Laravel\Rules\SiblingRule;
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
 * | `watch:<pattern>@2` | `Rules\WatchRule`, configured patterns (additive) | files matching the pattern | T is under one of its targets |
 * | `migrations@2` | `Laravel\Rules\MigrationRule` | migrations whose tables intersect T's; with no table to narrow by, every migration | always |
 * | `blade@2` | `Laravel\Rules\BladeRule` (additive) | templates the templates T depends on reference, transitively | always |
 * | `sibling:<dir>@2` | `Laravel\Rules\SiblingRule` | sibling candidates in `<dir>` no test has an edge to | T depends on a file in `<dir>` |
 * | `unattributable@1` | `ResiduePatterns::isUnattributable()` | `.php` files `<source><exclude>` keeps out of coverage, no configured pattern names, no rule claims | T is under the residue targets |
 * | `residue@2` | `ResiduePatterns`, `static_declaration_edges` only | `.php` files no test has an edge to that no rule claims | T is under the residue targets |
 *
 * **Every member set excludes T's own dependencies and T itself**: those are T's key inputs,
 * and the key already covers them. What a scope holds for T is therefore a function of the
 * tree, of T's own edges and tables, and of nothing another test records. Another test
 * gaining or losing an edge never moves T's digest; T gaining one moves that file from its
 * scope into its key, and re-runs T alone, once. This is only consistent with selection
 * because the rules behind the first three scopes are additive: a watch pattern or a Blade
 * reference applies to a changed file whatever other rule, or other test's edge, already
 * claimed it.
 *
 * Two scopes stay relative to the graph's universe (the files some test has an edge to), by
 * what they mean: `SiblingRule` and the flag's residue are presumptions about files nothing
 * attributes yet. When such a file gains its first edge it leaves the scope, and the test
 * files that carried the scope re-run once; in the common case that is the same pass the
 * chain had already selected them in, for the new file. The step that decides it is
 * {@see self::inUniverse()}, alone.
 *
 * ```
 * digest(T)       = "n2:" . xxh128("nonedge@2\n" . Σ sorted "<scope id>=<scope digest>\n")
 *                   over the scopes T carries that have at least one member
 * scope digest(T) = hex(⊕ xxh128(path . "\0" . ContentHash(path))) . ":" . |members|
 * ```
 *
 * A scope digest is an XOR over its members, so a scope shared by hundreds of test files is
 * hashed once and each test file's own dependencies are taken out of it in constant time per
 * dependency. `n2` is the version token: anything that changes what a digest means changes
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
    public const VERSION = 'n2';

    private const MATERIAL = "nonedge@2\n";

    /** @var list<string>|null|false present files, false until listed, null when git failed */
    private array|false|null $tree = false;

    /** @var array<string, array<string, list<string>>> path => configured pattern => targets */
    private array $watchMatches = [];

    /** @var array<string, list<string>> migration path => lowercased tables */
    private array $migrationTables = [];

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
     * @param array{migration?: Rule, sibling?: Rule, blade?: Rule} $extraRules the Laravel rules the
     *        pass runs (their presence is all that is read), as for {@see RunListBuilder}
     * @param WatchPatterns $watch the configured patterns only — never an instance
     *        {@see RunListBuilder::build()} has added the residue of a change set to
     * @param SourceScope|null $scope the coverage scope, for the `unattributable@1` scope
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
        $this->residue = new ResiduePatterns($graph, $testPaths, false, $scope, $watch);
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
        $watch = new WatchPatterns();
        $watch->useDefaults($projectRoot, $testPaths->directories(), LaravelDetector::enabled($projectRoot, $config));

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

        if (isset($this->extraRules['migration']) && MigrationRule::isMigrationPath($relative)) {
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

        $own = array_fill_keys($this->graph->dependenciesOf($testFile), true);
        $own[$testFile] = true;
        $underResidueTargets = $this->watch->testsUnderDirectories($this->residueTargets, [$testFile]) !== [];
        $scopes = [];

        foreach ($state['shared'] as $id => $scope) {
            if ($this->carries($testFile, $id, $state, $own, $underResidueTargets)) {
                $scopes[$id] = ['acc' => $scope['acc'], 'count' => $scope['count']];
            }
        }

        // Take the test file's own key inputs out of every shared scope that holds them.
        foreach (array_keys($own) as $path) {
            foreach ($state['memberOf'][(string) $path] ?? [] as $id) {
                if (isset($scopes[$id])) {
                    $scopes[$id]['acc'] ^= $this->elements[(string) $path];
                    $scopes[$id]['count']--;
                }
            }
        }

        // MigrationRule, narrowed by the tables this test file was recorded touching.
        $tables = array_fill_keys($this->graph->testTables()[$testFile] ?? [], true);
        $byTable = [];

        foreach ($tables === [] ? [] : $state['migrationsByTable'] as $path => $migrationTables) {
            foreach ($migrationTables as $table) {
                if (isset($tables[$table])) {
                    if (! isset($own[$path])) {
                        $byTable[] = (string) $path;
                    }

                    break;
                }
            }
        }

        if ($byTable !== []) {
            $scopes['migrations@2'] = self::merge($scopes['migrations@2'] ?? null, $this->accumulate($byTable), $byTable);
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
                $scopes['blade@2'] = ['acc' => $this->accumulate($blade)['acc'], 'count' => count($blade), 'members' => $blade];
            }
        }

        return array_filter($scopes, static fn (array $scope): bool => $scope['count'] > 0);
    }

    /**
     * @param array{watchTargets: array<string, list<string>>} $state
     * @param array<string, true> $own
     */
    private function carries(string $testFile, string $id, array $state, array $own, bool $underResidueTargets): bool
    {
        if (isset($state['watchTargets'][$id])) {
            return $this->watch->testsUnderDirectories($state['watchTargets'][$id], [$testFile]) !== [];
        }

        if (str_starts_with($id, 'sibling:')) {
            $dir = substr($id, strlen('sibling:'), -strlen('@2'));

            foreach (array_keys($own) as $path) {
                if (dirname((string) $path) === $dir && $path !== $testFile) {
                    return true;
                }
            }

            return false;
        }

        // residue@2, unattributable@1, and migrations@2's every-test half: the residue targets.
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
        $narrowByTable = $this->graph->testTables() !== [];
        $siblingsActive = isset($this->extraRules['sibling']);
        $dependentDirs = $siblingsActive ? $this->dependentDirectories() : [];

        /** @var array<string, list<string>> $sets scope id => members */
        $sets = [];
        $watchTargets = [];
        $byTable = [];
        $templates = [];

        foreach ($tree as $rel) {
            // Rules\WatchRule: additive, whoever else claims the file (a test file included).
            foreach ($this->watchMatches($rel) as $pattern => $targets) {
                $id = 'watch:' . $pattern . '@2';
                $watchTargets[$id] = $targets;
                $sets[$id][] = $rel;
            }

            if (isset($this->extraRules['blade']) && BladeReferences::isBladePath($rel)) {
                $templates[$rel] = true;
            }

            if ($this->testPaths->isTestFile($rel)) {
                continue;
            }

            // Laravel\Rules\MigrationRule runs first and consumes every migration.
            if ($migrationsActive && MigrationRule::isMigrationPath($rel)) {
                $tables = $this->migrationTables[$rel] ??= array_map(
                    strtolower(...),
                    MigrationRule::tablesForMigration($rel, $this->projectRoot),
                );

                if ($narrowByTable && $tables !== []) {
                    $byTable[$rel] = $tables;
                } else {
                    $sets['migrations@2'][] = $rel;
                }

                continue;
            }

            if ($this->inUniverse($rel)) {
                continue;
            }

            if ($siblingsActive && SiblingRule::isSiblingCandidate($rel) && isset($dependentDirs[dirname($rel)])) {
                $sets['sibling:' . dirname($rel) . '@2'][] = $rel;

                continue;
            }

            if ($this->residue->isUnattributable($rel)) {
                $sets['unattributable@1'][] = $rel;
            } elseif ($this->staticDeclarationEdges && ResiduePatterns::hasResidueShape($rel, $this->testPaths)) {
                $sets['residue@2'][] = $rel;
            }
        }

        $ignored = $this->ignoredAmong([
            ...array_merge([], ...array_values($sets)),
            ...array_map(strval(...), array_keys($byTable)),
            ...array_map(strval(...), array_keys($templates)),
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

        if ($keptTemplates !== [] && $this->bladeMap === null) {
            $cache = $this->stateDir !== null ? rtrim($this->stateDir, '/') . '/blade-references.json' : null;
            $this->bladeMap = BladeReferences::referenceMap($this->projectRoot, $cache);
        }

        return $this->state = [
            'shared' => $shared,
            'memberOf' => $memberOf,
            'watchTargets' => $watchTargets,
            'migrationsByTable' => $keptByTable,
            'templates' => $keptTemplates,
            'members' => $members,
        ];
    }

    /**
     * `Rules\PhpEdgeRule`'s step, for the two scopes that are presumptions about unattributed
     * files (`SiblingRule`, the flag's residue): a file some test has an edge to is attributed,
     * and leaves them. The one place a scope looks at the graph's universe.
     */
    private function inUniverse(string $rel): bool
    {
        return $this->graph->isDependency($rel);
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

    /**
     * @param array{acc: string, count: int}|null $shared
     * @param array{acc: string, count: int} $own
     * @param list<string> $members
     * @return array{acc: string, count: int, members: list<string>}
     */
    private static function merge(?array $shared, array $own, array $members): array
    {
        return [
            'acc' => $shared === null ? $own['acc'] : $shared['acc'] ^ $own['acc'],
            'count' => ($shared['count'] ?? 0) + $own['count'],
            'members' => $members,
        ];
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
