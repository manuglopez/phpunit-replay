# Internals — component contracts

Working contract between components. SPEC.md is the source of truth for behaviour; this file
pins down class names, signatures and data shapes so components built in parallel fit together.
When SPEC.md and this file disagree on a signature, this file wins (it reflects the real PHPUnit
API); when they disagree on behaviour, SPEC.md wins and the deviation goes to DECISIONS.md.

Conventions: `declare(strict_types=1)` everywhere, `final` by default, `readonly` where the
object is immutable, PSR-12 via `vendor/bin/pint`, PHPStan level max. Target PHP 8.2: no typed class constants, no `#[\Override]`, readonly classes are fine. All paths handed between
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
    //   environmental: php (MAJOR.MINOR), driver ('pcov'|'xdebug'|'none'), os (PHP_OS_FAMILY)
    public static function structuralMatches(array $a, array $b): bool;
    /** @return list<string> keys that differ */
    public static function structuralDrift(array $stored, array $current): array;
    public static function environmentalDrift(array $stored, array $current): array;
    /** Canonical JSON of the structural bucket (ksort, JSON_UNESCAPED_SLASHES) — input to the content key. */
    public static function canonicalStructural(array $fingerprint): string;
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

### Run partials (written by the extension, read by the wrapper) — `<stateDir>/runs/<run-id>/`

```php
final class Record\RunWriter
{
    public function __construct(string $runDir, string $projectRoot);
    public function markTruncated(): void;
    /** writes edges.json, results.json, tables.json, meta.json atomically */
    public function flush(Recorder $recorder, ResultCollector $collector, array $meta): bool;
}
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
final class PHPUnit\ConfigurationReader       // thin, version-tolerant reads of PHPUnit Configuration (11.5 vs 12)
{
    public function __construct(\PHPUnit\TextUI\Configuration\Configuration $configuration);
    public function hasPartialSelection(): bool;   // hasFilter || hasExcludeFilter || hasGroups || hasExcludeGroups || includeTestSuite !== '' || cliArguments with a path
    public function shouldRerun(int $status): bool; // SPEC §6.2 shouldRerun
    /** @return list<string> */ public function sourceIncludeDirectories(): array;
    public function testSuffixes(): array;
    public function configurationFile(): ?string;
}
final class PHPUnit\ReplayExtension implements \PHPUnit\Runner\Extension\Extension  // SPEC §6.1
final class PHPUnit\ReplayState                                                 // static singleton; phase 1: boot(Config, Configuration) → mode; owns Recorder/ResultCollector/RunWriter
```

Environment variables (wrapper → extension): `PHPUNIT_REPLAY_MODE`, `PHPUNIT_REPLAY_STATE_DIR`, `PHPUNIT_REPLAY_RUN_ID`, `PHPUNIT_REPLAY_ROOT`, `PHPUNIT_REPLAY_DEBUG`. `PHPUNIT_REPLAY=0` disables everything.

## Config

```php
final readonly class Config                  // SPEC §9 keys
{
    public ?string $stateDir; public ?string $remote; public ?string $remoteToken; public ?string $defaultBranch;
    /** @var array<string, string|list<string>> */ public array $watch; /** @var list<string> */ public array $neverCache;
    public int $quarantineReleaseAfter; public string $laravel; public bool $junitMerge;
    public static function defaults(): self;
    public static function load(string $projectRoot): self;           // phpunit-replay.php if present (returns array), else defaults; then env overrides
    public static function fromArray(array $values): self;
    public static function fromExtensionParameters(\PHPUnit\Runner\Extension\ParameterCollection $parameters): self;
    public function mergeEnv(array $server): self;
}
```

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
     * - when $recordsEdges: replaceEdges for executed test files, markKnownTestFiles(executed files), replaceTestTables
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
