# Proposal: tell a project which `watch` patterns it needs (`watch:suggest`)

**Status: accepted; partly implemented.** The owner's decisions are recorded in "Decisions (2026-10-01)" below. Decisions 1 and 6 are implemented in 0.13.0 as package rules (the schema dump, new sibling subdirectories, every migration path); the `watch:suggest` command itself does not exist in `src/` yet. Line references are
to `main` at `4f4f30e` (v0.12.1). The evidence comes from two real Laravel applications, both
anonymous here:

- **The first case:** a Laravel 13 application with about 10,957 tests in 853 test files and
  2,194 source files. Its findings come from a recorded graph and from reading the code, and
  the `watch` block it adopted was validated on v0.12.1.
- **The second case:** a multi-tenant Laravel application (custom tenancy, MariaDB with a
  schema dump, a Filament admin) with about 490 test files. Its findings come from static
  analysis plus a graph of 487 test files recorded with an older version, so its graph counts
  are **indicative only**.

Test and directory names taken from either project have been replaced with neutral ones that
keep their shape. Anything this document states without a line reference or a measurement is
marked **unverified**.

The question this answers was put by the package's owner: *if someone uses this, how will they
know what to put in `watch`?* Today they cannot. On the first case it took a long manual
investigation of the code and the recorded graph to find out, and a normal team will not do
that. This proposal turns that investigation into a command.

## The problem

### What `watch` is for

phpunit-replay selects tests through edges. Coverage records which source files each test
executed, and a change to a file selects the tests with an edge to it. Everything coverage
cannot see falls to a small set of rules, and after them to the watch patterns
(`Select\Selector.php:37-44`, rule order Migration, PhpEdge, TestFile, Sibling, Blade, Watch).

A watch pattern is a sentence: *"these tests depend on these files, whatever coverage says."*
It maps a glob to test directories or test files (`docs/configuration.md` "Selection";
`Select\WatchPatterns.php:197-224` matches a target as a directory prefix or as an exact file).
It exists for what a test depends on without executing it: a file it reads, a directory it
lists, a class a framework loads on its behalf before the test starts.

Two properties of watch shape everything in this proposal.

**Watch patterns only add.** A configured pattern applies to every changed file, whatever an
earlier rule did with it, and a configured key, a fallback and an unattributable file matching
the same change have their target lists joined (`Select\Rules\WatchRule.php:30-33`, `:50-54`;
`Select\WatchPatterns.php:101-112`). A pattern can never narrow what a rule, a default or a
fallback already selects. That makes a wrong suggestion cheap: a target that is too wide costs
extra tests, and a target that is too narrow closes less of the gap it was meant for. Neither
can make an existing selection smaller.

**Watch has no feedback.** A missing pattern produces no error and no warning. A change to a
file nothing attributes selects nothing, and the next run serves results recorded before the
change. The only way to notice is to know, file by file, what coverage cannot see. That is the
investigation a team will not do.

### What 0.12 already handles

| input | what selects tests for it | where |
|---|---|---|
| a `.php` file a test executed | `PhpEdgeRule`: every test file with an edge to it | `Select\Rules\PhpEdgeRule.php:24-34` |
| a `.php` migration under `database/migrations/` | `MigrationRule`: the tests whose recorded tables intersect its tables; a migration for tables no test records selects every test that records any table; one with no readable table selects every test | `Laravel\Rules\MigrationRule.php:48-58`, `:63-85`, `:92-95` |
| a `.blade.php` template under `resources/views/` | `BladeRule`: walks static references up to templates tests render, then those tests. Additive | `Laravel\Rules\BladeRule.php:48-62`; `Laravel\BladeReferences.php:145-148` |
| a **new** `.php` file in `app/Providers`, `app/Listeners`, `app/Events`, `app/Observers`, `app/Policies`, `app/Console/Commands`, `database/factories` or `database/seeders` | `SiblingRule`: the tests with an edge to another file **in the same directory** | `Laravel\Rules\SiblingRule.php:21-30`, `:54-66` |
| a template or migration-directory file no rule claimed | the Laravel fallbacks `resources/views/**` and `database/migrations/**`: every test | `Select\WatchDefaults\Laravel.php:19`, `:72-75` |
| `config/**`, `routes/**`, `lang/**`, `resources/lang/**`, non-PHP files under `app/`, `bootstrap/*.php` | Laravel defaults: every test | `Select\WatchDefaults\Laravel.php:47-55` |
| `.env*`, `phpunit.xml*`, `docker-compose*.y*ml`, and `<testsuite dir>/**/Fixtures/**`, `<testsuite dir>/**/__snapshots__/**` | generic defaults: every test (fixtures and snapshots: their own testsuite directory) | `Select\WatchDefaults\Php.php:27-33` |
| a `.php` file `<source><exclude>` keeps out of coverage | every test, whatever else names it | `Select\ResiduePatterns.php:172-179`; `Select\RunListBuilder.php:68-70` |
| with `static_declaration_edges`: any `.php` file with no edge; and no coverage edge at all for migrations, seeders and commands | every test (the residue fallback) | `Select\ResiduePatterns.php:153-156`; `Cache\GraphUpdater.php:143`; `Laravel\OncePerProcessPaths.php:78-82` |

The configured `watch` block is merged on top of all of this in each of the three places that
build a selection (`Console\Runner\RunPipeline.php:338-341`,
`Console\Commands\ExplainCommand.php:70-75`, `PHPUnit\ReplayState.php:855-858`).

### What is left, with evidence

Five shapes remain. The first case showed all five, and the second case added several variants.

**1. Directories a framework discovers by itself, outside the sibling list.**

- *First case:* `Nova::resourcesIn(app_path('Nova'))` registers every class in `app/Nova`.
  It held 265 files, and **100 of them had no edge**. A new resource **selected 0 tests**,
  because nothing claims it. `app/Nova` is not a sibling directory (`SiblingRule.php:21-30`).
  A new file has no edge (`PhpEdgeRule.php:25`). The Laravel default for `app/` excludes
  `.php` files (`WatchDefaults\Laravel.php:53`). The residue fallback runs only with
  `static_declaration_edges` on (`ResiduePatterns.php:155`).
- *First case:* a new **subdirectory** of `app/Console/Commands`, `app/Listeners` or
  `app/Policies` escapes `SiblingRule`. A file there is a candidate, because the prefix matches
  (`SiblingRule.php:83-95`). But the rule looks only for tests with an edge into the **same**
  directory (`dirname($dependency) === $dir`, `:54-59`). A new subdirectory has no tested
  files yet, so nothing matches, the file is left unconsumed (`:76-78`), and the watch rule
  has no pattern for it. Laravel discovers listeners by scanning the `Listeners` directory,
  and `withEvents(discover: […])` accepts other paths and wildcards (Laravel 13 documentation,
  "Event Discovery"). Policies are guessed from model names in `app/Policies` or
  `app/Models/Policies` (Laravel 13 documentation, "Policy Discovery").
- *Second case:* a Filament panel provider calls `discoverResources`, `discoverPages` and
  `discoverWidgets`. `app/Filament/Resources` held 488 files, 79 of them `*Resource.php`.
  `app/Filament` is not a sibling directory, so a new resource selects nothing.
