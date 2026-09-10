# Internals — component contracts

Working contract between components. SPEC.md is the source of truth for behaviour; this file
pins down class names, signatures and data shapes so components built in parallel fit together.
When SPEC.md and this file disagree on a signature, this file wins (it reflects the real PHPUnit
API); when they disagree on behaviour, SPEC.md wins and the deviation is documented inline or in the phase reports.

Conventions: `declare(strict_types=1)` everywhere, `final` by default, `readonly` where the
object is immutable, PSR-12 via `vendor/bin/pint`, PHPStan level max. Target PHP 8.2: no typed class constants, no `#[\Override]`, readonly classes are fine. This package's own PHP floor is unchanged by PHPUnit 12/13 support; only a *project* that runs its tests under PHPUnit 12 needs PHP >=8.3, or under PHPUnit 13 needs PHP >=8.4.1, per each PHPUnit release's own requirement. All paths handed between
components are **project-relative with forward slashes** unless a parameter is named `$absolute`.
Never throw out of a public method for an environmental problem (missing git, unreadable file,
malformed JSON): return `null`/`[]`/`false` and let the caller warn.

Ported-from-Pest files carry:

```php
/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/<File>.php
 */
```

## Namespaces

| Namespace | Directory |
|---|---|
| `Manuglopez\Replay` | `src/` |
| `Manuglopez\Replay\Tests` | `tests/` (`tests/Unit`, `tests/Integration`, `tests/Support`) |

`Manuglopez\Replay\Analysis` (`src/Analysis/`) is the static classifier behind
`static_declaration_edges` — see "Static declaration edges" below.

## Support

```php
final class Support\Paths
{
    /** Absolute → project-relative with '/' separators. null when outside root, under vendor/, empty, 'unknown' or eval()'d. Relative input is normalised (strip './', '\\'→'/'). */
    public static function relative(string $projectRoot, string $path): ?string;
    public static function isAbsolute(string $path): bool;
    public static function normalizeSeparators(string $path): string;
    public static function join(string $root, string $relative): string;
}
final class Support\AtomicFile
{
    /** mkdir -p dirname, write to "<path>.<8hex>.tmp", rename. false on any failure (tmp removed). */
    public static function write(string $path, string $content): bool;
    public static function read(string $path): ?string;   // null when missing/unreadable
}
final class Support\Json
{
    public static function encode(mixed $value): ?string;          // JSON_UNESCAPED_SLASHES, null on failure
    public static function encodePretty(mixed $value): ?string;
    /** @return array<mixed>|null null when invalid or not an object/array */
    public static function decodeArray(string $json): ?array;
}
```

## Cache

```php
final class Cache\ContentHash               // port of Pest ContentHash
{
    public static function of(string $absolute): ?string;                       // null when unreadable
    public static function ofContent(string $content, string $pathForType): string;  // NOTE arg order per SPEC §4.4
}

final readonly class Cache\Fingerprint       // port, structural list per SPEC §4.5
{
    public const SCHEMA_VERSION = 1;
    /** @return array{structural: array<string, int|string|null>, environmental: array<string, string|null>} */
    public static function compute(string $projectRoot, string $driver): array;
    //   structural: schema, composer_lock, phpunit_xml, phpunit_xml_dist, replay_config (phpunit-replay.php); only git-tracked files hash, else null
    //   environmental: php (MAJOR.MINOR), driver ('pcov'|'xdebug'|'none'), os (PHP_OS_FAMILY),
    //                  coverage (Coverage\CoverageFormat::id(), e.g. 'cc14/fmt3/snap2' — the
    //                  installed php-code-coverage major, its --coverage-php marker, and this
    //                  package's own snapshot format; a change clears cached RESULTS only,
    //                  the graph's edges stand, since a stale coverage format cannot corrupt
    //                  those — only a replayed result's merged coverage)
    public static function structuralMatches(array $a, array $b): bool;
    /** @return list<string> keys that differ */
    public static function structuralDrift(array $stored, array $current): array;
    public static function environmentalDrift(array $stored, array $current): array;
    /** Canonical JSON of the structural bucket (ksort, JSON_UNESCAPED_SLASHES) — input to the content key. */
    public static function canonicalStructural(array $fingerprint): string;
    /** Canonical JSON of the environmental keys that can change a test's OUTCOME — the content key's second input. */
    public static function canonicalResultEnvironment(array $fingerprint): string;
    //   php + os only. driver is out (it decides which lines are REPORTED, not whether an
    //   assertion passed, and alternating pcov/xdebug must not halve a hit rate); coverage is
    //   out (it guards the local snapshot store, and a result adopted from a remote carries no
    //   snapshot). Both stay in the bucket, which is what clears the results of a graph
    //   adopted from another environment — those addresses can no longer be computed here.
}

final class Cache\ProjectKey                 // port of Pest Storage::projectKey
{
    public static function for(string $projectRoot): string;         // slug(basename) . '-' . substr(sha256(originIdentity ?? realpath), 0, 16)
    public static function originIdentity(string $projectRoot): ?string;  // "github.com/org/repo" lowercased, no scheme/user/.git
    public static function normalizeOriginUrl(string $url): string;
}

final class Cache\StateDirectory
{
    /** $configured: absolute, or relative to root, or null → $HOME/.phpunit-replay/<ProjectKey>; no HOME → <root>/.phpunit-replay */
    public static function resolve(?string $configured, string $projectRoot): string;
}

/** In-memory graph + JSON codec. SPEC §4.2. Results stored per branch with SHORT keys on disk (s,a,t,m,f,k) and LONG keys in memory. */
final class Cache\Graph
{
    public const SCHEMA = 1;
    public function __construct(string $projectRoot);
    public function projectRoot(): string;
    public function relative(string $path): ?string;                   // delegates to Support\Paths::relative

    // files / edges (test file → source files)
    public function link(string $testFile, string $sourceFile): void;  // abs or rel; ignores paths outside root
    /** @param array<string, list<string>> $testToFiles full replacement per test file */
    public function replaceEdges(array $testToFiles): void;
    /** @param array<string, list<string>> $testToFiles merged into what the graph already had per test file, never replaced — GraphUpdater::apply()'s only caller, see its own docblock for why */
    public function unionEdges(array $testToFiles): void;
    /** @param list<string> $testFiles ensure edges[rel] exists (possibly []) — "known without dependencies" */
    public function markKnownTestFiles(array $testFiles): void;
    public function knowsTest(string $testFile): bool;
    /** @return list<string> */ public function allTestFiles(): array;
    /** @return list<string> */ public function files(): array;
    public function fileId(string $relative): ?int;
    /** @return list<string> relative source files */ public function dependenciesOf(string $testFileRel): array;
    /** @return list<string> test files whose edges contain $relative (reverse index, lazily built, invalidated on write) */
    public function testFilesDependingOn(string $relative): array;

    // tables (Laravel, phase 2) and hermeticity
    /** @param array<string, list<string>> */ public function replaceTestTables(array $testToTables): void;
    /** @return array<string, list<string>> */ public function testTables(): array;
    /** @param list<string> */ public function setNotCacheable(array $testFiles): void;
    /** @return list<string> */ public function notCacheable(): array;

    // fingerprint
    /** @param array<string, mixed> */ public function setFingerprint(array $fingerprint): void;
    /** @return array<string, mixed> */ public function fingerprint(): array;

    // baselines (SPEC §4.2 rules: array_replace(default, branch) unless branch complete → default only for files absent in branch)
    public function setDefaultBranch(string $branch): void;
    public function defaultBranch(): string;
    /** @return list<string> */ public function branches(): array;
    public function recordedSha(string $branch): ?string;             // own sha, else default-branch sha
    public function setRecordedSha(string $branch, ?string $sha): void;
    public function isBaselineComplete(string $branch): bool;
    public function markBaselineComplete(string $branch): void;
    /** @param TestResultArray $result */
    public function setResult(string $branch, string $testId, array $result): void;
    /** @return TestResultArray|null merged view */
    public function result(string $branch, string $testId): ?array;
    /** @return array<string, TestResultArray> merged view */
    public function results(string $branch): array;
    /** @return array<string, TestResultArray> only what this branch recorded itself */
    public function ownResults(string $branch): array;
    public function clearResults(?string $branch = null): void;      // environmental drift: results go, edges stay

    // pruning (SPEC §7.3)
    public function pruneMissingTestFiles(): void;                     // edges/test_tables/not_cacheable whose test file is gone from disk
    public function pruneMissingDependencies(): int;                   // one dependency edge at a time, only when its file is gone from disk; returns edges removed
    public function pruneResultsForMissingFiles(string $branch): void;
    /** @param list<string> $keep */ public function pruneMissingBranches(array $keep): void;
    /** @param list<string> $touchedTestFiles @param list<string> $keepTestIds */
    public function pruneStaleResults(string $branch, array $touchedTestFiles, array $keepTestIds): void;

    // codec
    public static function decode(string $json, string $projectRoot): ?self;  // null: invalid JSON or schema !== 1; malformed sections are skipped entry-by-entry
    public function encode(): ?string;
    /** @return array{files:int, test_files:int, edges:int, branches:int, results:int} */
    public function stats(): array;
}
```

`TestResultArray` (in memory, everywhere):

```php
/** @phpstan-type TestResultArray array{status:int, message:string, time:float, assertions:int, file?:string, key?:string} */
```

On disk inside `graph.json` (`baselines.<branch>.results.<testId>`): `{"s":int,"a":int,"t":float,"m":string,"f":string,"k":string}`.
`status` is `PHPUnit\Framework\TestStatus\TestStatus::asInt()` (0 success … 8 error, SPEC §4.2).

```php
final class Cache\GraphStore
{
    public function __construct(string $stateDir, string $projectRoot);
    public function path(): string;                                    // <stateDir>/graph.json
    public function load(): ?Graph;                                    // null when missing or undecodable
    public function loadRaw(): ?string;
    public function save(Graph $graph): bool;                          // AtomicFile::write
    public function delete(): bool;
}

final readonly class Cache\ContentKey        // SPEC §4.3
{
    public function __construct(string $projectRoot);
    /** @param array<string, mixed> $fingerprint  @param list<string> $dependencies relative paths */
    public function compute(array $fingerprint, string $testFileRel, array $dependencies): ?string;  // null when test file unreadable
    public function forTestFile(Graph $graph, string $testFileRel): ?string;      // uses graph->dependenciesOf + graph->fingerprint
}
```

## Change

```php
final readonly class Change\Git             // port of Pest Support\Git, no exceptions
{
    public function __construct(?string $directory = null, float $timeout = 5.0);
    public function withTimeout(float $timeout): self;
    /** @param list<string> $arguments */
    public function raw(array $arguments): ?string;                    // full stdout on exit 0, else null
    public function output(array $arguments): ?string;                 // trimmed, '' → null
    public function succeeds(array $arguments): bool;
    /** @return array{exitCode:int, output:string} */
    public function result(array $arguments, ?string $input = null): array;   // exitCode 127 when git binary missing
    public static function available(): bool;                          // `git --version` succeeds (cached)
    public function isRepository(): bool;
    public function hasCommits(): bool;
    public function topLevel(): ?string;                               // rev-parse --show-toplevel (realpath)
    public function currentBranch(): ?string;                          // null on detached HEAD
    public function currentSha(): ?string;
    public function defaultBranch(): ?string;                          // origin/HEAD → init.defaultBranch (if ref exists) → 'main'/'master' if exists → null
    public function isAncestor(string $sha, string $head = 'HEAD'): bool;
    /** @return list<string> */ public function branchNames(): array;  // local + remote (without remote prefix, no HEAD)
    public function show(string $sha, string $path): ?string;
    public function hasRemote(): bool;
}

final readonly class Change\ChangedFiles    // port of Pest ChangedFiles, SPEC §7.1 steps 1–5
{
    public function __construct(string $projectRoot, ?Git $git = null);
    /** @return list<string>|null null when sha unreachable (baseline unusable) or git failed */
    public function since(?string $sha): ?array;
    /** @param list<string> $files @return array<string, string> rel → ContentHash ('' when missing) */
    public function snapshotTree(array $files): array;
    public function currentHash(string $relativePath): ?string;
}

final readonly class Change\LastRunTree     // SPEC §7.1 step 6, persisted as <stateDir>/last-run.json
{
    /** @param array<string, string> $tree */
    public function __construct(public string $branch, public ?string $sha, public array $tree, public int $finishedAt);
    public static function fromJson(string $json): ?self;
    public function toJson(): string;
    public static function load(string $stateDir): ?self;
    public function save(string $stateDir): bool;
    /** candidates ∪ keys(tree), keep those whose current hash differs from snapshot (or gone). @param list<string> @return list<string> */
    public function filterUnchanged(array $candidates, ChangedFiles $changedFiles): array;
}
```

## Record

