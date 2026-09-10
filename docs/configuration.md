# Configuration reference

Every key and every environment variable, in full. The [README](../README.md#configuration) covers
the handful you are likely to set; this is the complete surface.

Nothing here is required. A project with no `phpunit-replay.php` and no environment variables set
works — autodetection covers the common cases.

## `phpunit-replay.php`

An optional file at the project root returning an array. Every key is optional; the value shown is
the default.

```php
<?php

// phpunit-replay.php
return [
    'state_dir' => null,                  // null = ~/.phpunit-replay/<project-key>
    'remote' => null,                     // null | 'file:///mnt/replay-cache' | 'https://cache.example.com/replay/' | 'git@github.com:org/project-replay-cache.git'
    'remote_token' => null,               // bearer token for the HTTP backend
    'remote_push' => 'objects',           // 'objects' | 'all' | 'off'
    'remote_branch' => 'main',            // git backend: which branch holds the cache
    'remote_refresh_seconds' => 300,      // git backend: how often the local mirror re-fetches
    'remote_timeout' => 60,               // git backend: total time budget for a push
    'default_branch' => null,             // null = autodetect
    'baseline_branches' => [],            // ordered nearest-baseline candidates; [] = [default_branch]
    'watch' => [],                        // extra glob => test directory/file mappings
    'never_cache' => [],                  // globs of test files that always run for real
    'quarantine_release_after' => 20,     // stable passes needed to leave automatic quarantine
    'laravel' => 'auto',                  // 'auto' | 'on' | 'off'
    'laravel_parallel_isolation' => true, // false to run --parallel on Laravel without per-worker DB isolation
    'junit_merge' => true,                // merge cached results into --log-junit output
    'mode' => 'auto',                     // extension mode override
    'static_declaration_edges' => false,  // see docs/reproducibility.md before turning this on
    'hermeticity_heuristics' => false,    // reserved; not implemented — leave false
];
```

### State and storage

| Key | Default | Notes |
|---|---|---|
| `state_dir` | `null` | Where `graph.json`, results, quarantine and the remote mirror live. `null` resolves to `~/.phpunit-replay/<project-key>`, keyed off the project so several checkouts of different projects never collide. `baseline-path` prints the resolved value. |

### Remote cache

Full setup guide, per backend: [sharing-the-cache.md](sharing-the-cache.md).

| Key | Default | Notes |
|---|---|---|
| `remote` | `null` | Backend URL. `file://` for a mounted path, `https://` for an S3/MinIO/WebDAV endpoint with GET/PUT/HEAD, or an SSH/HTTPS git URL for a dedicated cache repository. |
| `remote_token` | `null` | Bearer token, HTTP backend only. |
| `remote_push` | `'objects'` | What this machine may publish. `off` — pull only, never writes. `objects` — its own test-file results, keyed by content; safe from anywhere and never conflicts, but it *is* a write. `all` — also the branch baseline under `graph/**`, which is what everyone else's cold start reads; for the one CI job that owns the branch. **See the note below before leaving this at its default on a write-restricted cache.** |
| `remote_branch` | `'main'` | Git backend: which branch holds the objects. Usually a branch of a dedicated cache repository, but it can equally be an orphan branch of the project's own repository — a name that does not exist there yet is created orphan on the first push, which removes the whole setup at the price of the cache landing in every plain `git clone`. See [sharing-the-cache.md](sharing-the-cache.md). |
| `remote_refresh_seconds` | `300` | Git backend: how stale the local mirror may get before it re-fetches. |
| `remote_timeout` | `60` | Git backend: total seconds a push may take before giving up. Giving up is a warning, never a failure. |

A remote that is unreachable, unauthenticated or misconfigured always degrades to a warning on
stderr and a local-only pass. It cannot break a test run.

The default, `objects`, is a *write* — every machine publishes its own test-file results. That is
deliberate and it is where most of the sharing value comes from: a test one developer ran, the next
replays. Content addressing makes it safe from anywhere, since two writers of the same key are a
no-op rather than a conflict.

**The recommended setup is CI-writes-everyone-reads.** Keep the config file free of any guess
about where it is running:

```php
'remote_push' => 'off',
```

and let each CI job declare its own role through the environment, which every CI system can do:

| job | environment | also runs |
|---|---|---|
| a developer's machine | *nothing* | — |
| PR / branch job | `PHPUNIT_REPLAY_REMOTE_PUSH=objects` | — |
| baseline job, after a merge | `PHPUNIT_REPLAY_REMOTE_PUSH=objects` | `push --graph` |

`'remote_push' => getenv('CI') ? 'objects' : 'off'` also works and reads nicely, but it depends on
the host exporting `CI`, which Jenkins and TeamCity do not do by default, and it fails *open* —
anything that happens to set `CI` starts publishing. An explicit variable per job fails closed.

`all` is needed nowhere. `push --graph` publishes the branch baseline on the strength of its own
flag and is not gated by `remote_push`, so one named job does that one thing. `all` is not unsafe
— a CI-detected run refuses to publish a branch graph unless `--allow-ci-baseline` is passed
(`RunPipeline.php:1436`), so PR jobs do not quietly publish graphs — but it puts the decision in a
config file rather than in the job that means it. Note that the `--allow-ci-baseline` gate only
engages once `CI` is detected: on a runner that does not export `CI`, it does not apply at all.

Developers lose nothing by `off`: they read the graph and every object, a new test of theirs
executes for real locally (it is new to the graph), the PR job publishes its result, and from then
on the team replays it.

**Why homogeneous writers matter.** A content key is built from the *structural* fingerprint plus
the part of the environment that can change a test's outcome — PHP `MAJOR.MINOR` and the OS
family (`Cache\Fingerprint::canonicalResultEnvironment()`). A remote object is adopted on a key
match, and nothing re-checks the environment afterwards, so those two keys have to be in the
address: without them an object recorded under PHP 8.2 would be replayable by a machine on
PHP 8.4, which local recordings are protected from (environmental drift discards them) but
adopted ones are not.

What that leaves is a hit-rate question rather than a correctness one. Two machines that differ
only in coverage driver still share their cache — deliberately, since a driver decides which
lines are *reported*, not whether an assertion passed. Two machines on different PHP minors, or
different operating systems, now share nothing at all: a writer whose environment does not match
the readers' publishes objects nobody can address. Let developer machines publish only when
their environment matches CI's.

### Baselines

| Key | Default | Notes |
|---|---|---|
| `default_branch` | `null` | `null` autodetects, in order: `origin/HEAD`, then `init.defaultBranch` if that ref exists, then `main`, then `master`. Set it explicitly when your team branches from something other than the repository's nominal default — a git-flow project that branches from `develop` while `origin/HEAD` points at `master` needs this, because autodetection answers "what is this repo's default branch", not "where do baselines live". |
| `baseline_branches` | `[]` | Ordered candidates for a branch with no baseline of its own. `[]` means `[default_branch]`. Candidates whose recorded commit is not an ancestor of `HEAD` are dropped; of the rest, the one fewest files away from your tree wins. `['develop', 'master']` is the git-flow shape. |

### Selection

| Key | Default | Notes |
|---|---|---|
| `watch` | `[]` | Extra `glob => test directory or file` mappings, merged with the built-in defaults below. For anything a coverage driver cannot see a test read. |
| `static_declaration_edges` | `false` | Adds a static-analysis hop alongside coverage attribution and filters coverage edges to executed function bodies. It makes recorded graphs far more reproducible but changes which files carry edges at all — read [reproducibility.md](reproducibility.md) for the measured cost before enabling it. |

Built-in `WatchRule` defaults, by detected framework:

| Framework | Detected by | Patterns |
|---|---|---|
| Generic | always | `.env*`, `phpunit.xml*`, `docker-compose*.y*ml`, `tests/**/Fixtures/**`, `tests/**/__snapshots__/**` |
| Laravel | `artisan` exists | `config/**`, `routes/**`, `database/migrations/**`, `resources/views/**`, `lang/**`, `resources/lang/**`, `app/** !*.php`, `bootstrap/*.php` |
| Symfony | `config/bundles.php` exists | `config/**`, `migrations/**`, `templates/**`, `translations/**` |

### Cache honesty

| Key | Default | Notes |
|---|---|---|
| `never_cache` | `[]` | Globs of test files that always run for real, whatever the cache holds. For browser tests, or anything touching a live external service. The per-test equivalent is the `#[NotCacheable]` attribute. |
| `quarantine_release_after` | `20` | Consecutive stable passes before a quarantined test is released automatically. `prune --flaky` clears the quarantine by hand. |
| `hermeticity_heuristics` | `false` | Reserved for a future heuristic that would flag suspicious tests in `status` without quarantining them. Not implemented in this build. |

### Framework and runner

| Key | Default | Notes |
|---|---|---|
| `laravel` | `'auto'` | `auto` enables the Laravel integration when `artisan` exists. `on` forces it, `off` disables it. Read only by the Laravel detector — nothing else in the package consults it. |
| `laravel_parallel_isolation` | `true` | On `--parallel`, wires up Laravel's own per-worker database isolation when Laravel, Paratest and a resolvable `ParallelRunner` are all present. Set `false` if your project deliberately runs `--parallel` against one shared database. |
| `junit_merge` | `true` | Merge cached results into `--log-junit` output, so the report holds one complete `<testcase>` per test. Replayed entries carry `<property name="replayed" value="true"/>`. |
| `mode` | `'auto'` | Extension mode override: `auto`, `record`, `replay`, `off`. Leave it at `auto` unless you know why not. |

## Environment variables

Environment variables **always win** over `phpunit-replay.php`.

| Variable | Effect |
|---|---|
| `PHPUNIT_REPLAY=0` | Disables phpunit-replay entirely, even with the extension registered in `phpunit.xml`. The kill switch. |
| `PHPUNIT_REPLAY_DEBUG=1` | Prints every selection decision to stderr. |
| `PHPUNIT_REPLAY_STATE_DIR` | Overrides `state_dir`. |
| `PHPUNIT_REPLAY_REMOTE` | Overrides `remote`. |
| `PHPUNIT_REPLAY_REMOTE_TOKEN` | Overrides `remote_token`. |
| `PHPUNIT_REPLAY_REMOTE_PUSH` | Overrides `remote_push` (`objects` \| `all` \| `off`). |
| `PHPUNIT_REPLAY_DEFAULT_BRANCH` | Overrides `default_branch`. |
| `PHPUNIT_REPLAY_BASELINE_BRANCHES` | Comma-separated; overrides `baseline_branches`. |
| `PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES` | Overrides `static_declaration_edges`. |
| `PHPUNIT_REPLAY_MODE` | Overrides the extension `mode`. Also accepts the internal `record-subset` and `results-only` values the wrapper uses itself. |
| `PHPUNIT_REPLAY_KEEP_RUN=1` | Keeps the generated `.phpunit-replay.xml` and the run's partial directory for inspection. |
| `PHPUNIT_REPLAY_LEGACY_HOOK=1` | Forces in-process mode's PHPUnit 11.5 reflection fallback even on PHPUnit 12 or 13. |
| `CI` | Detected, not set by you. Gates whether a `run` may publish a branch baseline — see `--allow-ci-baseline`. |

A few more `PHPUNIT_REPLAY_*` variables exist purely so the wrapper can talk to the extension it
launches (run id, resolved root, resolved binary path). You should not need to set them by hand.

## Extension parameters

When phpunit-replay runs in-process, five settings can also come from the `<bootstrap>` block in
`phpunit.xml`, for projects that would rather keep configuration in one file:

```xml
<extensions>
    <bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension">
        <parameter name="mode" value="auto"/>
        <parameter name="stateDir" value=""/>
        <parameter name="remote" value=""/>
        <parameter name="remoteToken" value=""/>
        <parameter name="defaultBranch" value=""/>
    </bootstrap>
</extensions>
```

An absent parameter, or one whose value is the empty string, is treated as unset and falls back to
`phpunit-replay.php` and then to the defaults. The remaining keys have no parameter equivalent.

## Command options

`run` is the default command, so `vendor/bin/phpunit-replay` and `vendor/bin/phpunit-replay run`
are the same thing. Anything after a literal `--`, or the first token phpunit-replay does not
recognise, is forwarded to `vendor/bin/phpunit` untouched.

### `run`

| Option | Effect |
|---|---|
| `--fresh` | Ignore any cached baseline and record a fresh one. |
| `--no-remote` | Never contact a configured remote for this run. |
| `--explain` | Print which rule selected each test file, and why. |
| `--dry-run` | Print what would run without running it. Implies `--explain`. |
| `--log-junit=FILE` | Write a merged JUnit report — real results plus replayed ones. |
| `--allow-ci-baseline` | Let a run detected as CI publish a branch baseline. Without it, a CI run never updates the stored baseline. |
| `--parallel` / `-p[=N]` | Run through Paratest instead of a single `phpunit` process. |

`run`'s exit code is always PHPUnit's own, even when phpunit-replay has to degrade to an
unassisted run.

### `record`

`--fresh` and `--parallel`/`-p[=N]`, same meanings.

Unlike `run`, `record` exits `2` if it has to degrade to a plain PHPUnit run — PHPUnit's own tests
may all have passed, but no baseline was written, and that is a failure of what `record` was asked
to do.

### `verify`

`--parallel`/`-p[=N]`, plus `[-- <phpunit args>]`.

Runs the full suite in record mode and compares every result against the one the cache holds. The
four figures it reports are explained in the [README](../README.md#keeping-the-cache-honest).

### `prune`

| Option | Effect |
|---|---|
| *(no flag)* | Prunes deleted test files, plus `--branches`. |
| `--flaky` | Clears the quarantine. |
| `--branches` | Removes baselines for branches git no longer knows. |
| `--all` | Deletes the whole state directory's contents. |
| `--remote --keep-months=N` | Garbage-collects the **remote** cache instead of local state: deletes object shards older than `N` months (default 3), except objects still referenced by a branch baseline. |
| `--squash` | Git backend only: rewrites the remote branch as a single orphan commit. Clients re-clone automatically. |

### `push` and `pull`

`push` publishes cached objects; `push --graph` also publishes the branch baseline. `pull` fetches
the branch baseline from the remote and stores it locally.

### `status`, `explain`, `baseline-path`

No options. `explain` takes one path argument.