- Livewire class components (`app/Livewire`, or a path given to `Livewire::addNamespace()`),
  Livewire 4 single-file and multi-file components (`component_locations`, by default
  `resources/views/components` and `resources/views/livewire`), and project-specific glob
  loaders are further candidates (Livewire 4.x documentation, "Organizing components").
  **Unverified** on a real project.

**2. Tests that scan the source tree.** Coverage records the scanning code, not the files it
scans. A test that lists a directory or reads every file in it depends on those files and has
no edge to any of them.

- *First case:* one test scans every `.php` file under `app`, `config`, `routes`, `database`
  and `bootstrap` for a file header. Another scans `app_path()` for locale-dependent float
  formatting. A third greps `app/`. Others list `app/Nova/*.php`, `app/Jobs/Reports/*.php`,
  the migrations and `resources/views`.
- *Second case:* five architecture and isolation tests walk `app/Models` with a
  `RecursiveDirectoryIterator` and reflection. Nine tests read every `app/**/*.php` file for
  forbidden patterns (storage usage, the public disk, `exists` rules); that is about 1.8% of
  test files. One test scans `tests/` itself for fake-disk usage, and another scans
  `tests/Fixtures/<name>`.
- *Second case, a variant the first did not have:* **the scanner lives in application code.**
  An inline permission-check scanner is an app class running a `Finder` over `app_path()`, and
  five authorization tests call it. A detector that reads only `tests/` misses it.
- *Second case, another variant:* **a source-reading tool run from a test.** An API
  documentation test runs Scramble, which analyses controllers and form requests statically.
  Coverage sees Scramble executing, not the files it analysed.

**3. Once-per-process code that coverage under-attributes.** PHP runs a file's top level once
per process. Laravel's `RefreshDatabase` guards its migrate-and-seed step with a process-wide
flag (`RefreshDatabaseState::$migrated`, quoted in `Laravel\OncePerProcessPaths.php:13-15`).
Coverage credits such work to whichever test ran first in each worker
(`docs/reproducibility.md` "Once-per-process residue").

- *First case:* the base `TestCase` seeds a reference-data seeder through `$seeder` with
  `RefreshDatabase`, so about 851 of the 853 test files depend on it. **Coverage credited it
  to 37 test files (4.3%).** Console commands showed 1 edge against the 9 tests that mention
  them. A watch pattern `database/seeders/** => tests` closes it, because since 0.12 a
  configured pattern fires on files that have edges too (`WatchRule.php:15-21`). Before 0.12,
  `PhpEdgeRule` consumed the seeder for its 37 holders and the watch pattern never fired
  (CHANGELOG 0.12.0, "a watch pattern never fired for a file some test has an edge to").
- *Second case:* the same shape with a **non-PHP input**. The schema dump
  `database/schema/<connection>-schema.sql` is loaded by the migrator when the first test of a
  worker refreshes the database (Laravel 13 documentation, "Squashing Migrations": the schema
  file runs first, then the remaining migrations). It is not `.php` and not under
  `database/migrations/`, so no rule claims it, no fallback matches it
  (`WatchDefaults\Laravel.php:19`), no default does (`:47-55`), and the residue only takes
  `.php` (`ResiduePatterns.php:186-195`). About **89% of test files** depend on it, and a
  change to it selects none of them.
- *Second case, a negative:* its seeders run **per test**, not once per process, so coverage
  sees them in every test that runs them. They are not a gap, and a detector must tell the two
  apart.

**4. Data files read at runtime.** Fixtures, `.json`, `.sql`, `.csv` and collections that no
rule maps.

- *Second case:* a query watchdog in the base `TestCase` reads
  `tests/Fixtures/<baseline>.json` in `setUp()` and `assertPostConditions()`, so it affects
  about 100% of test files. Two isolation-baseline JSON files are read by three or four tests
  each. An API collection under `docs/` is compared against the routes by one test.
- The generic default `<dir>/**/Fixtures/**` exists for this (`WatchDefaults\Php.php:31`), but
  it is built **per testsuite directory**. With the common Laravel testsuites `tests/Unit` and
  `tests/Feature`, the patterns are `tests/Unit/**/Fixtures/**` and
  `tests/Feature/**/Fixtures/**`, and a file in `tests/Fixtures/` matches neither. Glob
  matching is case-sensitive (`Support\Glob.php:45`), so `tests/fixtures/` never matches
  either. Whether the second case's testsuites are exactly those two is inferred from its
  suggested targets: **unverified**.

**5. Files excluded from coverage.** Before 0.12, a provider, middleware, kernel or exception
handler listed in `<source><exclude>` selected **0 tests**. Since 0.12 it runs every test
(`ResiduePatterns.php:159-179`). That is safe but expensive, and no watch pattern can narrow it
(`:161-165`). The precise alternative is to take the file out of `<source><exclude>`, so that
coverage records who executes it.

- *Second case:* a legacy migration subsystem of 110 files is excluded, and its own tests sit
  in an excluded group. Since 0.12, editing one of those files runs every test. The project
  kept the exclusion because removing it lowers its coverage percentage. This is the trade-off
  the command has to state, not hide.

Two more findings from the second case are not gaps, but a team needs to hear them:

- **Expensive but safe, and watch cannot narrow it.** `routes/api.php` does
  `glob(__DIR__.'/api/*.php')` over 38 route files. The default `routes/**` already runs every
  test for any of them (`WatchDefaults\Laravel.php:49`). In that project `config/**`,
  `routes/**`, `lang/**` and `bootstrap/*.php` already send every test, and a configured
  pattern cannot narrow a default.
- **Coarse migration attribution.** All 431 database tests recorded the same ~158 tables, so
  `MigrationRule` selects about 88% of the suite for any migration. One plausible cause is the
  watchdog above: it queries in `assertPostConditions()`, after the table tracker is armed on
  `Test\Prepared` (`MigrationRule.php:76-80`). **Unverified.** This calls for a hint, not a
  watch pattern.

The second case also gave negatives, which are worth as much as the positives because they are
the false-positive tests: seeders called per test, a hard-coded morph map (adding a model edits
a file the graph already tracks), factory-based tenant bootstrap, and `new $class` in a couple
of places with no convention-based loading behind it.

### What the first case needed

This is the block the first case adopted after the investigation, with its names neutralised.
It was validated on v0.12.1. It is the worked example of what the command should produce, and
the precision check in "Testing" below measures the command against it.

```php
'watch' => [
    'app/Nova/**' => ['tests/Feature/Nova', 'tests/Feature/Partner/Nova'],
    'app/**' => ['tests/Unit/SourceHeaderTest.php', 'tests/Unit/Support/LocaleFloatFormattingTest.php', 'tests/Feature/Wallet/SlugIsolationTest.php'],
    'app/Jobs/Reports/**' => ['tests/Feature/Reports/QueueRoutingTest.php'],
    'app/Listeners/Availability/**' => ['tests/Feature/Availability/ArchitectureTest.php'],
    'app/Nova/*.php' => ['tests/Feature/Nova/NovaResourceAuthorizationTest.php'],
    'database/migrations/**' => ['tests/Feature/Partner/MigrationsAreIdempotentTest.php'],
    'resources/views/**' => ['tests/Feature/Livewire/ComponentLibraryRemovalTest.php', 'tests/Unit/CardRenderBudgetTest.php'],
    'database/seeders/**' => ['tests'],
    'app/Console/Commands/**' => ['tests/Feature/Console'],
    'app/Listeners/**' => ['tests/Feature/Listeners'],
    'app/Policies/**' => ['tests/Feature/Policies'],
],
```