```php
interface Record\CoverageDriver
{
    public static function available(): bool;                          // can record right now, in this process
    public function name(): string;                                    // 'pcov' | 'xdebug'
    public function start(): void;
    /** @return array<string, array<int, int>> absolute file → [line => hits], already filtered by SourceScope */
    public function stop(): array;
}
final class Record\PcovDriver implements CoverageDriver   { public function __construct(SourceScope $scope); }  // SPEC §5.1
final class Record\XdebugDriver implements CoverageDriver { public function __construct(SourceScope $scope); }
final class Record\DriverDetector
{
    public static function detect(SourceScope $scope): ?CoverageDriver;   // pcov preferred, then xdebug
    /** 'pcov' | 'xdebug' | null — extension loaded, regardless of enabled/mode (wrapper decides how to relaunch) */
    public static function loadedExtension(): ?string;
    public static function availableName(): ?string;                       // name of what detect() would return
}

final class Record\SourceScope              // port
{
    /** @param list<string> $includes absolute @param list<string> $excludes absolute */
    public function __construct(array $includes, array $excludes);
    public static function fromProjectRoot(string $projectRoot, ?\PHPUnit\TextUI\Configuration\Configuration $configuration = null): self;
    // TOP_LEVEL_NOISE: vendor node_modules .git .idea .vscode .github .phpunit.cache .cache  + NESTED: storage/framework storage/logs bootstrap/cache (SPEC §5.2)
    public function contains(string $absoluteFile): bool;
    /** @return list<string> */ public function includes(): array;
}

final class Record\Recorder                 // port; file-level reduction heuristic from Pest
{
    public function __construct(CoverageDriver $driver);
    public function beginTest(string $testFileAbsolute): void;        // no-op if a test is already open
    public function endTest(): void;
    public function currentTestFile(): ?string;
    public function linkSource(string $absoluteSourceFile): void;
    public function linkTable(string $table): void;
    /** @return array<string, list<string>> test file (abs) → source files (abs) */
    public function perTestFiles(): array;
    /** @return array<string, list<string>> */ public function perTestTables(): array;
    public function reset(): void;
}

final class Record\ResultCollector          // port (uses TestStatus for ordering); SPEC §5.4 precedence
{
    public function testPrepared(string $testId, ?string $testFile = null): void;
    public function testPassed(): void;
    public function testFailed(string $message): void;
    public function testErrored(string $message): void;
    public function testSkipped(string $message): void;
    public function testIncomplete(string $message): void;
    public function testRisky(string $message): void;
    public function testTriggeredWarning(string $message): void;
    public function testTriggeredNotice(string $message): void;
    public function testTriggeredDeprecation(string $message): void;
    public function recordAssertions(string $testId, int $assertions): void;
    public function finishTest(): void;
    public function hasUnfinishedTest(): bool;
    /** @return array<string, TestResultArray> (file = absolute as given) */ public function all(): array;
    public function reset(): void;
}
```

### PHPUnit subscribers (`src/PHPUnit/Subscribers/`)

One class per event, `final readonly`, constructor takes the collaborator. Names:
`StartRecordingOnPreparationStarted(Recorder)`, `StopRecordingOnFinished(Recorder)`,
`CollectResultOnPreparationStarted(ResultCollector)`, `RecordPassed`, `RecordFailed`, `RecordErrored`,
`RecordSkipped`, `RecordMarkedIncomplete`, `RecordConsideredRisky`, `RecordWarningTriggered`,
`RecordPhpWarningTriggered`, `RecordNoticeTriggered`, `RecordPhpNoticeTriggered`,
`RecordDeprecationTriggered`, `RecordPhpDeprecationTriggered`, `RecordAssertionsOnFinished`,
`FlushOnExecutionFinished(RunWriter)`, `MarkTruncatedOnExecutionAborted(RunWriter)`.
Only `TestMethod` tests are recorded (`$event->test() instanceof TestMethod`); `wasSuppressed()` issues are ignored.

Every place that needs "the file this test's edges/result/not-cacheable-marker/database-table
widening counts against" — `StartRecordingOnPreparationStarted` (both its replay-decision and its
`beginTest()` calls), `CollectResultOnPreparationStarted`, the class-level branch of
`RecordNotCacheableOnPreparationStarted`, and `Laravel\Subscribers\ArmLaravelTrackersOnPrepared` —
calls `PHPUnit\TestMethodFile::of(TestMethod $test): string` rather than `$test->file()` directly.
`file()` resolves through `ReflectionMethod::getFileName()`, which returns the DECLARING class's
file for an inherited method (an abstract base a concrete `final` subclass extends with no test
methods of its own); `of()` instead reflects `$test->className()` — always `$testCase::class`, the
concrete, actually-running class — and falls back to `$test->file()` only when that class cannot
be reflected (does not happen in ordinary operation: a `TestMethod` is only ever built from an
already-instantiated test object). See "Abstract-base test classes" below.

### Run partials (written by the extension, read by the wrapper) — `<stateDir>/runs/<run-id>/`

```php
final class Record\RunWriter
{
    public function __construct(string $runDir, string $projectRoot);
    public function markTruncated(): void;
    /** writes edges.json, results.json, tables.json, meta.json atomically */
    public function flush(Recorder $recorder, ResultCollector $collector, array $meta): bool;
}
// FlushOnExecutionFinished(RunWriter $writer, Recorder $recorder, ResultCollector $collector, \Closure $meta)
final readonly class Record\RunPartial
{
    /** @param array<string, list<string>> $edges rel→rel  @param array<string, TestResultArray> $results (file rel)  @param array<string, list<string>> $tables */
    public function __construct(public array $edges, public array $results, public array $tables, public array $meta);
    public static function load(string $runDir): ?self;
}
```

`meta.json`: `{"driver":"pcov","php":"8.4","os":"Linux","mode":"record","truncated":false,"startedAt":<unix>,"finishedAt":<unix>,"fingerprint":{...}}`.

## Select

```php
final readonly class Select\TestPaths       // port
{
    /** @param list<string> $directories rel, no trailing '/' @param list<string> $files rel @param list<string> $suffixes */
    public function __construct(array $directories, array $files, array $suffixes);
    public static function fromConfiguration(\PHPUnit\TextUI\Configuration\Configuration $configuration, string $projectRoot): self;
    public function isTestFile(string $relativePath): bool;
    /** @return list<string> */ public function directories(): array;
}

interface Select\WatchDefault { public function applicable(string $projectRoot): bool; /** @return array<string, list<string>> pattern → test dirs */ public function defaults(string $projectRoot, array $testDirectories): array; }
// Select\WatchDefaults\{Php, Laravel (is_file(root/artisan)), Symfony (is_file(root/config/bundles.php))} per SPEC §7.2.6
final class Select\WatchPatterns            // port minus Pest marks
{
    public function useDefaults(string $projectRoot, array $testDirectories): void;
    /** @param array<string, string|list<string>> $patterns */ public function add(array $patterns): void;
    /** @return array<string, list<string>> */ public function patterns(): array;
    /** @return array<string, list<string>> pattern → matched changed files */ public function matches(string $changedFile): array;
    /** @param list<string> $changed @return list<string> test dirs/files */ public function matchedDirectories(string $projectRoot, array $changed): array;
    /** @param list<string> $directories @param list<string> $allTestFiles @return list<string> */ public function testsUnderDirectories(array $directories, array $allTestFiles): array;
}

final readonly class Select\Reason { public function __construct(public string $rule, public string $trigger, public string $detail = ''); }
final class Select\Selection
{
    /** @return list<string> */ public function testFiles(): array;
    /** @return array<string, list<Reason>> */ public function reasons(): array;
    public function add(string $testFile, Reason $reason): void;
    public function has(string $testFile): bool;
    public bool $sourcePhpChanged;                                     // any project .php outside tests changed
}
final class Select\Context                  // passed through the rule chain
{
    public Graph $graph; public string $projectRoot; public TestPaths $testPaths; public WatchPatterns $watch;
    /** @var list<string> */ public array $remaining;                  // changed files not yet consumed
    public Selection $selection;
    public function consume(string $rel): void;
}
interface Select\Rule { public function name(): string; public function apply(Context $context): void; }
// Rules\PhpEdgeRule (files with an id in graph, deleted included), Rules\TestFileRule (isTestFile && exists), Rules\WatchRule (rest, unknown to graph)
final class Select\Selector
{
    /** @param list<Rule> $rules */
    public function __construct(Graph $graph, TestPaths $testPaths, WatchPatterns $watch, string $projectRoot, array $rules);
    public static function default(Graph $graph, TestPaths $testPaths, WatchPatterns $watch, string $projectRoot): self;
    /** @param list<string> $changed relative */
    public function affected(array $changed): Selection;              // drops test files no longer on disk
}
```

## PHPUnit glue

```php
enum PHPUnit\Mode: string { case Record = 'record'; case RecordSubset = 'record-subset'; case ResultsOnly = 'results-only'; case Replay = 'replay'; case Off = 'off'; }
final class PHPUnit\ConfigurationReader       // thin, version-tolerant reads of PHPUnit Configuration (11.5 vs 12 vs 13)
{
    public function __construct(\PHPUnit\TextUI\Configuration\Configuration $configuration, ?\PHPUnit\TextUI\CliArguments\Configuration $cliConfiguration = null);
    public static function cliConfigurationFromArgv(): ?\PHPUnit\TextUI\CliArguments\Configuration;   // rebuilds the CLI half from $_SERVER['argv'] for bootInProcess(), which has only the already-merged Configuration; null on any failure
    public function hasPartialSelection(): bool;   // read from the CLI half ALONE (never the merged Configuration, which folds XML <groups>/defaultTestSuite in): hasFilter || hasExcludeFilter || hasGroups || hasExcludeGroups || hasTestSuite || hasExcludedTestSuite || arguments() !== []; true (fail closed) when the CLI half could not be determined at all
    public function shouldRerun(int $status): bool; // SPEC §6.2 shouldRerun
    /** @return list<string> */ public function sourceIncludeDirectories(): array;
    public function testSuffixes(): array;
    public function configurationFile(): ?string;
}
final class PHPUnit\ReplayExtension implements \PHPUnit\Runner\Extension\Extension  // SPEC §6.1
final class PHPUnit\ReplayState  // static holder booted by the extension: boot(Mode $mode, string $root, string $stateDir, string $runId, ?CoverageDriver $driver); accessors mode()/root()/stateDir()/runId()/recorder()/collector()/runWriter()/startedAt(); reset() for tests
```

Environment variables (wrapper → extension): `PHPUNIT_REPLAY_MODE`, `PHPUNIT_REPLAY_STATE_DIR`, `PHPUNIT_REPLAY_RUN_ID`, `PHPUNIT_REPLAY_ROOT`, `PHPUNIT_REPLAY_DEBUG`. `PHPUNIT_REPLAY=0` disables everything.

## Config

```php
final readonly class Config                  // SPEC §9 keys
{
    public ?string $stateDir; public ?string $remote; public ?string $remoteToken; public ?string $defaultBranch;
    /** @var array<string, string|list<string>> */ public array $watch; /** @var list<string> */ public array $neverCache;
    public int $quarantineReleaseAfter; public string $laravel; public bool $junitMerge;
    public string $mode; public bool $hermeticityHeuristics;
    public bool $staticDeclarationEdges;                              // SPEC §4.3.1, default false; env PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES=1|0
    public static function defaults(): self;
    public static function load(string $projectRoot): self;           // phpunit-replay.php if present (returns array), else defaults; then env overrides
    public static function fromArray(array $values): self;
    public static function fromExtensionParameters(\PHPUnit\Runner\Extension\ParameterCollection $parameters): self;
    public static function isKnownMode(string $mode): bool;
    public function mergeEnv(array $server): self;
}
```

## Static declaration edges (SPEC §4.3.1) — `src/Analysis/`

Off by default. Everything here is reached through a nullable collaborator, so with the flag
off the graph, the content keys and the selection are byte-for-byte what they were.

```php
final readonly class Analysis\FileFacts                  // one source file, as the classifier sees it
{
    public bool $parsed;                                  // false = php-parser could not read it; NOTHING may be inferred
    /** @var list<array{int, int}> */ public array $bodies;      // inclusive line spans of every function/method/closure stmt list
    /** @var list<string> */ public array $declares;             // FQ class-like names declared here
    /** @var list<string> */ public array $references;           // FQ names mentioned here (resolved names + class-shaped strings)
    public function declarationOnly(): bool;              // $parsed && $bodies === []
    public function coversAnyBodyLine(array $lines): bool;
}
final class Analysis\DeclarationScanner
{
    public const RULES_VERSION = 2;                       // BUMP whenever FactsVisitor's rules change: the content cache cannot notice, and (being structural) a bump re-records
    public function scan(string $absoluteFile): FileFacts;
    public function scanSource(string $source): FileFacts;
}
final class Analysis\FactsVisitor extends \PhpParser\NodeVisitorAbstract;   // runs after NameResolver, never standalone
final class Analysis\FactsCache      // <stateDir>/analysis/v<RULES_VERSION>/<xx>/<hash>.json, keyed by xxh128 of the RAW bytes (the payload is line ranges); one read, failures never stored
{
    public function __construct(string $stateDir, string $projectRoot, DeclarationScanner $scanner = new DeclarationScanner());
    public function for(string $absoluteFile): FileFacts;         // .blade.php is refused outright
    public function forRelative(string $relativeFile): FileFacts;
}
final class Analysis\StaticEdges
{
    public function __construct(string $projectRoot, Record\SourceScope $scope, FactsCache $facts, int $maxFiles = self::MAX_FILES);
    /** @return array<string, list<string>> lowercased FQ name => declaring files (relative) */
    public function index(): array;   // MAX_FILES only warns; giving up removed edges without adding any
    /** @param array<string, list<string>> $behaviouralEdges test (rel) => this run's coverage edges, one entry per executed test; $ignored declaring files (rel) to refuse linking (no-edges-to-ignored-files fix), empty by default @return int edges added */
    public function expand(Cache\Graph $graph, array $behaviouralEdges, array $ignored = []): int;
}
```