Each line is one of the five shapes above. `app/Nova/**` and the last three are discovery
(shape 1). The `app/**`, `app/Jobs/Reports/**`, `app/Listeners/Availability/**`,
`app/Nova/*.php`, `database/migrations/**` and `resources/views/**` lines are scanners
(shape 2). `database/seeders/**` is once-per-process (shape 3).

Two lines teach something about the diff the command must produce:

- `app/Nova/*.php` is **redundant**. Its one target, `tests/Feature/Nova/NovaResourceAuthorizationTest.php`,
  is under `tests/Feature/Nova`, which `app/Nova/**` already maps every file it matches to. A
  human wrote it because it states a separate reason, which is fair. The command should keep
  the reason in a comment and say the line adds no test.
- `database/migrations/**` and `resources/views/**` are spelled like the two Laravel
  fallbacks. They are **not** ineffective. A configured key fires on every changed file,
  including the migrations and templates the Migration and Blade rules claim, so these lines
  add their scanner tests to every migration and template change. Only a key spelled like a
  fallback **in order to narrow it** would be ineffective, and the command can only tell the
  two apart by the evidence behind the targets.

The second case would end up with a block of the same kind. Its suggested keys were
`app/Filament/Resources/*/*Resource.php => tests/Feature/Admin` (note `*` is one path segment
and `**` is any number, `Support\Glob.php:27-35`), `app/Models/** => [the five model-tree tests]`,
`app/** => [the nine content scanners]`, `tests/** => [the two test-tree scanners]`,
`app/Http/** => [the API documentation test]`, `database/schema/** => [tests/Feature, tests/Unit]`,
and `tests/Fixtures/<baseline>.json => [every test directory]`.

## The idea

The investigation asked the same three questions for every gap. What loads or reads this file
without coverage seeing it? Which tests depend on that? And what does a change to it select
today? Each question can be answered mechanically from three sources the package already reads:

- **the tests**, as source: what they scan, read and extend;
- **the application**, as source: what it registers by directory, and which packages it uses;
- **the recorded graph**: which files have edges, who holds them, and how that compares with
  who should.

`watch:suggest` runs a set of **detectors** over those sources. Each detector produces
**findings**, and a finding is a pattern with its targets, its evidence, a confidence, and a
cost. The command then compares the findings with the project's current `watch` block and
prints what is missing, what is redundant, and what cannot work.

It never edits configuration and never runs a test. It gives a team the investigation's
conclusions, each with the evidence a reviewer needs to accept or reject it in a minute.

## The model

### A finding

| field | meaning |
|---|---|
| `id` | stable: `<detector>:<pattern>`, so a team can silence one finding without silencing the detector |
| `kind` | `add` (a pattern to add), `advice` (a change to something other than watch: `<source><exclude>`, `static_declaration_edges`), `hint` (no action available, information only), `diff` (about an existing key: `redundant` or `ineffective`) |
| `pattern` | the glob, as it would appear in `watch` |
| `targets` | test directories or files |
| `evidence` | a list of facts with `file:line`: the call that registers or scans the directory, the test that reads the file, the edge counts |
| `confidence` | `high`, `medium` or `low` (defined below) |
| `cost` | what the pattern adds when it fires (defined below) |
| `covered_by` | when an existing default, rule, fallback or configured key already selects these tests for these files, which one |

### Confidence

- **High:** the evidence names both sides. The files are known (a resolvable path in a
  discovery call or a scan) and so are the consumers (the test that scans, the tests that
  inherit the setup). And what a change selects today falls short of that, measured on the
  graph or deduced from the rule chain.
- **Medium:** the evidence names one side. A discovery call with a resolvable path whose
  files all have edges today: no gap yet, but the next new file will be one. Or a consumer
  inferred by naming convention, such as `tests/Feature/Listeners` for `app/Listeners`.
- **Low:** the detector saw the shape but could not resolve it, such as a scan over a path
  built at runtime. The finding carries the `file:line` and asks a human to look.

Only high-confidence findings count for `--check` and for the hints in `status` and `record`.

### Cost

Watch only adds, so a pattern's cost is the tests it adds, and that cost is paid on every
change it matches. The report gives the cost in three forms, because each answers a different
question:

- **Share when it fires:** the test files under its targets, as a share of all test files
  (`WatchPatterns::testsUnderDirectories()`, `:197-224`). `database/seeders/** => tests` is
  100%. A scanner pattern is one file, roughly 0.1% on the first case.
- **Marginal share:** the same, minus what the rule chain selects for that change without the
  pattern. That is the honest number. On the second case, `app/** => [nine tests]` adds at
  most 1.8%. `config/**` changes are already 100% because of the default, so a pattern there
  would add 0%. This is computed with the rule chain's own side-effect-free selection,
  `RunListBuilder::select()` (`Select\RunListBuilder.php:180-189`), over a hypothetical change
  set made of one matching file.
- **Expected per commit:** how often the pattern would have fired over the last *N* commits
  (default 200, from `git log --name-only`), times its marginal share. A pattern on a
  directory nobody touches is nearly free even at 100%.

**One-time cost of adopting a pattern.** A configured pattern is a non-edge input of every test
under its targets: it becomes a `watch:<pattern>@3` scope in each of their digests
(`Select\NonEdgeInputs.php:40`, `:454`). Adding it changes those digests, so those results stop
validating and the tests run once on the next pass (CHANGELOG 0.12.0 describes the digest).
Adopting `database/seeders/** => tests` therefore re-runs the whole suite once. The report says
so next to the pattern. This follows from reading the code, and has **not been measured**.

## The detectors

The detectors are grouped by what they read. Each entry gives the signal, the Laravel knowledge
it needs if any, its precision and recall trade-off, and its false-positive risk. The generic
subset, which works on any PHPUnit project, is marked **(generic)**.

### Resolving a path statically

Most detectors need to turn an expression into a project-relative path without running it. One
small evaluator over the php-parser AST serves them all (`nikic/php-parser` is already a
dependency, `composer.json:19`, used by `Analysis\DeclarationScanner` through `Analysis\FactsCache`).
It handles:

- string literals, concatenation, `sprintf` with literal arguments, `__DIR__` and
  `dirname(__DIR__, n)`;
- Laravel's path helpers with literal arguments: `app_path()`, `base_path()`,
  `database_path()`, `resource_path()`, `config_path()`, `storage_path()`, `lang_path()`;
- `$this->app->basePath(…)` and `app()->path(…)`.

Anything else is **unresolved**. An unresolved scan or read becomes a low-confidence finding that
carries the `file:line` and lets a human finish it. The evaluator never guesses.

It turns the scanning API into a glob:

| call | glob |
|---|---|
| `glob(X . '/*.php')` | `X/*.php` |
| `scandir(X)`, `opendir(X)`, `File::files(X)`, `new DirectoryIterator(X)`, `new FilesystemIterator(X)` | `X/*` |
| `new RecursiveDirectoryIterator(X)`, `File::allFiles(X)`, `Finder::create()->in(X)` | `X/**` |
| `Finder … ->name('*.php')`, `->depth(0)` | narrowed to `X/**/*.php`, `X/*` |
| `file_get_contents(X)`, `fopen(X)`, `json_decode(file_get_contents(X))`, `parse_ini_file(X)`, `simplexml_load_file(X)`, `require X` of a non-class file | `X` (the literal file) |

### Detectors that read the tests

**T1. Tests that scan the source tree (generic).**

- *Signal:* in each test file, and in every trait and base class under the test directories
  (resolved through `extends` and `use`), the scanning calls above with a path that resolves
  outside the test directories, or to the test directories themselves (the second case's
  `tests/**` scanners).
- *Targets:* the test file itself. When the scan is in a base class or trait, the targets are
  the test files that inherit it, collapsed to directories by the rule in "Choosing targets".
- *Precision:* high when the path resolves. The first case's six scanner lines and the second
  case's model-tree, content and test-tree scanners are all of this shape.
- *Recall:* bounded by the evaluator. A path built from a data provider, or a loop over a
  configured list, comes out low-confidence.
- *False positives:* a test that lists a directory only to count its files (still a
  dependency: the count changes when a file is added, so this is not a false positive); a scan
  of a fixture directory a default already covers (reported as `covered_by`, not suggested); a
  scan of `vendor/` (skipped, and `composer.lock` is a structural input,
  `docs/proposals/self-describing-objects.md` "What it takes to find a result today").

**T2. Data files read at runtime (generic).**

- *Signal:* the read calls above in tests, traits and base classes, resolving to a file that
  is not `.php` (or is `.php` but returns data), and that no default or rule matches. A read
  in a lifecycle method of a base class (`setUp`, `setUpBeforeClass`, `assertPostConditions`,
  `tearDown`) means every inheriting test depends on it.
- *Targets:* the reading test, or every inheriting test for a base-class read. That gives the
  wide spread the second case showed: everything for the watchdog baseline, three or four
  tests for each isolation baseline, one for the API collection.
- *Precision:* high for literal paths. The `covered_by` check needs care, because the fixture
  default is per testsuite directory and case-sensitive (`WatchDefaults\Php.php:31`,
  `Support\Glob.php:45`). The command must evaluate the real default set for the project's
  `<testsuites>` and must never assume `tests/**/Fixtures/**`.
- *False positives:* a file the test writes before it reads it (a temporary file). The
  evaluator skips paths under `sys_get_temp_dir()`, `storage_path('framework')` and paths the
  same method also writes.

**T3. Source-reading tools run from tests (generic, with a catalogue).**

- *Signal:* a test that invokes a library known to read source without executing it. The
  catalogue starts with Scramble (the second case: an API documentation test generating the
  document), PHPUnit architecture-test libraries such as `ta-tikoma/phpunit-architecture-test`
  and `phparkitect/phparkitect` driven from a test, and PHPStan or Larastan run through
  `Process` from a test. The catalogue entry gives the directories the tool reads by default:
  for Scramble, the routes' controllers and form requests (**unverified** whether a
  configuration narrows it).
- *Targets:* the invoking test.
- *Precision:* medium by default, high when the tool's configuration file is present and
  resolvable.
- *False positives:* a test that only boots the tool's service provider. Requiring a call to
  the tool's generation or analysis entry point, not merely a `use`, handles this.

### Detectors that read the application

**A1. Directories registered by discovery (Laravel catalogue).**

- *Signal:* static analysis of `bootstrap/app.php`, `bootstrap/providers.php`, every class in
  the configured providers, `config/*.php` and `composer.json`/`composer.lock` (which packages
  are present), for the discovery APIs in the catalogue below. A discovery call yields a
  directory D and a file shape.
- *Finding:* for each D, the files under D with no edge, and what a new file in D selects
  today by the rule chain: nothing, siblings in the same directory only, or every test.
- *Targets:* from the graph (G1 below). Without a graph, a test directory mirroring D's name,
  at medium confidence.
- *Precision:* high when the call resolves and some file under D has no edge (the first case's
  100 of 265). Medium when every file under D has an edge: there is no gap yet, but a new file
  will be one.
- *False positives:* a discovery call in dead code, or behind an environment check that is off
  in tests. The detector reports the condition it saw and lowers the confidence.

The catalogue, with what the rule chain already does for each:

| package or convention | discovery API | what it registers | today, a new file there selects |
|---|---|---|---|
| Laravel Nova | `Nova::resourcesIn(app_path('Nova'))` | every class in the directory | nothing (first case: measured) |
| Filament | `->discoverResources(in:, for:)`, `discoverPages`, `discoverWidgets`, `discoverClusters` | every class in each directory (Filament 4.x documentation, "Clusters") | nothing (second case) |
| Livewire 3 | class components under `config('livewire.class_namespace')`, default `app/Livewire` | components by class name | nothing, unless a test or template already references the name (**unverified**) |
| Livewire 4 | `component_locations` (default `resources/views/components`, `resources/views/livewire`), `component_namespaces`, `Livewire::addNamespace()` | single-file and multi-file components, class components under `classPath` | single-file components are `.blade.php` under `resources/views`, so `BladeRule` or the fallback; class components under `classPath`: nothing (**unverified**) |
| Laravel event discovery | the `Listeners` directory by default, `withEvents(discover: [...])` with wildcards | listeners by type-hint | the same directory's siblings only (`SiblingRule.php:54-59`); a new subdirectory, or a custom path: nothing |
| Policy discovery | `app/Policies`, `app/Models/Policies`, `Gate::guessPolicyNamesUsing()` | a policy by model name | `app/Policies` siblings only; `app/Models/Policies`: nothing |
| Console commands | `withCommands([...])`, `$this->load(__DIR__.'/Commands')` in a console kernel | every command class, recursively (**unverified** for `withCommands`) | siblings only; new subdirectory: nothing; and edits are under-attributed (G2) |
| Route loaders | `require`/`glob()` over route files from a provider, outside `routes/` | routes | nothing when outside `routes/`; inside `routes/`, every test (`WatchDefaults\Laravel.php:49`, reported as *expensive, cannot narrow*) |
| Package-style migrations | `loadMigrationsFrom(X)` | migrations under X | nothing: `MigrationRule` reads only `database/migrations/` (`MigrationRule.php:92-95`), and so does the fallback |
| Package-style views | `loadViewsFrom(X, ns)` | templates under X | nothing: `BladeRule` and the fallback read only `resources/views/` (`BladeReferences.php:145-148`) |
| Tenancy: stancl/tenancy | tenant migrations path from `config/tenancy.php` (`migration_parameters`, `--path`) | tenant migrations | under `database/migrations/`: `MigrationRule`; elsewhere: nothing (**unverified** default path) |
| Tenancy: spatie/multitenancy | landlord and tenant migration paths, tenant-aware tasks from `config/multitenancy.php` | tasks and migrations | as above (**unverified**) |
| Schema dump | `database/schema/*-schema.sql` (written by `schema:dump`), loaded by the migrator | the schema of every refreshed database | **nothing** (second case: ~89% of test files depend on it) |