Wiring, all of it optional and null/false by default:

| Seam | With the flag on |
|---|---|
| `Record\Recorder::__construct(CoverageDriver, ?FactsCache)` | an edge needs an executed line inside a body; an unparseable file falls back to the Pest heuristic |
| `Cache\GraphUpdater::__construct(..., ?StaticEdges)` | `expand()` runs right after `unionEdges()`, before `mergeResults()` computes content keys |
| `Select\RunListBuilder::__construct(..., bool $staticDeclarationEdges)` | `Select\ResiduePatterns` adds each changed-but-unknown `.php` path as its own watch pattern (shared with `Console\Commands\ExplainCommand`, so `explain` shows the same plan) |
| `Console\Commands\{Pull,Status}Command` | must pass the flag to `Fingerprint::compute()` too — omitting it makes the "current" fingerprint lack a key the stored one has, which `detectDrift()` reads as permanent drift |
| `Cache\Fingerprint::compute(..., bool $staticDeclarationEdges)` | adds `structural.static_declaration_edges = true` and `structural.analysis_rules = RULES_VERSION` — present only when on; the parameter has no default, twice bitten |
| `Console\Runner\RunPipeline::baseEnv()` | sets `PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES=1` for the PHPUnit child and its Paratest workers |
| `PHPUnit\ReplayState::boot(..., bool $staticDeclarationEdges)` | builds the `Recorder`'s `FactsCache`; `bootInProcess()` also builds the `StaticEdges` the in-process persist path uses |

`Console\Commands\PruneCommand`'s `--all` now clears `<stateDir>/analysis/` too (its
`removeRunsDirectory()` became the generic `removeDirectory($stateDir, $name)`).

Fixture: `tests/Fixtures/Projects/declarations` (`FixtureProject::declarations()`) — four test
files over a cases-only enum, a constants-only class, one class with real bodies, and a
`lang/`-shaped `return [...]` file nothing loads. Tests carry
`#[Group('static-declaration-edges')]`, so `--exclude-group static-declaration-edges`
reproduces the pre-feature suite exactly.

## Test support (`tests/Support/`)

```php
final class Tests\Support\TempDir  { public static function make(string $prefix): string; public static function remove(string $dir): void; }
final class Tests\Support\GitRepo  // wraps a temp dir: init (user.name/email set, branch main), commit(msg), write(rel, content), delete(rel), rename(a,b), sha(), git(...$args): string
final class Tests\Support\FixtureProject // copies tests/Fixtures/Projects/<name> into a TempDir, git init + initial commit, returns root
```

Unit tests never touch `$HOME`; they pass an explicit `stateDir` inside a TempDir.

## Wave 2 additions

### Graph updating (SPEC §7.3) — single implementation shared by wrapper and extension

```php
final class Cache\GraphUpdater
{
    public function __construct(Graph $graph, string $projectRoot, ContentKey $contentKey);
    /**
     * Applies a run partial. $complete = the run covered everything it was asked to and was not truncated.
     * - always: merge results of test files the graph knows (or all, when $recordsEdges); recompute `key` for touched test files
     * - when $recordsEdges: unionEdges (merged into what the graph already had, never replaced — see Graph::unionEdges()) for executed test files, markKnownTestFiles(executed files), replaceTestTables
     * - when $complete && $recordsEdges: pruneStaleResults(branch, touched, keep ids), pruneMissingTestFiles, pruneResultsForMissingFiles
     * @return array{touched: list<string>, results: int, edges: int}
     */
    public function apply(RunPartial $partial, string $branch, bool $recordsEdges, bool $complete): array;
    /** After a complete pass: setRecordedSha + markBaselineComplete + pruneMissingBranches(keep). */
    public function finalizeBaseline(string $branch, ?string $sha, array $keepBranches): void;
}
```

### Generated PHPUnit configuration (filtered mode, SPEC §3.1.6)

```php
final class PHPUnit\ConfigurationWriter
{
    /**
     * Loads the user's phpunit.xml(.dist) as DOM, replaces <testsuites>/<testsuite> with a single
     * <testsuite name="phpunit-replay"> listing one <file> per entry of $testFiles (relative to the
     * XML's directory), injects <extensions><bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension"/>
     * when absent, keeps everything else verbatim, and writes the result next to the source file
     * as `.phpunit-replay.xml` (same directory → relative paths keep working). Returns the written path.
     * @param list<string> $testFiles project-relative
     */
    public function write(string $sourceXml, array $testFiles, string $projectRoot): string;
    public static function TEMP_BASENAME = '.phpunit-replay.xml';
}
```

### Wrapper pipeline

```php
final class Console\Runner\PhpunitProcess     // builds+runs `php -d pcov.enabled=1 -d pcov.directory=<root> <root>/vendor/bin/phpunit -c <xml> --no-coverage <args>` with env; streams output through; returns exit code
final class Console\Runner\RunPipeline        // SPEC §3.1 steps 1–12; every failure path = warn to stderr + run plain phpunit
final class Report\Summary                    // formats "Replay  ✓ N executed (a affected, u uncached) · M replayed · q quarantined · baseline <branch>@<sha7> · saved Xs"
final class Report\JUnitMerger                // merge(realJunitXml|null, array<string, TestResultArray> replayed, string $projectRoot): string  — adds <testcase ... ><properties><property name="replayed" value="true"/></properties>
```

Exit code of the wrapper is always PHPUnit's exit code (0 when nothing needed to run).

## Wrapper pipeline — detailed algorithm (phase 1, filtered mode)

`Console\Runner\RunPipeline::run(RunRequest $request): int` — `RunRequest` = `{cwd, phpunitArgs: list<string>, fresh, noRemote, explain, dryRun, logJunit: ?string, allowCiBaseline, record: bool}`.
Every "degrade" below means: print one line to STDERR prefixed `phpunit-replay: ` and run PHPUnit exactly as the user would have (`vendor/bin/phpunit <args>`), returning its exit code — unless `request.dryRun`, in which case `degrade()` (the single funnel every degrade case goes through) instead prints `Replay  would run the full suite via plain phpunit (degraded: <reason>)` to STDOUT and returns 0 without ever launching PHPUnit. The wrapper never swallows PHPUnit output and never changes its exit code (dry runs aside, which never launch it at all).