The schema dump is listed here because it is found by convention. Its consumers come from G2.

**A2. Scanners in application code reached from tests (generic).** This is the second case's
inline permission-check scanner.

- *Signal:* the scanning calls of T1 in files under the source tree, with a path that resolves
  inside the project.
- *Targets:* from the graph: the test files with an edge to the scanning file
  (`Graph::testFilesDependingOn()`, `Cache\Graph.php:238-241`). Coverage records the scanner's
  own execution, so the tests that hold its edge are the ones that called it. That is exactly
  the second case's five authorization tests.
- *Precision:* high when the holders are few. When the scanner is also used on a hot path (a
  middleware), every HTTP test holds its edge, and the finding says so, with its cost.
- *Without a graph:* reported as low-confidence, naming the scanner and the scanned directory.

**A3. Files excluded from coverage (generic).**

- *Signal:* the `.php` files under the configured `<source><exclude>` entries
  (`Record\SourceScope.php:56-72`, the same predicate `ResiduePatterns::isUnattributable()`
  uses).
- *Finding:* `advice`, not `add`. Since 0.12 each of these files runs every test
  (`ResiduePatterns.php:159-171`), and no watch pattern can narrow that. The precise
  alternative is to remove the entry from `<source><exclude>`. Coverage then records which
  tests execute the file, and `PhpEdgeRule` selects those.
- *What the advice must state, because the second case shows it matters:*
  - **removing the exclude changes the coverage report too.** `<source>` is shared with
    PHPUnit's own coverage report, so a team that excluded a legacy subsystem to keep its
    percentage honest will see it fall;
  - **once-per-process work stays under-attributed** after the exclude is removed. A kernel or
    a provider registered once per application instance is fine in Laravel, because the
    testing `TestCase` creates a fresh application per test. Anything guarded by a static flag
    is not (**unverified** per file; G2 checks it after the next recording);
  - **what it buys:** the files, how often they changed in the last *N* commits, and the
    marginal share they cost today (100% of test files, each time).

### Detectors that read the graph

These need a recorded graph and run on whatever graph the project has. They degrade gracefully:
with no graph they are skipped, and the report says which findings it could not compute.

**G1. Where the edges into a directory come from.** This is the evidence-based target for any
directory D (from A1, or from a pattern being audited).

For D, collect the test files with an edge into any file under D. Group them by test directory
at every depth, and for each directory T record two numbers:

- **coverage**: the share of D's edge holders that live under T;
- **density**: the share of T's test files that hold an edge into D.

The first case's `app/Nova/**` targets, `tests/Feature/Nova` and `tests/Feature/Partner/Nova`,
are the shape this produces: two directories that hold almost all of the directory's edge
holders, and in which most tests hold one. The real shares were not recorded at the time, so
the precision check has to measure them.

**G2. Once-per-process inputs, compared against their plausible dependents.** This is the
detector behind `database/seeders/** => tests` and `database/schema/** => [...]`.

1. **Find the base test classes and what they set up.** Parse the test tree, follow `extends`
   and `use` chains, and record for each abstract base, and for each trait used by many tests:
   - `RefreshDatabase`, `LazilyRefreshDatabase`, `DatabaseMigrations`, `DatabaseTruncation`;
   - `protected $seed = true`, `protected $seeder = X::class`, and the `#[Seed]` and `#[Seeder(X)]`
     attributes (Laravel 13 documentation, "Running Seeders");
   - `$this->seed(X)` and `$this->artisan(...)` in `setUp()`, `setUpBeforeClass()`, and data
     loaded behind a static flag.
2. **Decide whether each input runs once per process or once per test.** That is the step
   the second case's negatives require:
   - seeding through `$seed`, `$seeder` or `#[Seed]` with `RefreshDatabase` runs inside the
     guarded migrate step, so its code executes **once per worker**. Laravel's documentation
     says the database is seeded "before each test", which describes what each test sees, not
     how often the seeder's code runs. The first case's 4.3% is consistent with once per
     worker;
   - `$this->seed(X)` in `setUp()`, or seeding with `DatabaseMigrations`, runs **per test**,
     coverage sees it every time, and there is no finding. That is the second case's negative;
   - a schema dump present together with `RefreshDatabase` or `DatabaseMigrations` is loaded
     once per worker by the first.
3. **Compare edge holders with plausible dependents.** Plausible dependents are the test files
   whose class inherits the base (transitively) and does not override the relevant property.
   Edge holders come from `Graph::testFilesDependingOn()` for each file of the input: the
   seeder, the seeders it calls through `$this->call([...])`, and the commands it runs. The
   ratio is `holders / dependents`. The first case: 37 / 851, which is **4.3%**. A non-PHP
   input, such as the schema dump, has no holders by construction, so the ratio is 0.
4. **Flag below a threshold.** The default proposed is 50%. A ratio near 0 with many
   dependents is high confidence. The pattern covers the input's directory
   (`database/seeders/**`, `database/schema/**`), and the targets are the dependents, collapsed
   by the rule below. With 851 of 853 that gives every test directory, written `tests`.

The same comparison works for console commands, with a different notion of plausible
dependent: the test files whose source mentions the command, by class name or by its
`$signature` in `$this->artisan('…')` or `Artisan::call('…')`. The first case had 1 holder
against 9 mentions. The targets are the mentioners' directories, which gave the first case's
`app/Console/Commands/** => tests/Feature/Console`.

With `static_declaration_edges` on, coverage edges for `database/seeders/`,
`database/migrations/` and `app/Console/Commands/` are refused (`Cache\GraphUpdater.php:143`,
`Laravel\OncePerProcessPaths.php:78-82`), so such a file usually has no edge and the residue
runs every test for it (`ResiduePatterns.php:153-156`). The detector then reports
`covered_by: residue` instead of a finding, unless a static edge (a test naming the seeder)
gives the file an edge and keeps it out of the residue. **Unverified:** whether the base
`TestCase`'s `$seeder = X::class` gives the seeder a static edge from every inheriting test.

**G3. Coarse table attribution (Laravel, hint).** Read `Graph::testTables()`
(`Cache\Graph.php:309`). When at least 90% of the database tests record at least 90% of all
recorded tables, say that `MigrationRule` cannot narrow, and how much it selects: the second
case's 88% for any migration. Name the likely causes as hypotheses: a query in
`assertPostConditions()` or an observer touching every table. No pattern is suggested.

**G4. Directories with unattributed files (generic, informational).** Directories where many
`.php` files have no edge and no detector explains why. On their own these are often dead code
or untested code, not a watch gap. They are reported at low confidence, and only when the
directory also appears in A1's catalogue or in a framework's autoload map entry.

### Choosing targets: precise, or every test

Every finding ends with a set of dependents: the test files that would be affected by a change
the pattern matches. The command writes them as targets by this rule:

1. **Almost everything: write every test directory.** When the dependents are at least 80% of
   the test files, the targets are the testsuite directories and files, the same list the
   residue uses (`ResiduePatterns::targetsFor()`, `ResiduePatterns.php:97-103`), written as
   `tests` when they all live under one root. A precise list would save at most a fifth of the
   suite, and it would miss every future test that inherits the base. The first case's seeder
   (851 of 853) and the second case's schema dump (~89%) land here.
2. **Otherwise, collapse to directories where the dependents are dense.** Choose the smallest
   set of test directories that contains every dependent, where each chosen directory has
   density at least 50% (G1). A directory target over-selects a little, and in exchange
   catches the next test written in that directory. That is how the first case's
   `tests/Feature/Nova` and `tests/Feature/Console` come out.
3. **Few and scattered: list the files.** Five dependents or fewer that are not dense anywhere
   are listed as files. This is the scanner case, and the second case's nine content scanners.

All three thresholds are options (`--all-tests-at`, `--density`, `--max-files`). Because watch
only adds, a wrong choice costs tests, not correctness, for everything the rules already select.
For the gap itself, the asymmetry is the reverse: a target that is too narrow leaves part of
the gap open. That is why rule 1 prefers every test once the saving is small.

## The diff against the current block

The command evaluates the project's own `watch` block against the findings and the rule chain.
Every comparison is done over the real tree (`git ls-files`), not over glob algebra: for each
tracked file a key matches, ask what the key adds that nothing else selects.

| verdict | when | example |
|---|---|---|
| **missing** | a high- or medium-confidence finding that no configured key, default, fallback or rule covers | the first case before its investigation: all eleven lines |
| **redundant** | for every tracked file the key matches, every target is already selected by another configured key, a default, or a rule that always claims that file with a superset of the targets | `app/Nova/*.php => [a test under tests/Feature/Nova]` next to `app/Nova/** => tests/Feature/Nova`; `config/** => tests/Unit` next to the default `config/**` |
| **ineffective: cannot narrow** | the key matches only files a default, a fallback or the coverage-exclude residue already sends to every test, and its targets are a subset of that | `routes/** => [two tests]`; `'app/Providers/AppServiceProvider.php' => [...]` for an excluded provider (`ResiduePatterns.php:161-165`); `resources/views/** => [...]` with no evidence behind its targets, for the templates the fallback covers |
| **ineffective: replaced nothing** | a key spelled like a fallback whose comment or history says it was meant to replace it. Since 0.12 keys are unioned (`WatchRule.php:47-54`) | a pre-0.12 `'resources/views/**' => ['tests/Feature/Views']` written to stop every template edit from running the suite |
| **ineffective: matches nothing** | no tracked file matches the key: a typo, a moved directory, a leading `./`, the wrong case (`Support\Glob.php:45`), or `*` where `**` was meant (`:27-35`) | `app/Filament/Resources/*Resource.php` when resources live one directory deeper |
| **ineffective: targets nothing** | no test file is under any target (`WatchPatterns.php:197-224`) | a renamed test directory |
| **ignored** | an entry `Config` drops: a list entry such as `['config/**']`, or a target that is not a string or a list of strings (`Config.php:482-498`) | `'watch' => ['config/**']` |
| **kept** | a configured key whose targets have evidence: it adds tests for files some rule already claims | the first case's `database/migrations/**` and `resources/views/**` lines |

The "cannot narrow" verdicts do not tell the user to delete the line. They say what the line
does: on the first case's `resources/views/**`, it adds two scanner tests to every template
edit, and it adds nothing for a template the fallback already sends to every test. The user
keeps it for the first reason.

## Output

### The human report

```
$ vendor/bin/phpunit-replay watch:suggest

watch:suggest · laravel · graph recorded at 1a2b3c4 · 853 test files · 2,194 source files

MISSING  high   app/Nova/** → tests/Feature/Nova, tests/Feature/Partner/Nova
  why       app/Providers/NovaServiceProvider.php:41  Nova::resourcesIn(app_path('Nova'))
            registers every class in app/Nova; 100 of its 265 files have no edge, and a
            new file there selects 0 tests today.
  targets   G1: 2 directories hold n% of the edge holders into app/Nova (density n%, n%)
  cost      when it fires n% of test files · marginal n% · fired in n of the last 200 commits

MISSING  high   database/seeders/** → tests
  why       tests/TestCase.php:18  $seeder = ReferenceDataSeeder::class with RefreshDatabase:
            runs once per worker; 851 test files inherit it, 37 hold its edge (4.3%).
  targets   851 of 853 test files (≥ 80%): every test directory
  cost      when it fires 100% · marginal 99.8% · fired in n of the last 200 commits
  note      adopting it re-runs every test once (new watch scope in every digest)

MISSING  high   app/** → tests/Unit/SourceHeaderTest.php, … (3 files)
  why       tests/Unit/SourceHeaderTest.php:22  Finder over app, config, routes, database,
            bootstrap; … (one line per scanner)
  cost      when it fires 0.4% · marginal 0.4%

ADVICE   high   2 files excluded from coverage run every test when they change
  files     app/Http/Kernel.php, app/Exceptions/Handler.php  (<source><exclude>, phpunit.xml:24)
  instead   remove them from <source><exclude>; coverage then records which tests run them.
  trade-off the coverage report will include them; …

HINT            routes/api.php:12  glob(__DIR__.'/api/*.php') loads 38 route files. routes/**
                already runs every test; watch cannot narrow it.

DIFF     redundant    app/Nova/*.php → its one target is under tests/Feature/Nova (app/Nova/**)
DIFF     kept         resources/views/** → adds 2 scanner tests to every template edit

11 missing (9 high, 2 medium) · 1 advice · 1 hint · 1 redundant
Paste-ready block: watch:suggest --format=php
```

In the example, `n` stands for figures that were not recorded during the first case's
investigation. Every other number is a measurement quoted above. Each `why` line is one piece
of evidence with its `file:line`, so a reviewer can open it and decide.

### The ready-to-paste block

`--format=php` prints a `watch` block containing the existing keys that are kept and the
missing ones, one comment per pattern stating its reason and its cost. Redundant lines stay,
marked, because deleting the user's lines is not this command's job.

```php
'watch' => [
    // Nova::resourcesIn(app_path('Nova')) — NovaServiceProvider.php:41. 100/265 files have no
    // edge; a new resource selected 0 tests. Fires: n% of test files.
    'app/Nova/**' => ['tests/Feature/Nova', 'tests/Feature/Partner/Nova'],

    // Source scanners: SourceHeaderTest (app, config, routes, database, bootstrap),
    // LocaleFloatFormattingTest (app_path()), SlugIsolationTest (greps app/). 0.4%.
    'app/**' => ['tests/Unit/SourceHeaderTest.php', 'tests/Unit/Support/LocaleFloatFormattingTest.php', 'tests/Feature/Wallet/SlugIsolationTest.php'],

    // Once per worker: TestCase.php:18 seeds through $seeder; 37 of 851 dependents hold
    // its edge. 100% when it fires. Adopting it re-runs every test once.
    'database/seeders/**' => ['tests'],

    // … one entry per finding …

    // Redundant: its target is under tests/Feature/Nova, already mapped by app/Nova/**.
    // Kept for its reason: the authorization guard lists app/Nova/*.php.
    'app/Nova/*.php' => ['tests/Feature/Nova/NovaResourceAuthorizationTest.php'],
],
```