1. **Root & tools.** `root = Git::topLevel(cwd)`; if git is unavailable, not a repo, or has no commits → degrade ("git repository with at least one commit required"). `phpunitBin = <root>/vendor/bin/phpunit` (override: env `PHPUNIT_REPLAY_PHPUNIT_BIN`); missing → degrade. Config file: `-c|--configuration <file>` in phpunitArgs, else `<root>/phpunit.xml`, else `<root>/phpunit.xml.dist`; none → degrade ("filtered mode needs phpunit.xml").
2. **Config & state.** `Config::load(root)`; `stateDir = StateDirectory::resolve(config.stateDir, root)`; `branch = Git::currentBranch() ?? 'HEAD'` (detached → `persist = false`); `defaultBranch = config.defaultBranch ?? Git::defaultBranch() ?? 'main'`; `head = Git::currentSha()`.
3. **Driver.** `ext = DriverDetector::loadedExtension()`: `pcov` → php flags `-d pcov.enabled=1 -d pcov.directory=<root>`; `xdebug` → `-d xdebug.mode=coverage`; null → no driver (`driverName = 'none'`). Flags go before the script path. PHPUnit always receives `--no-coverage` when we record edges.
4. **PHPUnit configuration object.** Build a `PHPUnit\TextUI\Configuration\Configuration` in the wrapper process from the XML file + phpunitArgs using PHPUnit's `XmlConfiguration\Loader`, `CliArguments\Builder` and `Configuration\Merger` (never `Registry::init`). Wrap in `PHPUnit\ConfigurationReader`. `request.record && reader->hasPartialSelection()` → degrade ("record does not accept a partial PHPUnit selection (--filter/--exclude-filter/--group/--exclude-group/--testsuite/--exclude-testsuite/an explicit path): it always records the complete suite, unconditionally (SPEC.md §3.3) — use `run` or `verify` instead"), checked here — before the graph is even loaded (step 6) — because a partial run cannot produce record's one product: a complete, prunable, publishable baseline. `TestPaths::fromConfiguration(...)`.
5. **Fingerprint.** `fp = Fingerprint::compute(root, driverName)`.
6. **Graph.** `store = new GraphStore(stateDir, root)`; `graph = request.fresh ? null : store->load()`. If graph: `structuralDrift(graph.fingerprint, fp)` non-empty → warn "structural change (<keys>): recording a fresh baseline" → `graph = null`; else `environmentalDrift` non-empty → warn → `graph->clearResults()`. `graph?->setDefaultBranch(defaultBranch)`.
7. **Partial selection?** Only reached when `request.record` is false — the combination was already degraded in step 4 above. `reader->hasPartialSelection()` (also true when `request.record` is false and phpunitArgs contain a positional path) → **results-only run**: `request.dryRun` → print `Replay  would run only your own selection (--filter/--group/--testsuite/an explicit path); no plan to compute for a partial selection` to STDOUT, return 0, without launching PHPUnit (this branch sits before steps 8-9, so it never reaches the dry-run handling described there or in step 9). Else: xml = `ConfigurationWriter::withExtensionOnly(configFile)` (extension injected, testsuites untouched), run PHPUnit with env `MODE=results-only`; the exit code is returned untouched — `GraphUpdater::apply()` is never called at all for this pass (bug fix: it used to be called with `recordsEdges:false, complete:false` whenever `graph !== null`, then saved — but `recordsEdges:false` only gates edge writing, not result merging, so an already-known test file's real result still entered, and was persisted with, the graph; a CLI selection now persists nothing: no edges, no results, no sha, no prune, no snapshot, no quarantine write). No missing-driver check either: `$iniFlags` is always `[]` here, so no coverage data is ever collected or required, unlike step 8's `record`.
8. **Record** (graph null, or `request.record`): no driver → degrade ("no coverage driver: install pcov or enable xdebug coverage") — subject to the dry-run short-circuit above. `request.dryRun` (driver present) → print `Replay  would record the full suite (no cached baseline)` to STDOUT, return 0, without constructing a `Graph`, opening a run directory, or launching PHPUnit — this is the path a bare `run --dry-run` takes on a project with no cached baseline yet. Else `graph = new Graph(root)`, fingerprint set, run PHPUnit with env `MODE=record` and the extension injected, then `apply(partial, branch, recordsEdges:true, complete: !partial.meta.truncated && exit ∈ {0,1})`; if complete and persist and (!CI or allowCiBaseline): `finalizeBaseline(branch, head, Git::branchNames())`, snapshot `LastRunTree(branch, head, ChangedFiles::snapshotTree(ChangedFiles::since(head) ?? []))`; save graph; print `RecordSummary`; exit code. Fingerprint is re-computed after the run: if structural changed during the run → discard graph, warn.
9. **Replay.** `sha = graph->recordedSha(branch)`; null → treat as record (step 8, keep graph edges? no: fresh). `changed = ChangedFiles::since(sha)`; null → warn "baseline <sha7> is not an ancestor of HEAD: recording a fresh baseline" → step 8. `lastRun = LastRunTree::load(stateDir)`; if `lastRun?->branch === branch` → `changed = lastRun->filterUnchanged(changed, changedFiles)`. `selection = Selector::default(...)->affected(changed)`.
   Run list `L` = `selection.testFiles()` ∪ `unknown` (test files on disk under `TestPaths` directories/suffixes that `!graph->knowsTest()`) ∪ `rerun` (files of results in `graph->results(branch)` whose status `reader->shouldRerun()` and exist on disk). Counters: `affected = |selection|`, `uncached = |unknown ∪ rerun \ selection|`, `replayed = number of results whose file ∉ L`, `saved = Σ time of those results`.
   If `selection.sourcePhpChanged && driverName === 'none'` → `L = all test files on disk` and the run is results-only (cannot refresh edges) — warn.
   `--explain`: print one line per file in L: `<file>  ← <Rule>  <trigger> (<detail>)` (unknown → `Uncached  new test file`, rerun → `Rerun  <status name>`), sorted by file. `--dry-run`: print explain + summary, exit 0.
   `L = []` → print Summary(executed 0, replayed N, success true), write merged JUnit when `logJunit` (real = null), save LastRunTree snapshot, exit 0 — without launching PHP.
   Else: xml = `ConfigurationWriter::write(configFile, L, root)`; env `MODE = driver ? 'record-subset' : 'results-only'`; add `--log-junit <stateDir>/runs/<id>/junit.xml` when `logJunit`; run; `apply(partial, branch, recordsEdges: MODE==='record-subset', complete: !truncated && exit ∈ {0,1})`; when complete and persist and (!CI or allowCiBaseline): `finalizeBaseline(branch, head, branchNames)` and snapshot LastRunTree; re-check fingerprint; save graph; JUnit merge (`JUnitMerger::merge(realJunit, replayedResults, root)` → write to `logJunit`); print Summary(success = exit === 0); return exit.
10. **Cleanup** (`finally`): delete the generated xml and `runs/<id>/`. Keep them when `PHPUNIT_REPLAY_KEEP_RUN=1`.
11. **Debug.** `PHPUNIT_REPLAY_DEBUG=1` → every decision (root, branch, sha, driver, drift, changed list, selection reasons, run list, mode, env passed) is printed to STDERR prefixed `[replay] `.
12. **Env passed to PHPUnit:** `PHPUNIT_REPLAY_MODE`, `PHPUNIT_REPLAY_STATE_DIR`, `PHPUNIT_REPLAY_RUN_ID` (`date('Ymd-His') . '-' . bin2hex(random_bytes(3))`), `PHPUNIT_REPLAY_ROOT`, `PHPUNIT_REPLAY_DEBUG` (propagated). Output passthrough: `Process::setTty(true)` when STDOUT is a TTY and TTY is supported, else stream both pipes to STDOUT/STDERR. Timeout: none.

`ConfigurationWriter::withExtensionOnly(string $sourceXml): string` — same as `write()` but keeps `<testsuites>`. Both write `<dir of source>/.phpunit-replay.xml`.

## Extension behaviour (phase 1)

`ReplayExtension::bootstrap()`:
- `PHPUNIT_REPLAY=0` → return. No `PHPUNIT_REPLAY_MODE` env → phase 1: return (in-process replay is phase 2; parameter `mode` is read but only `off` has an effect now).
- MODE `record` | `record-subset`: `stateDir`/`runId`/`root` from env (root fallback: dirname of `$configuration->configurationFile()`, else cwd). `scope = SourceScope::fromProjectRoot(root, $configuration)`; `driver = DriverDetector::detect(scope)`; null → STDERR warning `phpunit-replay: no coverage driver available inside PHPUnit (pcov.enabled=1 or xdebug.mode=coverage); recording results only` and behave like `results-only`. Register ResultCollector subscribers, Recorder subscribers (`StartRecordingOnPreparationStarted`, `StopRecordingOnFinished`), `FlushOnExecutionFinished`, `MarkTruncatedOnExecutionAborted`.
- MODE `results-only`: ResultCollector subscribers + flush.
- `meta` written by flush: `{driver, php, os, mode, truncated, startedAt, finishedAt, fingerprint: Fingerprint::compute(root, driver)}`.
- `ReplayState` (static) holds `mode`, `root`, `stateDir`, `runId`, the `Recorder`, `ResultCollector`, `RunWriter`; `ReplayState::reset()` for tests.

## Phase 2 contracts

### Shared services extracted from `RunPipeline` (used by wrapper AND in-process extension)

```php
final readonly class Cache\RunContext
{
    public function __construct(public string $root, public string $stateDir, public string $branch, public ?string $head,
        public string $defaultBranch, public bool $persist /* false on detached HEAD */, public bool $ciMode, public bool $allowCiBaseline);
}
final class Cache\BaselineWriter          // was RunPipeline::persistAfterRun
{
    public function __construct(GraphStore $store, Git $git, ChangedFiles $changedFiles);
    /** complete → finalizeBaseline(branch, head, branchNames) + LastRunTree snapshot (unless CI without allowCiBaseline → warning); always store->save(). */
    public function commit(Graph $graph, GraphUpdater $updater, RunContext $ctx, bool $complete): bool;
}
final class Select\RunList
{
    public Selection $selection; /** @var list<string> */ public array $unknown; /** @var list<string> */ public array $rerun; /** @var list<string> */ public array $quarantined;
    /** @return list<string> sorted union */ public function files(): array;
    public function has(string $testFileRel): bool;
    public function reasonsFor(string $testFileRel): array;     // Reason objects incl. synthetic ones: Uncached ("new test file"), Rerun (status name), Quarantine (reason)
}
final class Select\RunListBuilder          // was RunPipeline::computeRunList/unknownTestFiles/rerunFiles
{
    public function __construct(Graph $graph, TestPaths $testPaths, WatchPatterns $watch, ConfigurationReader $reader, Hermeticity\Policy $policy, string $projectRoot);
    /** @param list<string> $changed relative */
    public function build(array $changed, string $branch): RunList;
}
```

### In-process replay (SPEC §3.2, §6; docs/spikes/in-process-replay.md)

```php
namespace Manuglopez\Replay\PHPUnit\Decision;
abstract readonly class Decision { public function isReplay(): bool; }
final readonly class Run extends Decision            { public function __construct(public string $reason /* affected|uncached|rerun|quarantined|not-cacheable|depends-provider|no-baseline */) {} }
final readonly class ReplayPass extends Decision     { public function __construct(public int $assertions, public bool $wasRisky, public array $cached /* TestResultArray */) {} }
final readonly class ReplaySkipped extends Decision  { public function __construct(public string $message, public array $cached) {} }
final readonly class ReplayIncomplete extends Decision { public function __construct(public string $message, public array $cached) {} }
```

`ReplayState` (phase 2 additions):

```php
public static function bootInProcess(Config $config, \PHPUnit\TextUI\Configuration\Configuration $configuration): Mode;  // resolves root/stateDir/git/graph/fingerprint/RunList; returns the mode it settled on (Record | Replay | ResultsOnly | Off) — SPEC §6.1 decision list
public static function decide(string $testFileAbsolute, string $testId): Decision;   // cached per testId; SPEC §6.2 algorithm + "depends provider → Run" + Policy
public static function markReplayed(string $testId, Decision $decision): void;       // remembers cached result to restore on persist
public static function counters(): array{affected:int, uncached:int, replayed:int, quarantined:int, executed:int}
public static function isDependsProvider(string $className, string $methodName): bool;   // MetadataRegistry::parser()->forClass($className): any DependsOnMethod metadata targeting $methodName (cache per class)
public static function persistInProcess(): void;   // at TestRunner\ExecutionFinished: build RunPartial in memory (replayed ids → cached result restored: status/time/assertions/message), GraphUpdater::apply(recordsEdges = driver && !resultsOnly, complete = !truncated), BaselineWriter::commit, Quarantine save
public static function summaryLine(): ?string;     // printed by PrintSummaryOnApplicationFinished (Application\Finished — the only event emitted after PHPUnit prints its result)
```

Trait `PHPUnit\Replayable` (public API: `isReplaying(): bool`; everything else prefixed `__replay`):
- PHPUnit ≥ 12 hook: `protected function invokeTestMethod(string $methodName, array $testArguments): mixed` — if decision is a replay → apply it and return null, else `parent::invokeTestMethod(...)`.
- PHPUnit 11.5 fallback (also forced by env `PHPUNIT_REPLAY_LEGACY_HOOK=1`, used by tests on 12 and 13): `#[Before] public function __replayBefore(): void` — only when `!method_exists(\PHPUnit\Framework\TestCase::class, 'invokeTestMethod')` or the env is set; swaps private `TestCase::$methodName` via `ReflectionProperty` to `__replayStub`; `public function __replayStub(mixed ...$args): mixed` restores the name, applies the decision, returns null.
- Applying: `ReplayPass` → `if ($assertions === 0 && !$wasRisky) expectNotToPerformAssertions(); addToAssertionCount($assertions); ReplayState::markReplayed(id, d)`; `ReplaySkipped` → `markTestSkipped(msg)`; `ReplayIncomplete` → `markTestIncomplete(msg)`.
- `decide()` is called with `(new ReflectionClass(static::class))->getFileName()` and `$this->valueObjectForEvents()->id()`.
- Recorder must not record replayed tests: `StartRecordingOnPreparationStarted` consults `ReplayState::decide()` when `ReplayState::isInProcess()`; replayed → skip `beginTest`.
- Hazard, real and handled now that PHPUnit 13.1+ is supported: PHPUnit 13.3.0 adds `--repeat`/`--retry` (`#[Repeat]`/`#[Retry]`, `RepeatTestSuite`/`RetryTestSuite`, absent from 12.5/13.0.x/13.1/13.2) and, from that version on, `TestMethod::id()` appends `' (repetition %d of %d)'` when `totalRepetitions > 1` and `' (attempt %d of %d)'` when `attempt > 1`. `ResultCollector` keys results and `decide()` looks up replay decisions by exactly this id, so it is not stable across runs that differ in those flags: a graph recorded under `--repeat`/`--retry` would miss on a plain run (fails safe on its own — a bare id never matches a suffixed one, so a miss, not a false replayed pass), but replaying a repetition or retry itself would defeat what those flags are for. Two distinct triggers for this, fixed at two distinct granularities: a `--repeat`/`--retry` **CLI flag** makes the hazard global to the whole run (every test's id could gain a suffix), so the whole run is treated as not cacheable rather than relying on the id alone — `ConfigurationReader::repeatOrRetryRequested()`, checked by `RunPipeline` and `ReplayExtension::bootstrapInProcess()` before either registers a single subscriber; the detection mechanism itself is deliberately not detailed here. A `#[Repeat]`/`#[Retry]` **attribute**, by contrast, is read by `PHPUnit\Framework\TestBuilder` regardless of any CLI flag (a method-level `#[Repeat]` takes precedence over `--retry`, per its own comments), so a decorated method gets the same suffixed ids on *every* run, flag or no flag — a whole-run degrade would be the wrong trade for one decorated method, so this is instead handled **per test**: subscriber `RecordRepeatOrRetryNotCacheableOnPreparationStarted` marks the currently-preparing test's exact `TestMethod::id()` not-cacheable (whatever repetition/attempt suffix it currently carries, including the bare, unsuffixed one a first repetition/attempt always gets) through the same `NotCacheableCollector`/`Graph::isNotCacheable()` path `#[NotCacheable]` uses (below), detected via `ReflectionMethod::getAttributes()` matching the attribute's class name as a literal string — unlike `MetadataRegistry::parser()->forMethod(...)->isRepeat()`/`isRetry()`, that never needs the named class to be loadable, so it needs no version gating at all before 13.3.

Mode decision without wrapper env (`ReplayExtension::bootstrap` when `PHPUNIT_REPLAY_MODE` is absent): `Config::load(root)` merged with `Config::fromExtensionParameters` then env; `config.mode === 'off'` → Off; git unavailable → Off + warning; `hasPartialSelection()` → ResultsOnly; `mode === 'record'` or no valid graph (missing, structural drift, `--fresh` n/a) → Record when a driver is available else Off + warning `no coverage driver`; else Replay (edges recorded for executed tests when a driver exists). Bug fix: `hasPartialSelection()` here used to read the already-merged `Configuration` this method is handed — XML plus CLI, provenance gone — so a project's own XML `<groups><exclude>`/`defaultTestSuite` counted as a selection with zero CLI arguments, and `ResultsOnly` itself used to still merge results (never edges) for any already-known test file regardless. `bootInProcess()` now builds the `ConfigurationReader` with the CLI half reconstructed from `$_SERVER['argv']` (`ConfigurationReader::cliConfigurationFromArgv()` — the same source of truth the wrapper derives its own CLI-only half from, one definition instead of two notions of "the command line asked for a subset" drifting apart), and `ResultsOnly` now persists nothing at all — `persistInProcess()` returns immediately for it, before `GraphUpdater::apply()` is ever called. Replayed tests are excluded from `ChangedFiles`/graph mutations; the final `Summary` line uses `counters()`. When it is specifically `config.mode === 'record'` — a standing "always record" configuration, not just "no baseline yet" (an ordinary, silent bootstrap `run --filter` already shares through the wrapper) — that a partial selection downgraded to ResultsOnly, `decideMode()` sets `ReplayState::$recordModeDowngraded`, and that `Summary` line gains a `record mode: baseline NOT refreshed (partial selection)` segment (`Report\Summary::$recordModeDowngraded`) instead of a new, separate warning channel.

### Hermeticity (SPEC §8)

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Attributes\NotCacheable { public function __construct(public string $reason = '') {} }

final class Hermeticity\Quarantine          // <stateDir>/flaky.json  {"<testId>": {"firstSeen": unix, "flips": n, "stable": n, "lastKey": "k", "reason": "flip|divergence"}}
{
    public static function load(string $stateDir): self;   public function save(string $stateDir): bool;
    public function isQuarantined(string $testId): bool;   /** @return list<string> */ public function testIds(): array;
    public function recordFlip(string $testId, string $key, string $reason = 'flip'): void;      // flips++, stable = 0
    public function recordStable(string $testId): void;     // stable++; released when stable >= releaseAfter
    public function release(string $testId): void;  public function clear(): void;
    public function setReleaseAfter(int $n): void;
    /** @return array<string, array{firstSeen:int, flips:int, stable:int, lastKey:string, reason:string}> */ public function all(): array;
}
final class Hermeticity\Policy
{
    public function __construct(Graph $graph, Config $config, Quarantine $quarantine, string $projectRoot);
    public function cacheable(string $testFileRel, string $testId): bool;   // !graph->isNotCacheable(file) && !graph->isNotCacheable(testId) && !neverCacheGlob(file) && !quarantine->isQuarantined(testId)
    public function reason(string $testFileRel, string $testId): ?string;   // 'attribute' | 'never_cache' | 'quarantine' | null
    /** @return list<string> test files that contain at least one non-cacheable test (for RunList) */ public function nonCacheableFiles(array $allTestFiles, array $resultsByFile): array;
}
```
- Recording the attribute: subscriber `RecordNotCacheableOnPreparationStarted` (TestMethod → `ReflectionClass::getAttributes(NotCacheable::class)` → file rel; `ReflectionMethod` → `Class::method`) collects into `ResultCollector`-adjacent `NotCacheableCollector`; `RunWriter::flush` writes `not_cacheable.json` (list); `RunPartial::$notCacheable`; `GraphUpdater::apply` replaces entries for executed files/classes (`Graph::setNotCacheable(array_values(array_unique([...kept for non-executed files, ...partial])))`). `Graph::isNotCacheable(string $fileOrTestId): bool`. A second subscriber, `RecordRepeatOrRetryNotCacheableOnPreparationStarted`, adds to the same `NotCacheableCollector` for the `#[Repeat]`/`#[Retry]` case above: `ReflectionMethod::getAttributes()` against the two literal attribute-class-name strings (method-level only — both attributes are `Attribute::TARGET_METHOD`) → the test's own `TestMethod::id()`, suffix and all.
- Flip detection in `GraphUpdater::apply` (constructor gains `?Quarantine $quarantine = null`): for every incoming result with a previous cached result for the same id where `old.key === new.key` and `class(old.status) !== class(new.status)` with `class = success-like {0,3,4,5,6} | failure-like {7,8} | skipped {1} | incomplete {2}` → `recordFlip`; same class → `recordStable`.
- `RunListBuilder` adds files of quarantined test ids and non-cacheable files to `RunList::$quarantined` (Reason `Quarantine <testId>` / `NotCacheable <reason>`).

### Argv pre-split (`Console\Application`, SPEC §11)

`Application::run()` pre-processes `$_SERVER['argv']` before Symfony ever parses it (class
docblock: an unrecognised option makes `ArgvInput::bind()` throw before any command-specific
parsing, with no way to recover the tokens that triggered it), inserting a `--` at the first
token `splitPassthrough()` cannot attribute to the resolved command or the application itself
— everything from there on reaches PHPUnit/Paratest untouched. This is a **latch**, not a
per-token test: once one token is unrecognised, every later token — including a real own
option typed after it — goes to PHPUnit too.

Ownership is read from the live objects, never from a hand-maintained list:
`Command::getNativeDefinition()` for the resolved command's own options/shortcuts/arguments
(used instead of `getDefinition()` specifically because the merged definition — command +
application globals — is only populated once `mergeApplicationDefinition()` runs, inside
`Command::run()`/`Application::doRunCommand()`, both downstream of this pre-processing step,
so `getDefinition()` cannot be trusted here; `getNativeDefinition()` returns
`$this->definition`, populated by `configure()` inside the command's own constructor and
therefore already complete regardless of merge timing), and the application's own
`getDefinition()` for the global options/shortcuts every command inherits (`--help`,
`--quiet`, `--verbose`/`-v|-vv|-vvv`, ...). A `VALUE_REQUIRED` option (`--log-junit`,
`prune --keep-months`) is recognised only with its value already attached via `=`; the one
`VALUE_OPTIONAL` option (`--parallel`/`-p`) is additionally allowed Symfony's own "peek the
next token" trick on its bare form — both fall out of `InputOption::isValueRequired()`/
`isValueOptional()` rather than three separate hardcoded cases, since those are the only two
modes any option in this CLI actually uses.

Short options (`resolveShortToken()`) are a two-level rule, entirely from
`hasShortcut()`/`acceptValue()`, naming no option: first the WHOLE remainder as one exact
shortcut key (`InputDefinition::hasShortcut()`; this is also where Symfony itself expands
`--verbose`'s `-v|-vv|-vvv` alias string into three independent keys, so `-vv`/`-vvv` need no
regex either), and only if that fails, the first character alone as the shortcut with
anything left over as its glued value — owned whenever that shortcut `acceptValue()`s (`-p4`,
`-p10`, `-p=4` all resolve to `--parallel` this way, matching `ArgvInput::parseShortOption()`'s
own rule), an unimplemented cluster otherwise (`-hq`: not recognised — `-p` is the only own
shortcut anywhere in this CLI, so there is nothing to cluster it *with*). A glued value
carrying a literal leading `=` (`-p=4`) is additionally rewritten to drop it (`-p4`) before
the token is emitted, because Symfony's own `ArgvInput` does not do that itself — confirmed
empirically against the installed `symfony/console`, not assumed: `-p=4` parses to the
literal option value `"=4"`, which fails `RunRequest::parseParallel()`'s numeric check and
silently substitutes Paratest's auto-detected count — even though SPEC.md/README document
the form as `-p[=N]`. `explain <path>`'s positional argument is the one non-array argument
declared anywhere in the command set, so it falls out of a general "claim the first N
non-option-looking tokens, N = the command's own count of non-array arguments" rule that
would also cover a future command adding one, without naming `explain` anywhere.

Reading the live objects instead of a hand-maintained mirror also means correctness here
does not depend on which supported Symfony version (`composer.json`: `^6.4 || ^7.0 || ^8.0`)
is actually installed: Symfony 8.1 added a `--silent` global option that a hand-written list
tuned against an earlier version would have no way to know about.
`tests/Unit/Console/ApplicationArgvSplitTest.php` is the full behavioural contract — a
data-driven corpus generated by running the previous, hand-maintained-constants
implementation and reading every case back by hand, plus a few cases documenting the two
bugs it fixed instead of merely pinning (`--silent`; `-p=N`, above).

### Commands (SPEC §11)

- `explain <path>` — `ExplainCommand`: load graph (fail: "no baseline yet" exit 1); `rel = Paths::relative`; `Selector::default(...)->affected([rel])`; print one line per affected test file `%-40s ← %-8s %s` (same formatter as `--explain`, extracted to `Console\ExplainFormatter`); then `direct dependents: N` and, when the file is unknown to the graph, either the watch patterns matched or `no recorded test executes this file`. Exit 0.
- `prune [--flaky] [--branches] [--all] [--stale-edges]` — `PruneCommand`: `--flaky` → `Quarantine::clear()` + save; `--branches` → `pruneMissingBranches(git branchNames ∪ default)`; `--all` → delete `graph.json`, `flaky.json`, `last-run.json`, `divergence.json`, `runs/`; `--stale-edges` → `Graph::pruneMissingDependencies()`: drops a single dependency edge whose file no longer exists on disk, for a test file that still exists (never a whole test's edge set — that is `pruneMissingTestFiles()`'s job when the test file itself is gone); no flag → `pruneMissingTestFiles()` + `pruneResultsForMissingFiles(each branch)` + `--branches`. `--stale-edges` is opt-in only, never folded into the no-flag bundle: unlike deleted test files or unreachable branches (purely historical, zero effect on cache validity), a dependency edge is exactly what a changed file is matched against to decide whether a cached result can still be trusted (`Select\Rules\PhpEdgeRule` / `Graph::testFilesDependingOn()`), so dropping one wrongly is the specific mistake `Graph::unionEdges()` was written to prevent — it stays behind an explicit flag the user opts into, same as `--flaky`. Never triggered by "not observed in the last recording pass": coverage attribution is first-loader-wins and can vary run to run even across complete, full-suite passes (`Graph::unionEdges()`'s docblock), so only a dependency's file being confirmed absent from disk (`is_file()`) counts as stale. Prints what was removed. Exit 0.
- `verify [--parallel=N|-p] [-- <phpunit args>]` — `VerifyCommand` → `RunPipeline::runVerify`: record-mode full run (graph kept), launched through the same `runPhpunit()` branch `record`/`run` use — `PhpunitProcess` when `request.parallel` is null, `ParatestProcess` otherwise (§13). Before anything runs: snapshot `old = graph->results(branch)`, then `replaySetBeforeVerify()` — `sha = baseline.sha ?? graph->recordedSha(branch)` (null → `ReplaySet::none()`), `changed = ChangedFiles::since(sha)` (null → `ReplaySet::none()`), `LastRunTree::filterUnchanged`, `computeRunList()` (the same `Select\RunListBuilder` call `runReplay()` makes, unaffected by the caller's own CLI selection, and with no `pruneMissingTestFiles()` — a measurement must not delete state), then `ReplaySet::against(graph, branch, runList)`. Must happen at that point and not later: `apply` rewrites edges/keys/results in place, and the run list comes from a git diff plus a directory walk, which after the run would read a working tree the suite and the generated `.phpunit-replay.xml` had already changed. After `apply`, for each `id` in `partial->results`: `replaySet->has(id)` → `wouldReplay++`; then `old[id]`/`new[id]` missing or `old.key !== new.key` → `unverified++` if replayable, skip; `class(old.status) === 'fail'` → skip (§6.2 rerun, so never in `replaySet` either); classes equal → `quarantine->recordStable(id)`; else divergence `{testId, k, cached: old.status, actual: new.status, sha: head, at: unix}` + `quarantine->recordFlip(id, k, 'divergence')`. Persist `divergence.json` `{"runs": n, "entries": [...]}` (append, keep last 500 entries). Print `Verify  ✓ 1240 tests · 1198 would replay · 0 divergences · 0 unverified (lifetime: 2 in 143 runs)` (`✗` when divergences > 0 or PHPUnit failed; `unverified` never affects the symbol). Exit code = PHPUnit's. `status` prints `divergences: <lifetime> in <runs> verify runs` and the quarantined ids with flips.
- `Select\ReplaySet` — the single implementation of "would the fast lane have served this test from cache": every result `graph->results(branch)` holds whose `file` is not in the run list **and still exists on disk** (checked once per file, not per test). Run-list absence is almost the whole rule, because `RunListBuilder` has already folded the affected selection and the `unknown`/`rerun`/`quarantined`/`notCacheable` buckets into the list, so a surviving file is exactly one `ReplayState::decideFresh()` reaches its cached-result branch for — with the one exception the disk check covers: a deleted test file is in no bucket either (`unknown`/`notCacheable` come from a directory walk, `rerun` skips a missing file, `Selector::dropMissingTestFiles()` drops it from the selection), so it is absent from the list for the one reason that does *not* mean "replayable". `run` masks that by pruning first (deleting a test file is itself a change, so `pruneResultsForMissingFiles()` runs); `verify` must not prune, so the check belongs here rather than in the caller. `RunPipeline::replayedAgainst()` (what `run` and `--dry-run` report as replayed, plus seconds saved) reads the same class, so `run`'s figure and `verify`'s `would replay` cannot drift apart. Deliberately key-agnostic, matching `decideFresh()`. One shared imprecision: a `#[Depends]` provider counts here but is executed anyway (`ReplayState::isDependsProvider()`) — answering that needs the test class loaded and PHPUnit's metadata parser, which neither this nor `run`'s own summary has.
- `Graph::encode()` adds `"generator": "manuglopez/phpunit-replay " . Version::id()`. `Version::id()` returns `Composer\InstalledVersions::getPrettyVersion()` for this package verbatim — `v0.8.1` installed as a dependency, `dev-main` in a checkout of this repository — and `Version::UNKNOWN` (`'unknown'`) when Composer's runtime map cannot describe it. It is derived rather than written down because the hand-written constant it replaced still read `0.1.0-dev` in v0.8.0, so every graph in existence, including every one published to a shared remote, misreported the version that wrote it. `getReference()` is not appended: for the root package Composer reports the reference from the last autoloader dump, not the working tree's HEAD.

### Laravel (SPEC §7.2 rules 1/4/5, §10) — package must NOT depend on illuminate/*

```php
final class Laravel\LaravelDetector { public static function enabled(string $projectRoot, Config $config): bool; }  // config.laravel: 'on' | 'off' | 'auto' → class_exists(\Illuminate\Container\Container::class) && is_file(root/artisan)
final class Laravel\TableExtractor  // port of Pest TableExtractor: fromSql(string $sql): list<string>, fromMigrationSource(string $php): list<string>
final class Laravel\TableTracker    // arm(object $app, Recorder $recorder): void — $app['db']->listen(fn (QueryExecuted $q) => foreach TableExtractor::fromSql($q->sql) as $t → $recorder->linkTable($t))
final class Laravel\BladeTracker    // arm(object $app, Recorder $recorder, string $projectRoot): void — $app['view']->composer('*', fn ($view) => ...) links $view->getPath() UNLESS it is inside config('view.compiled') (read fresh per render, never cached at arm() time) or, as a fallback, SourceScope::isNestedNoisePath($projectRoot, $path) — see "No edges to files git ignores" below
final class Laravel\MigrationTables // tablesOf(string $projectRoot): list<string> (all tables of database/migrations/**/*.php via TableExtractor::fromMigrationSource); usesDatabase(string $className): bool (RefreshDatabase|DatabaseMigrations|DatabaseTransactions traits, recursively)
final class Laravel\BladeReferences // ancestorsOf(string $bladeRel, string $projectRoot): list<string> — static @include/@extends/@component/view('x')/<x-name> walk (port of Pest Graph::bladeAncestorsFor and helpers)
final readonly class Laravel\Subscribers\ArmLaravelTrackersOnPrepared implements PreparedSubscriber  // once per Container instance: binding marker 'phpunit-replay.armed'
final class Laravel\Rules\MigrationRule  // database/migrations/**/*.php changed → TableExtractor::fromMigrationSource → tests whose graph->testTables() intersect (Reason 'Migration', detail table names); unparseable → left for WatchRule
final class Laravel\Rules\SiblingRule    // new/unknown .php under app/Providers|Listeners|Events|Observers|Policies|Console/Commands, database/factories|seeders → tests with edges to files in the same directory (Reason 'Sibling')
final class Laravel\Rules\BladeRule      // unknown .blade.php → BladeReferences::ancestorsOf → tests with edges to an ancestor (Reason 'Blade')
final class Laravel\LaravelIntegration  // rules(...): list<Rule> in SPEC order (Migration first, Sibling/Blade after TestFile, before Watch); subscribers(Recorder): list<Subscriber>; augment(RunPartial, root): RunPartial (MigrationTables for database-using test files → tables ∪ all migration tables)
```
`Selector::default()` gains an optional `array $extraRules` inserted per the SPEC order. Recorder tables flow: `Recorder::perTestTables()` → `RunWriter` `tables.json` → `GraphUpdater::replaceTestTables`.

Fixture `tests/Fixtures/Projects/laravel-lite`: created from `composer create-project laravel/laravel`, reduced (sqlite `:memory:`, 3 migrations `users`/`posts`/`comments`, models `User`/`Post`, 4 Feature tests, 2 Blade views), with `manuglopez/phpunit-replay` as a path repository (`../../../..`, `@dev`) so the fixture's own `vendor/` contains PHPUnit (pinned `^12.5.12` in the fixture's own `require-dev`, independent of — and not exercising — this package's own 11.5/12/13 support matrix) and a symlinked copy of this package. `vendor/` is gitignored; integration tests `markTestSkipped` when `vendor/autoload.php` is missing. `FixtureProject::laravelLite()` copies the fixture WITHOUT `vendor/` and symlinks `vendor` to the fixture's installed one.

### Paratest (SPEC §13)

Wrapper: `--parallel|-p[=N]` → launches `vendor/bin/paratest -c <xml> --processes N --passthru-php="-d pcov.enabled=1 -d pcov.directory=<root>"` with the same env; `RunWriter` uses `runs/<id>/worker-<TEST_TOKEN>-{edges,results,tables,not_cacheable}.json` when env `TEST_TOKEN` is set; `RunPartial::load()` merges worker files (edges by union, results last-write-wins, meta from any worker, truncated = any). `brianium/paratest` becomes a dev dependency.

## Phase 3 contracts — distribution

### Remote cache (SPEC §9) — keys and backends

Keys: `graph/<project-key>/<branch>.json` (full graph of a branch baseline) and `objects/<yyyy-mm>/<k>.json`
(results of one test file by content key; the month shard is the write month; lookups try every shard, newest first).
`objects/*` are append-only and content-addressed: writing the same key twice is a no-op.

```php
interface Cache\Remote\RemoteCache
{
    public function get(string $key): ?string;
    public function put(string $key, string $body): void;      // never throws: failures → warning via a callback / returned false in a `lastError()`
    public function has(string $key): bool;
    /** @return list<string> keys under a prefix (used by prune --remote) */ public function keys(string $prefix): array;
    public function delete(string $key): void;
    public function name(): string;                            // 'null' | 'file' | 'http' | 'git'
    /** Called once per run before the first read; may fetch. */ public function begin(): void;
    /** Called once per run after the last write; may push/flush. */ public function end(): void;
}
final class Cache\Remote\NullRemoteCache
final class Cache\Remote\FilesystemRemoteCache     // remote = file:///path ; AtomicFile writes; keys map to paths
final class Cache\Remote\HttpRemoteCache           // remote = http(s)://host/prefix/ ; GET/PUT/HEAD/DELETE (+ Bearer token); S3/MinIO presigned or nginx dav_methods; 5 s timeouts; curl ext or stream wrapper
final class Cache\Remote\GitRemoteCache            // remote = git+ssh://…, git+https://…, or any URL ending in .git
final class Cache\Remote\RemoteCacheFactory        // from Config: scheme → backend; unknown → Null + warning
final class Cache\Remote\ObjectStore               // put/get of objects/<shard>/<k>.json + graph/<key>/<branch>.json on top of RemoteCache; local read-through cache in <stateDir>/remote/
                                                    // deviation: the project key is fixed at construction (`__construct(RemoteCache $remote, string $stateDir, string $projectKey)`), not a per-call argument — `graph(string $branch)`/`graphOf(string $branch, string $projectRoot)` take only the branch
```

Bug fix — publish markers vs. the read-through cache: `ObjectStore`'s local mirror
(`<stateDir>/remote/cache/objects/<k>.json`) does two jobs, and only one of them may happen
at `put()` time. As a *read* cache (written by `object()` on a successful `get()`) it is fine
to write immediately — a successful read really did read something. As `putObject()`'s
*publish marker* — the file whose mere existence makes the next `putObject()` for the same
`k` skip — it must mean "durably in the remote", which `put()` returning `true` does **not**
always mean: `FilesystemRemoteCache`/`HttpRemoteCache` are synchronous, but `GitRemoteCache`'s
`put()` only stages the local mirror working tree, and the object is not actually in the
shared remote until `end()` completes with `lastError() === null` (see below). `ObjectStore`
therefore buffers what `putObject()` accepts this session (`k => body`) and only writes the
marker from `confirmPublished(): int`, which is itself a no-op (writes nothing, returns 0)
whenever `lastError() !== null` — so a caller cannot turn a failed push into a permanently
skipped object even by forgetting the check. `RunPipeline::closeRemote()`,
`PHPUnit\ReplayState::closeRemote()` and `PushCommand` all call it right after `end()`.

### GitRemoteCache — automatic maintenance

- Mirror: `<stateDir>/remote/git/` = shallow clone (`--depth 1`, single branch, default `main`, configurable `remote_branch`).
- `begin()`: create the mirror if missing; otherwise `git fetch --depth 1 origin <branch>` + `git reset --hard FETCH_HEAD` when the mirror is older than `remote_refresh_seconds` (default 300). If fetch reports unrelated/rewritten history → wipe and re-clone. Any failure → warning, continue with what the mirror has (offline mode).
- `put()`: writes into the mirror working tree (AtomicFile) and remembers the path; `end()`: `git add` + one commit `replay: <project-key> <branch> <sha7> +N objects` + `git push origin HEAD:<branch>`; on rejection: `fetch` + `rebase` (objects never conflict; for `graph/**` keep ours) → retry up to 3 times; still failing → warning, objects stay committed locally and go with the next push. Push is skipped when nothing was written. Total time budget: `remote_timeout` (default 60 s) — beyond it the push is abandoned with a warning.
- Locking: `flock` on `<stateDir>/remote/git.lock` around begin/end (Paratest workers do not touch the remote; only the wrapper does).
- Policy `remote_push`: `objects` (default for developers: only `objects/**`), `all` (CI baseline job: objects + `graph/**`), `off` (pull only).
- Auth: whatever git already has (SSH agent, credential helper, deploy key in CI). No token handling in the package.
- Garbage collection: `phpunit-replay prune --remote [--keep-months=3]` deletes shards older than N months except objects referenced by any `graph/**` file, then (with `--squash`) re-creates the branch as an orphan commit and force-pushes; clients detect the rewritten history on the next `begin()` and re-clone. Intended to run from a monthly CI job (`.github/workflows/tia-gc.yml`).

### Pipeline changes

- Startup without local graph: `ObjectStore::graph(branch) ?? graph(defaultBranch)` (the project key is fixed on the `ObjectStore` instance, not passed to `graph()`/`graphOf()` — see above) → fingerprint reconcile (structural must match; environmental drift clears results) → sha must be an ancestor of HEAD, else use it only as a source of `objects` by key (edges still useful) — record fresh but replay-remote by `k` still applies.
- Replay: for every test file in the run list that is `affected` (not unknown/rerun/quarantined/not-cacheable): compute `k_now`; `ObjectStore::object(k_now)` hit → mark file **replayed-remote**, drop from the run list, merge its results with `key = k_now` (Summary: `M replayed (R from remote)`).
- After the run: `put objects/<shard>/<k>.json` for each executed test file (results of that file, with `k`); `put graph/<key>/<branch>.json` when `remote_push === 'all'` and the pass was complete. `CI=true`: objects only unless `--allow-ci-baseline`. The per-object local publish marker is confirmed later, not here — see "publish markers vs. the read-through cache" above.
- Commands: `push [--graph]` (force a push of the current graph and all objects derivable from it), `pull` (fetch graph for the current branch/default and store locally as baseline), `prune --remote`.
- `--no-remote` disables all of the above for one run; `remote => null` disables permanently.
- Deviation: `verify` and `results-only` (a partial CLI selection: `--filter`/`--group`/`--testsuite`/an explicit path) never publish — they persist the local graph the same way a full pass does, but never reach the `putObject`/`putGraph` step above. Only `record` and a full/replay `run` (`RunPipeline::pushAfterRun()`) publish; `verify`'s whole point is comparing against what is already cached, and a partial selection has nothing complete enough to be worth sharing.

### Coverage format abstraction (`src/Coverage/`) — reads/writes `--coverage-php` across four php-code-coverage majors

`phpunit/php-code-coverage` changed its `--coverage-php` on-disk shape and its coverage-data shape between the majors this package supports (11-13 vs 14, and — silently — within 14 itself, at 14.3). Nothing here trusts a version constraint for any of that: capability is read off the installed classes/constants, and a file's own format is read off its first line.

```php
final class Coverage\CoverageFormat
{
    public const SNAPSHOT_FORMAT = 2;                                      // this package's own <k>.cov shape (Snapshot); part of id()

    public static function archive(): CoverageArchive;                    // SerializedArchive when cc has Serialization\Serializer+Unserializer (14+), else LegacyArchive (11-13)
    public static function newSerializer(): ?object;                      // Serialization\Serializer instance, or null on cc 11-13 (class doesn't exist)
    public static function newUnserializer(): ?object;                    // Serialization\Unserializer instance, or null on cc 11-13
    public static function serializationFormat(): ?int;                   // installed SERIALIZATION_FORMAT constant; null pre-14, and on 14.0/14.1 (no such constant)
    public static function declaredFormat(string $path): ?int;            // the format number a --coverage-php file's first line declares; null when it declares none
    public static function isForeign(string $path): bool;                 // markerOf($path) !== ownMarker() — CoverageMerger::merge() degrades (copy unmerged) rather than throw
    public static function markerOf(string $path): ?string;               // normalised 'fmt<n>' | 'ver<version>' | null, off the file's first line
    public static function ownMarker(): ?string;                         // the marker the installed cc writes AND reads back
    public static function id(): string;                                 // Fingerprint's environmental.coverage token: 'cc<major>/<fmt<n>|ser|php>/snap<SNAPSHOT_FORMAT>'
    public static function usesTestIndexes(): bool;                      // true from cc 14.3 on: per-line hits are interned indexes, not inline id strings (LineHits)
    public static function newProcessedData(bool $collectsHitCounts): ProcessedCodeCoverageData;             // reflection: ctor is 0-arg pre-14.3, 1-arg ($collectsHitCounts) from 14.3
    public static function installLineCoverage(ProcessedCodeCoverageData $data, array $lineCoverage, ?array $testIds): void;  // setLineCoverage() always, setTestIds() only when $testIds !== null (14.3+)
    public static function collectsHitCounts(ProcessedCodeCoverageData $data): bool;                          // false pre-14.3; false (not a fatal Error) for data restored from another major
    /** @param array<mixed> $tests @return array<non-empty-string, TestType> */
    public static function testsFrom(array $tests): array;              // validates raw CodeCoverage::getTests() entries (shape/keys differ per major) rather than trusting them
    public static function call(object $object, string $method, mixed ...$arguments): mixed;                   // method_exists() dispatch behind a non-literal $method — see the class docblock for why (PHPStan cannot introduce a missing class/method via stubs, and a literal guard gets one branch flagged dead against whichever version is installed)
}

interface Coverage\CoverageArchive
{
    public function read(string $path): ?CodeCoverage;                   // absolute file paths always, regardless of on-disk representation; null when unreadable/foreign
    public function write(string $path, CodeCoverage $coverage): bool;  // atomic; may mutate $coverage (clearCache() / excludeUncoveredFiles())
}
final class Coverage\LegacyArchive implements CoverageArchive     // cc 11-13's Report\PHP shape: `<?php return unserialize(<<<'END_OF_COVERAGE_SERIALIZATION' …);` — read() includes it output-buffered so a foreign/truncated file's echoed bytes never leak into the wrapper's stdout
final class Coverage\SerializedArchive implements CoverageArchive // cc 14+'s Serialization\Serializer/Unserializer: unserializes to array{buildInformation, basePath, codeCoverage: ProcessedCodeCoverageData, testResults}, not a CodeCoverage object — read() rebuilds one around it, re-expanding basePath-relative keys back to absolute (PathReducer, upstream issue #925) and carrying the original file's driver name/version forward via NullCoverageDriver (cc 14's own Serialization\Merger refuses to merge files whose driverInformation disagrees)

final class Coverage\LineHits    // shape-independent reads/writes of ProcessedCodeCoverageData::lineCoverage()'s per-line value (list<test id> up to cc 14.2, array<test index, hit count> from 14.3 — same method signature, different value shape, detected from the DATA not the version)
{
    /** @return array<int, non-empty-string>|null */
    public static function testIds(ProcessedCodeCoverageData $data): ?array;                        // the interned index => id table (cc 14.3's testIds()), or null when the installed cc has none
    /** @return array<non-empty-string, positive-int> */
    public static function idsOnLine(?array $hit, ?array $testIds): array;                           // test id => hit count for one line's raw value; [] for null (not executable) and for no hits
    public static function hitByAny(?array $hit, ?array $testIds, array $wanted): bool;               // short-circuiting "did any of $wanted hit this line" (PiggybackCoverageDriver's hot path)
    /** @return array{0: array<string, array<int, mixed>>, 1: array<int, non-empty-string>|null} */
    public static function toLineCoverage(array $neutral, bool $intern): array;                       // inverse: shape-independent `file => line => (id => hits)` → the installed representation (+ index table when $intern)
}

final class Coverage\Snapshot    // this package's own <stateDir>/coverage/<k>.cov: version-neutral JSON {format, lines, tests} — no longer a serialized php-code-coverage object (SNAPSHOT_FORMAT 1 was; a snapshot recorded under one major now merges cleanly under the next)
{
    public static function restrict(array $lineCoverage, ?array $testIds, array $tests, array $wantedIds): ?self;  // one test file's slice of a run's coverage; null when $wantedIds hit nothing (autoload-only file)
    public function encode(): ?string;
    public static function decode(string $content): ?self;              // null for anything not SNAPSHOT_FORMAT (a v1 file from an older release included) — caller warns and skips, never salvages
    public function toCoverage(bool $collectsHitCounts): CodeCoverage;  // rebuilt in the INSTALLED cc's own representation, ready for CodeCoverage::merge(); driver is NullCoverageDriver (a snapshot never collects anything itself)
}
```

Every public method above either returns null/false/a safe default or is called from a caller that catches `Throwable` — none of this throws out of a public method for an environmental problem, per the convention at the top of this file.

### CoverageMerger (SPEC §3.2, port of Pest CoverageMerger)

```php
final class Report\CoverageMerger
{
    /** @param list<string> $snapshotPaths absolute paths to <stateDir>/coverage/<k>.cov files */
    public static function merge(string $runCoveragePhp, array $snapshotPaths, string $output): bool;
    public static function writeEmptyRun(string $path, ConfigurationReader $reader): bool;      // empty CodeCoverage scoped to <source>, for a pass where the run list was empty
}
```

The wrapper accepts `--coverage-php=FILE`; when replayed files have a stored per-file coverage snapshot (`<stateDir>/coverage/<k>.cov`, written at record time by `Record\CoverageSnapshots` when `--coverage-php` was requested), `merge()` folds them into the run's own `CodeCoverage` and writes the final file through `CoverageFormat::archive()`. Only `.php` coverage; HTML/Clover are produced by the user from it. Two failure modes degrade instead of breaking the run: a run coverage file `CoverageFormat::isForeign()` (a format the installed php-code-coverage cannot read) is copied through to `$output` unmerged, with a warning; a snapshot this installation cannot decode is skipped and counted, with one warning for the total. Git information is deliberately never written into the merged file: it spans several runs at potentially several commits, so stamping it with the working tree's current commit would assert something untrue. Documented limitation: coverage snapshots are only available for test files recorded with `--coverage-php`.

### GitHub Actions (examples in `.github/workflows/`)

- `tia-baseline.yml` (push to main): checkout `fetch-depth: 0`, PHP + pcov, `composer install`, `phpunit-replay record --fresh -p`, `phpunit-replay push --graph` (remote configured via `PHPUNIT_REPLAY_REMOTE` secret/var; git backend uses a deploy key).
- `ci.yml` (pull_request): job `fast` → `phpunit-replay run` (pulls main baseline + objects, pushes objects); job `full` → `phpunit-replay verify` (gate). Both `fetch-depth: 0`.
- `tia-gc.yml` (monthly cron): `phpunit-replay prune --remote --keep-months=3 --squash`.

### README additions (phase 3)

Section "Sharing the cache with your team" with a comparison table and setup steps for: local only (default), shared folder (`file://`), HTTP (S3/MinIO presigned, nginx WebDAV), **dedicated git repository** (recommended when no object storage exists: create empty repo, give CI a deploy key, `remote => 'git@github.com:org/project-replay-cache.git'`, `remote_push`, GC job), and CI artifacts (`baseline-path` + actions/cache). Each with prerequisites, what gets shared, failure behaviour (never breaks the run), and size expectations.

### Nearest baseline (`baseline_branches`) — phase 3

Config: `'baseline_branches' => ['develop', 'main']` (list, ordered by preference; `default_branch` remains
as an alias for the first entry; when both are set `baseline_branches` wins). Env `PHPUNIT_REPLAY_BASELINE_BRANCHES`
(comma-separated).

```php
final class Change\BaselineResolver
{
    public function __construct(Git $git, Graph $graph, ?ObjectStore $remote, Config $config);
    /** @return array{branch: string, sha: string, source: 'own'|'local'|'remote', distance: int}|null */
    public function resolve(string $currentBranch, string $head): ?array;
}
```
Algorithm: candidates = [current branch (own baseline)] + `baseline_branches`; for each with a known sha
(local graph first, then `graph/<key>/<branch>.json` from the remote), keep those where
`git merge-base --is-ancestor <sha> <head>`; `distance` = `git diff --name-only <sha>..<head> | count`;
pick the smallest distance (ties → order of preference); own baseline wins when its distance is ≤ the best
candidate's. When the winner is not the current branch, `Graph::setNearestBranch()` records it and
`Graph::results()`/`mergedResults()` layers three tiers, own on top: **own → nearest → default**, each
complete layer authoritative for the test files it covers (a result the layer below holds for one of
those files is dropped, never merged, so a renamed test does not keep reporting the old name too). The
nearest branch is a narrower layer inserted ABOVE the default branch, not a replacement for it: a
branch's own baseline and its nearest one are both deltas (partial re-recordings of whatever changed
since they diverged), so the default branch remains the only layer with results for everything neither
of them ever touched. `status`/`--explain` print `baseline develop@abc1234 (nearest, 3 files away)`.
Detached HEAD: candidates only. No candidate is an ancestor → fresh record (as today).

Deviation: `status` resolves the nearest baseline locally only — `StatusCommand::execute()` builds its
`BaselineResolver` with `$remote = null`, so `shaFor()` never reaches the `graph/<key>/<branch>.json`
remote fallback and only ever reports `source: 'own'|'local'`, never `'remote'`. `run --explain`, by
contrast, resolves through the same real, remote-aware `BaselineResolver` a normal `run` does
(`RunPipeline::resolveBaseline()`, `$this->objects`). `status` is a read-only, offline-safe report of
what the local graph already knows, never a network call.

### No edges to files git ignores (SPEC §7.3, §4.5, §10)

Root cause (measured on a real 9056-test Laravel project, two identical `record --parallel=8`
passes from an empty graph): `Laravel\BladeTracker::arm()` linked `$view->getPath()`
unconditionally. For an ordinary template that path is the source file — correct — but for
`Blade::render($string)` and inline/anonymous components, Laravel writes the raw string into
`config('view.compiled')` itself (`Illuminate\View\Component::createBladeViewFromString()`,
content-hashed, under the `__components::` namespace) and `$view->getPath()` for that view is
that disposable path — additionally carrying a per-paratest-worker token under Laravel Parallel
Testing (`view.compiled` rewritten per worker), so which worker ran a test changed its recorded
dependency set. 189 of 726 tests' dependency sets differed between the two passes; 421 distinct
files moved; every one of the 281 `bootstrap/cache/views/test_<N>/*.blade.php` paths involved was
confirmed `git check-ignore`d and zero were tracked.

Two layers, both required (the second is a net for any writer, not only `BladeTracker`):

```php
final class Laravel\BladeTracker
{
    public static function arm(object $app, Recorder $recorder, string $projectRoot): void;
    // composer('*', ...) still links an ordinary template; refuses a path under
    // config('view.compiled') (read inside the closure, every call — never captured at
    // arm() time) or, when that config cannot be read, under
    // Record\SourceScope::isNestedNoisePath($projectRoot, $path).
}
final class Record\SourceScope
{
    // ...existing API...
    /** bootstrap/cache | storage/framework | storage/logs, resolved under $projectRoot */
    public static function isNestedNoisePath(string $projectRoot, string $absoluteFile): bool;
}
final class Change\Git
{
    // ...existing API...
    /** Batched `check-ignore --no-index -z --stdin`; null only when git itself failed. */
    public function ignored(array $paths): ?array;
}
```

`Cache\GraphUpdater::apply()` collects every candidate dependency this call could write — every
source across `$partial->edges`, plus (when `static_declaration_edges` is on) every file in
`Analysis\StaticEdges::index()` — into ONE `Change\Git::ignored()` call, then drops the ignored
ones from the edges handed to `Graph::unionEdges()` and passes the same set into
`StaticEdges::expand(..., $ignored)`, which skips linking any target in it. `apply()`'s return
gains `excludedEdges: int` (the coverage/explicit-link count only — `StaticEdges::expand()` still
returns a bare `int $added`, unchanged in shape, so no existing `StaticEdgesTest.php` assertion
needed touching). `GraphUpdater`'s constructor gains an optional `?Change\Git $git = null`
(`Console\Runner\RunPipeline` passes its own instance through all five construction sites rather
than paying for a second one).

`Change\ChangedFiles::filterIgnored()` now calls the same `Git::ignored()` instead of shelling out
to `check-ignore` itself — the one thing this fix reuses rather than duplicates.

Fingerprint: `edges_exclude_ignored: true`, unconditional (§4.5) — every graph recorded before this
key existed is missing it, so `structuralDrift()` names it and forces exactly one fresh record.

Visibility: `Report\RecordSummary`/`Summary::recorded()` gain `excludedEdges`, rendered as
`N excluded (gitignored)` only when positive (`RecordSummary::format()`, next to `edges`).
`Console\StatusReport`/`StatusCommand` gain `excludedEdges` too, but computed differently — a LIVE
`Git::ignored($graph->files())` at `status` time, not a stored counter, so it also surfaces stale
pollution left in a graph recorded before this shipped; rendered beside the `edges:` line.

No configuration: an allowlist design (`source_paths`) was considered and rejected — `.gitignore`
is the one exclusion lever, matching `Select\ResiduePatterns`'s own stated principle that an
allowlist omitting a real source root is an unsafe default.

### Once-per-process residue (SPEC §4.3.1, §7.3) — `src/Laravel/OncePerProcessPaths.php`

Root cause (measured on the same 9056-test Laravel project, two identical `record --parallel=8`
passes with `static_declaration_edges` on, each from an empty graph): 22 of 726 test files' edge
sets still moved. Classified with this package's own `Analysis\DeclarationScanner`: all 22 have
function bodies, none is declaration-only — not the first-loader problem the flag already fixes.
`app/Console/Commands/*` (5), `database/seeders/*` (7) and `database/migrations/*` (4) are Laravel
conventions whose body genuinely executes once per worker process (a migration/seeder guarded by
`RefreshDatabase`'s own once-per-worker migrate+seed; a command's registration once per Kernel
boot), so coverage credits whichever test triggered it first in that worker, same shape as a
declaration one level down the call stack. Holder identities, not just counts, were extracted from
the graphs to confirm the mechanism: the set **shrinks rather than swaps** pass to pass (e.g.
`app/Console/Commands/BackfillRecoveryLeads.php` 129 → 128 → 128 holders) — `Graph::unionEdges()`
cannot repair this the way it repairs an ordinary partial re-record, because a test that never once
happened to be the first loader in any of Paratest's worker distributions never gets the edge to
lose in the first place. See [reproducibility.md](reproducibility.md) "Once-per-process residue"
for the full measurement; `app/Services/*` (2) and a factory/model pair (2) moved too and are
**not** covered by this fix (see "What this does not cover" below).

```php
final class Laravel\OncePerProcessPaths
{
    // database/migrations/, database/seeders/, app/Console/Commands/ — exact-case prefix match,
    // no filesystem access, no Illuminate, no application boot.
    public function matches(string $relative): bool;
}
```

Wiring: a new optional `Cache\GraphUpdater` constructor parameter,
`?Cache\OnceProcessClassifier $onceProcessPaths = null` (last positional slot, after the existing
`?Change\Git $git = null`). `Console\Runner\RunPipeline` and `PHPUnit\ReplayState::bootInProcess()`
each build one alongside their `?Analysis\StaticEdges`, both inside the same
`if ($config->staticDeclarationEdges)` block, gated additionally on `Laravel\LaravelDetector::enabled()`
— threaded through every `new GraphUpdater(...)` call site exactly as `$staticEdges` already is (7
sites total: 1 in `ReplayState.php`, 6 in `RunPipeline.php`, one of which never calls `apply()` at
all — `finalizeBaseline()` — and still receives it for constructor-shape consistency).

`Cache\GraphUpdater::apply()` computes a **second**, separate filtered edge map — `$edgesToRecord`
— from `$filteredEdges` (the existing git-ignore-filtered map), stripping any source
`$onceProcessPaths->matches()` accepts, but only when **both** `$staticEdges !== null` **and**
`$onceProcessPaths !== null`: the flag re-check is deliberate and redundant with the constructor
sites above on purpose, so a future caller that builds `$onceProcessPaths` without also building
`$staticEdges` cannot silently make this fire with the flag off, which would refuse an edge with no
`Select\ResiduePatterns` net underneath it — a strictly worse false green than the one this closes.
`$edgesToRecord` (not `$filteredEdges`) is what reaches `Graph::unionEdges()` and the `edges`
counter; `$filteredEdges` — unfiltered for once-process sources — is still what reaches
`Analysis\StaticEdges::expand()`'s hop-source argument (`behaviouralEdges()`), which is *why* a
test that names one of these files in its own source still gets the edge (`expand()`'s `anyShape`
hop) even though the coverage-derived one to the same file was refused. The two edge writers this
package has (`Cache\GraphUpdater::apply()`'s `unionEdges()` call and `Analysis\StaticEdges::expand()`'s
`Graph::link()` call, per the git-ignore fix above) are therefore treated asymmetrically here on
purpose, unlike the git-ignore filter, which refuses both identically.

No fingerprint change: unlike `static_declaration_edges` itself, this does not change what an edge
*means* (a refused edge simply is not one), only which edges get recorded, and that is already a
function of `static_declaration_edges` and of the project being a detected Laravel one — both
already fingerprinted or file-detected — so no new structural key is needed.

**What this does not cover.** `app/Services/*`, a factory and a model also moved in the same
measurement and are deliberately left alone: nothing about their path says "executes once per
process" the way a migration's does, and guessing wrong in the "still gets an edge" direction
would silently reintroduce this same bug. No live Illuminate container is consulted either (e.g.
asking a booted application for its configured migration paths, which would also resolve an
unconventional `Modules/*/Database/Migrations` layout) — considered and rejected, because
`Cache\GraphUpdater::apply()` runs both from the wrapper process (nothing loaded) and in-process
(Laravel already booted, `ReplayState::persistInProcess()`), and an answer that depends on which of
those handled a given recording pass reintroduces process-shape-dependent non-determinism one level
up from the bug this removes. No configuration: extending or narrowing the three convention paths
is a code change to `OncePerProcessPaths`, not a project setting, matching the git-ignore fix's own
"no allowlist" precedent above.

**Also considered and rejected**: pointing `Hermeticity\Policy`'s existing `never_cache` at the
holder test files instead of touching edge recording. Same cost in expectation (the same tests
always run), but the disputed edges stay recorded, so content keys keep moving between machines and
the remote cache stays exactly as unshareable as before — it changes which tests run, not what the
graph *is* (reproducibility.md "Considered and rejected: exempting the holders instead").

**Acceptance test.** `tests/Integration/OnceProcessResidueParallelTest.php` records the same
fixture serially and with `-p 4` and asserts the two graphs are byte-identical once resolved to
`test file -> dependency path` pairs (never compares raw file ids — the intern table's numbering
is not the contract). This is the property that actually matters, and it is stronger than two
parallel passes agreeing (`tests/Integration/StaticDeclarationEdgesTest.php`'s own
`..._sequentially_and_in_parallel` test, which pins the declaration/first-loader case): serial and
parallel are the two extremes of first-loader attribution, so only comparing them exercises both
ends. The fixture adds a hand-written `app/Console/Commands/OnceProcessDemo.php` guarded from the
*caller* side (`if (! $class::$triggered) { ...; (new $class())->run(); }`, never inside `run()`
itself — the same place `RefreshDatabaseState::$migrated` sits in Laravel's own `RefreshDatabase`
trait) to eight new `tests/Feature/OnceProcess*Test.php` files, all built at runtime via
`FixtureProject::write()` rather than committed to the shared `laravel-lite` fixture, so no
existing test's hardcoded test/file counts move. The class name is assembled from
backslash-free string fragments (`implode('\\', ['App', 'Console', 'Commands', 'OnceProcessDemo'])`)
specifically so `Analysis\FactsVisitor` cannot parse it as a name reference — a literal `use`
statement would let the static hop supply the same edge regardless of shape and the test would
pass unfixed, proving nothing.

### Abstract-base test classes attribute their edges to the wrong file — `src/PHPUnit/TestMethodFile.php`

**Reproduction (a real Laravel suite, 4820 tests).** Two `final` test classes extending a shared
abstract base, adding no test methods of their own — the base declares them and is never itself
run by PHPUnit. `baselines['<branch>']['results']` correctly held all of both classes' results,
keyed by test id. `edges` did not: the abstract base (which PHPUnit never executes) had 841
recorded dependencies, and the two concrete classes that actually ran had none. Consequence: the
two concrete files were permanently "unknown test files" (`Select\RunListBuilder::build()`) and
re-ran on every pass — the two most expensive files in the suite, measured at 466s under pcov.

**Root cause.** `StartRecordingOnPreparationStarted`, `CollectResultOnPreparationStarted`, the
class-level branch of `RecordNotCacheableOnPreparationStarted`, and
`Laravel\Subscribers\ArmLaravelTrackersOnPrepared` all called `PHPUnit\Event\Code\TestMethod::file()`
to decide which file a test's edges/result/not-cacheable-marker/database-table-widening counts
against. `file()` resolves through `Event\Code\TestMethodBuilder::fromTestCase()` ->
`Util\Reflection::sourceLocationFor($testCase::class, $methodName)` ->
`(new ReflectionMethod($className, $methodName))->getFileName()` — native PHP reflection, which
for an inherited method returns the file of the class that DECLARES the method body, never the
class actually running it. `TestMethod::className()`, by contrast, is always `$testCase::class` —
the concrete, running class, regardless of where its methods are declared.

**Two variants, not one.** The abstract base above happened to be named with the configured test
suffix, which makes PHPUnit's own directory-based discovery try to load it as a test file too and
convert `Runner\TestSuiteLoader`'s `ClassIsAbstractException` into a `testRunnerTriggeredPhpunitWarning`
(`Framework\TestSuite::addTestFile()`) — a warning a project with `failOnPhpunitWarning` on (PHPUnit's
own XML loader default when the attribute is absent, unlike `failOnWarning`) sees as a build failure.
Renaming the base away from the test suffix silences that warning, but is a *different* fix for a
*different* problem (this package's own business only in as much as `Select\TestPaths::isTestFile()`
then stops matching the base at all) — the file-attribution defect above survives the rename
undiminished, since it never depended on the base's filename matching any convention.
`tests/Fixtures/Projects/abstract-base/` reproduces both: `SweepBaseTest` (abstract, matches the
suffix) and `SweepScenario` (abstract, does not), each with two `final` concrete subclasses.

**The fix.** `PHPUnit\TestMethodFile::of(TestMethod $test): string` reflects `$test->className()`
and returns ITS file, falling back to `$test->file()` only when that class cannot be reflected (in
ordinary operation this cannot happen: a `TestMethod` is only ever built from an already-instantiated
test object, so its class necessarily exists). All four call sites above now go through it — the
fifth and sixth `$test->file()`-adjacent computations in the codebase, `PHPUnit\Replayable::
__replayDecision()`'s `(new ReflectionClass(static::class))->getFileName()` and
`RecordNotCacheableOnPreparationStarted`'s own method-level branch (keyed by `Class::method`, never a
file), were already correct or already file-independent and are unchanged. Neither a
`#[DataProvider]` dataset nor a `#[Depends]`/`#[DependsExternal]` relationship changes which class is
running (`TestMethodBuilder::dataFor()` only attaches test data to the same instance; a dependency
resolves by class+method id, never by file — `ReplayState::isDependsProvider()`), so both fall out
unaffected — checked empirically, not just read: `tests/Fixtures/Projects/abstract-base/tests/
SweepBaseTest.php` has a `#[DataProvider]`-driven inherited method, and its concrete subclass's
recorded edges are identical to the plain-method ones.

**Ordering curiosity, not a bug this needed to fix.** `ReplayState::decide()` memoises per test id;
`StartRecordingOnPreparationStarted` (fired from `Test\PreparationStarted`, which PHPUnit's
`TestCase::runBare()` emits before `setUp()`/`invokeTestMethod()`) always calls it before
`Replayable::__replayDecision()` ever runs, so the trait's own (already-correct) file computation
never actually reached `decide()` — the subscriber's file argument was the only one that mattered.
After this fix both computations agree, so which one wins the memoisation race is no longer
observable.

**Second guard considered and rejected: refusing to key `edges` by an abstract class's file at
record time.** Checked rather than assumed: with the fix above, the abstract base cannot become a
key in `edges` at all, on any path. Every one of the four call sites above now resolves through
`TestMethod::className()`, and PHPUnit never instantiates a test whose class is abstract
(`Runner\TestSuiteLoader::load()` throws `ClassIsAbstractException` before constructing one), so
`className()` can never name one. No other code path in the package creates a NEW key in `edges` —
`Cache\GraphUpdater::apply()`'s `unionEdges()`/`markKnownTestFiles()` are driven entirely by
`RunPartial::$edges`/`$results`, themselves populated only by the four fixed call sites;
`Analysis\StaticEdges::expand()` only extends an EXISTING test file's dependency list, never
introduces a new key; `Select\RunListBuilder`/`TestPaths::isTestFile()` only read the graph, never
write it. And the structural-drift-forced fresh record `edges_by_running_class` below guarantees at
least once starts from a genuinely empty `Graph` (`ReplayState::freshGraph()`,
`Console\Runner\RunPipeline`'s `record()`/`verify()`), so even a stale pre-fix key does not linger.
Confirmed empirically too: `Graph::knowsTest()` is `false` for both abstract bases in
`tests/Integration/AbstractBaseTestClassEdgesTest.php`'s recorded graph, in both variants. A second,
independent guard would therefore be dead code.

**The abstract base as a dependency, not merely an absent key.** The base is source the concrete
tests depend on — its method bodies are the lines that execute — so it must appear INSIDE the
concrete files' own dependency lists, not merely stop being a key of its own: editing it must still
select both concrete tests (`Select\Rules\PhpEdgeRule`). This falls out of the fix with no extra
code: `Record\PcovDriver::stop()`/`Record\XdebugDriver` report coverage for whatever `SourceScope`
includes, which covers `tests/` by default (`SourceScope::fromProjectRoot()`'s `topLevelProjectDirs()`
half, independent of `<source><include>`), so the abstract base's executed method-body lines are
already in the coverage data handed to `Recorder::endTest()` — attributed to whichever file
`beginTest()` opened, now correctly the concrete one. Verified, not assumed:
`tests/Integration/AbstractBaseTestClassEdgesTest.php` asserts each concrete file's edges are
non-empty AND contain the abstract base's own path, and separately drives `Select\Selector` to
confirm changing the base's file selects both concrete subclasses.

**Fingerprint: `edges_by_running_class: true`, unconditional, alongside `edges_exclude_ignored`**
(`Cache\Fingerprint`'s own class docblock has the full argument). A graph recorded before this fix
has an inherited test method's edges under the wrong file entirely — not merely stale, but
attributed to a file that is not even a test file this package selects — so, same as
`edges_exclude_ignored`, a bare `SCHEMA_VERSION` bump would force the same one-time fresh record but
could never be *named* in a drift report (`structuralDrift()` always skips `'schema'`). `status`
names it `edges_by_running_class (drift)`.

**Acceptance test.** `tests/Fixtures/Projects/abstract-base/` (`FixtureProject::abstractBase()`):
two abstract bases (`SweepBaseTest`/`SweepScenario`, with/without the test suffix), five `final`
concrete subclasses between them (four plain, one `#[DataProvider]`-driven), two tiny source
classes (`Alpha`/`Beta`) each family actually calls. `tests/Integration/
AbstractBaseTestClassEdgesTest.php` records the fixture and asserts, per variant: every concrete
file is known to the graph and has a non-empty, base-inclusive edge list; the abstract base is
never a key in `edges`; every test id (plain and dataset-suffixed) has a result; and
`Select\Selector::affected([abstractBaseFile])` selects every concrete subclass. `tests/Unit/PHPUnit/
TestMethodFileTest.php` covers `TestMethodFile::of()` directly: the ordinary case (declaring file ==
running file, a no-op), the inherited case (resolves to the running class regardless of what
`$test->file()` says), and the unreflectable-class fallback.