### `--json`

For tooling and for the golden tests. A version field, the inputs the result was computed from
(so a cached copy can be checked for staleness), and the findings in the model above:

```json
{
  "version": 1,
  "inputs": {"graph": "<graph sha256>", "config": "<phpunit-replay.php sha256>", "phpunit": "<phpunit.xml sha256>", "tree": "<HEAD sha>", "dirty": false},
  "detectors": {"ran": ["T1", "T2", "A1", "A3", "G1", "G2"], "skipped": {"G3": "no tables recorded"}},
  "findings": [
    {
      "id": "A1:app/Nova/**",
      "kind": "add",
      "pattern": "app/Nova/**",
      "targets": ["tests/Feature/Nova", "tests/Feature/Partner/Nova"],
      "confidence": "high",
      "evidence": [
        {"detector": "A1", "file": "app/Providers/NovaServiceProvider.php", "line": 41, "fact": "Nova::resourcesIn(app_path('Nova'))"},
        {"detector": "G1", "fact": "files 265, without edge 100"}
      ],
      "cost": {"share_when_fires": null, "marginal_share": null, "fired_in_commits": null, "commits_examined": 200, "rerun_on_adoption": null},
      "covered_by": null
    }
  ],
  "diff": [
    {"key": "app/Nova/*.php", "verdict": "redundant", "because": "app/Nova/**"}
  ]
}
```

### Options

| option | effect |
|---|---|
| `--format=text\|php\|json` | the report (default), the block, or JSON |
| `--check` | exit 1 when a missing high-confidence finding exists; see "CI" |
| `--min-confidence=high\|medium\|low` | what the report and `--check` include (report default: medium) |
| `--no-graph` | run only the static detectors |
| `--history=N` | commits examined for the per-commit cost (default 200, `0` disables) |
| `--all-tests-at`, `--density`, `--max-files` | the target thresholds above |
| `--detector=T1,G2,…` | run a subset |

## Integration

### A one-line hint in `status` and after `record`

The hint is a single line, shown only when at least one **high-confidence missing** finding
exists:

```
watch:     2 likely gaps (app/Nova/**, database/seeders/**) — phpunit-replay watch:suggest
```

Keeping it from being noisy:

- **`status` stays read-only and fast.** It never runs a detector. `record` runs them at its
  end, when the graph is already in memory, and writes the summary to
  `<stateDir>/watch-suggest.json` together with the inputs it was computed from. `status`
  reads that file and prints the line only while the inputs still match (graph, configuration,
  `HEAD`). That costs one JSON read, the same kind of work as the mirror line it already prints
  (`Console\Commands\StatusCommand.php:89-90`).
- **After `record`, the line is shown only when the set of high-confidence findings differs
  from the last set shown.** A team that has looked and decided is not told again until
  something new appears.
- **In CI the hint is not printed** (the `CI` variable the package already detects,
  `docs/configuration.md` "Environment variables"). CI uses `--check` instead.
- **Opt-outs:** `'suggest' => ['hint' => false]` in `phpunit-replay.php` turns the line off.
  `'suggest' => ['ignore' => ['A1:app/Legacy/**', 'G4:*']]` silences findings by id, for the
  hint, the report and `--check` alike. The ignore list lives in configuration so that the team
  reviews it, and the command only reads it.
- **Budget:** the detectors at the end of `record` are capped (proposed: 3 s). When the cap is
  hit, `record` writes what it has and the hint says `watch:suggest` has more.

### First run and `remote:init`

- **First run:** the first `record` that saves a baseline prints the hint if it applies, and
  a sentence in the README's setup section says to run `watch:suggest` after the first record.
  That is the moment a team is still configuring.
- **`remote:init`:** no. It sets up a remote cache and proves a round trip
  (`Console\Commands\RemoteInitCommand.php` class docblock), usually before any graph exists,
  and graph-based detectors would have nothing to read. Mixing the two would make a command
  that "answers does it work" (same docblock) answer a second question. Its closing lines could
  mention `watch:suggest` as a next step without running it.

### CI

```
vendor/bin/phpunit-replay watch:suggest --check
```

- exit **0**: no missing high-confidence finding (after `ignore`);
- exit **1**: at least one, listed in the same format as the report;
- exit **2**: the command could not evaluate (unreadable configuration, a `watch` block of
  the wrong type).

Without a graph, the static detectors still run, the report says which graph detectors were
skipped, and the exit code reflects what could be checked. `--check --require-graph` turns a
missing graph into exit 2, for a job that runs after the baseline is pulled.

## Safety

- **It never edits configuration.** It prints a block, and writes only its own summary under
  the state directory.
- **It never runs tests and needs no coverage driver.** It reads `graph.json`, the PHPUnit
  configuration, `phpunit-replay.php`, the source and `git log`. It never consults the driver.
- **It never boots the application.** Every Laravel signal is read statically, for the reason
  `OncePerProcessPaths` gives: what is only reachable from a booted container would classify
  differently depending on which process asked (`Laravel\OncePerProcessPaths.php:51-64`). It
  also keeps the command safe to run on a machine with no database.
- **Without a graph it degrades.** T1–T3 and A1–A3 run, G1–G4 are skipped and listed as
  skipped, and A1's targets fall back to mirrored directory names at medium confidence.
- **Performance budget**, for a project the size of the first case (2,194 source files, 853
  test files):
  - parse the test tree, the providers, `bootstrap/` and `config/`, but not all of `app/`.
    A2 needs a token scan of `app/` for the scanning functions, and parses only the files that
    contain one;
  - reuse `Analysis\FactsCache` and the persisted content hashes
    (`<stateDir>/content-hashes.json`, CHANGELOG 0.12.0), so a warm run reparses only changed
    files;
  - targets: **under 10 s cold and under 2 s warm** for the full command, **3 s** at the end of
    `record`, and **one file read** in `status`. These budgets are **unmeasured**. The history
    cost, one `RunListBuilder::select()` per pattern per commit examined, is the term most likely
    to exceed them, and `--history` bounds it.

## Limits: where watch cannot help

Some dependencies cannot be expressed as a glob from static evidence. The command should name
them and point to the lever that can help, without pretending:

- **Dynamic class resolution from strings or database rows.** `app("App\\Reports\\{$type}")`, a
  settings table that stores handler class names, a morph map built from configuration. No
  path resolves. The lever is `static_declaration_edges` when a test's own source names the
  class: name references are order-independent edges (`Analysis\FileFacts.php` class docblock).
  The second case's `new $class` with no convention behind it is the negative: nothing to
  suggest.
- **Tenant-specific code by convention.** `app/Tenants/{Tenant}/…` loaded by a tenant id read
  at runtime. A1 can find the loader if it is a glob, but not which tenant a test uses. A
  directory-wide pattern towards the tenant tests is the honest answer, at its cost.
- **Code generated at runtime.** Compiled containers, proxies, `eval`, the compiled Blade
  cache. The sources they are generated from are what to watch, and they are usually already
  covered.
- **Excluded code with a reason to stay excluded.** Watch cannot narrow it (A3). The choices
  are run everything, or remove the exclude and accept the coverage report change.
- **Once-per-process code outside the conventions.** A service initialised once behind a
  static flag. G2 finds it only through a base-class setup it can see. Otherwise the lever is
  `static_declaration_edges` plus a pattern, and `docs/reproducibility.md` records why no
  convention list closes that hole.

## Testing strategy

- **One fixture per detector**, built from `tests/Fixtures/Projects/laravel-lite` (whose
  `phpunit.xml` declares one testsuite, `tests/Feature`, and includes `app`). The variants need
  no real packages, because every detector is static: a stub `Nova` class with a
  `resourcesIn()` call in a provider, a Filament-shaped panel provider, a base `TestCase` with
  `$seeder` and `RefreshDatabase`, the same with `$this->seed()` in `setUp()` (the negative),
  a `database/schema/sqlite-schema.sql`, a scanner test, a scanner in `app/` called by two
  tests, a `<source><exclude>`, a `routes/api.php` that globs, a hard-coded morph map (a
  negative), and a `tests/Fixtures/` read under a `tests/Feature` testsuite (the per-testsuite
  default trap).
- **Graph detectors on synthetic graphs.** G1–G3 take a `Graph` built with `link()` and
  `replaceTestTables()`, so the 37-of-851 shape and the 158-identical-tables shape are
  reproduced without recording anything.
- **Golden outputs** for the text report, the PHP block and the JSON, one per fixture. JSON is
  the contract; the other two are snapshots.
- **The diff verdicts**, each from a configuration fixture: a redundant nested key, a key that
  cannot narrow a default, a key on an excluded file, a key with a leading `./`, a wrong-case
  key, a `*`-for-`**` key, a list entry that `Config` drops, and the two kept keys spelled like
  fallbacks.
- **Precision against a known answer.** On the first case, at the commit where its block was
  adopted, run `watch:suggest` and compare it with the block. Per line: did the command find
  it (recall over eleven lines), with which targets (exact, wider, narrower), and at which
  confidence. Per extra finding: a human verdict, as precision. `app/Nova/*.php` must come out
  as `redundant`. Repeat on the second case, where the twelve findings above, including the
  negatives, are the answer key. Both projects are private, so this is a manual acceptance
  step whose anonymous numbers go into the PR, not into the package's CI.

## Delivery order

Each step ships on its own and is useful without the next. None changes what `run` selects.

1. **The command, the report, the block and JSON, with T1 (scanners), T2 (data files) and A3
   (excluded files), and the diff.** These need no graph and no Laravel knowledge, they are
   the generic subset, and they cover the first case's six scanner lines and the second case's
   scanners and data files.
2. **Graph-based targets and under-attribution: G1, G2, G3 and A2.** This adds
   `database/seeders/**`, the commands, the schema dump's consumers, application-side scanners,
   and evidence-based targets for everything step 3 finds.
3. **The Laravel discovery catalogue (A1) and T3's tool catalogue.** Nova, Filament,
   Livewire, events, policies, commands, route loaders, `loadMigrationsFrom`, `loadViewsFrom`,
   tenancy, schema dumps. Each catalogue entry is a small class with its own fixture, so new
   ones can be added one at a time.
4. **The `status` and `record` hint, its cached summary, the opt-outs, and `--check`.** Last,
   because a hint is only worth showing once its findings are trustworthy, and the precision
   check after step 3 is what says so.

## Decisions (2026-10-01)

The owner chose the recommended option on every open question below.

| # | Question | Decision |
|---|---|---|
| 1 | Schema dump and new sibling subdirectories: rules or suggestions? | **Implemented in 0.13.0** (`Laravel\Rules\SchemaDumpRule`, `Laravel\Rules\SiblingRule::nearestAncestorWithEdges()`). **Package rules.** Both are universal in Laravel. `schema:dump` is a framework feature that `RefreshDatabase` loads for every database test, and a new subdirectory of a sibling directory is discovered like its parent. They close false greens for every project; they do not promote one project's choices. |
| 2 | Initial thresholds (80% dependents → every test; 50% directory density; under-attribution below 50%) | Accepted to start with, then calibrated against the two real cases and user reports. |
| 3 | Where the ignore list lives | In `phpunit-replay.php`, so it is reviewed and shared. An ignored gap stays visible to the team. |
| 4 | Does `record` run the detectors? | Yes, within a budget. `record` writes the summary (under 2 s warm) and `status` only reads it, so the hint reaches people who do not know the command exists. |
| 5 | Recording coverage for excluded paths in a separate scope | Later, as its own proposal. Until then the command advises removing the exclude and states the coverage-percentage trade-off. |
| 6 | Should `MigrationRule` learn configured paths (`loadMigrationsFrom`, tenant migrations)? | **Implemented in 0.13.0** (`Laravel\MigrationPaths`: `migration_paths`, literal `loadMigrationsFrom()` calls, `stancl/tenancy`; by default a migration selects by whether the test database runs it, table by table only under `migrations => 'conservative'`). Yes. The rule attributes them table by table, which is more precise than a suggested pattern and covers every project without action. |
| 7 | Separate command or `explain --watch` | A separate `watch:suggest` command, with its own `--check`. |
| 8 | Show the one-time adoption cost in seconds | Yes, computed from recorded test durations. |

## Open questions for the owner (answered above)

1. **Should some findings become built-in rules instead of suggestions?** Two are Laravel
   conventions, not one project's choices: the schema dump (`database/schema/**`, which no rule
   or default covers today) and new subdirectories under the sibling directories. A fallback
   for the first and a recursive sibling match for the second would close them for everyone.
   The package's standing rule is never to promote one project's choices into defaults, and
   the question is whether these two are conventions enough to qualify.
2. **Thresholds.** Every test at 80% dependents, directory density 50%, under-attribution
   flagged below 50%. Accept these to begin with, and tune them on the two precision checks?
3. **Where the ignore list lives.** In `phpunit-replay.php` (reviewed, shared) as proposed, or in
   the state directory (per machine, written by a `--dismiss` flag)?
4. **Should `record` run detectors at all?** The alternative is a hint that only says "run
   `watch:suggest`" when no summary exists, which costs `record` nothing and tells less.
5. **The coverage-exclude trade-off.** The second case kept 110 files excluded to protect its
   coverage percentage, and pays a full run for each edit. Is a package-level way to record
   coverage for paths the report excludes in scope (a separate source scope for recording), or
   is "remove the exclude, or run everything" the whole answer?
6. **`MigrationRule` for other paths.** `loadMigrationsFrom()` and tenancy paths fall outside
   `database/migrations/`. Should the command suggest a pattern towards every database test,
   as it would today, or should `MigrationRule` learn configured paths, which removes the gap
   for every project?
7. **Name and surface.** `watch:suggest` as a separate command, or `explain --watch`? The
   proposal prefers a separate command because its input is the whole project, not one path.
8. **Adoption cost.** Adopting a wide pattern re-runs every test under its targets once. Should
   the report also print the one-time cost in seconds, from recorded durations, so a team
   can choose when to adopt?
