# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

- Fix `run --dry-run` executing the full suite instead of printing the plan: `RunPipeline::runRecord()` (no cached baseline yet), `degrade()` (the single funnel for every "fall back to a plain PHPUnit run" case — no coverage driver, environment resolution failures, an unexpected exception) and `runResultsOnly()` (a partial CLI selection: `--filter`/`--group`/`--testsuite`/an explicit path) now honour `--dry-run` themselves instead of only the replay branch, printing a plan line to stdout and exiting 0 without ever launching PHPUnit or writing state.
- Fix `verify` silently ignoring `--parallel`/`-p[=N]`: the option was never declared on the command and the argv splitter forwarded it straight through to PHPUnit as a passthrough argument; `RunPipeline::verify()` also constructed a `PhpunitProcess` directly instead of going through the branch that picks `PhpunitProcess`/`ParatestProcess`. `verify` now supports `--parallel`/`-p[=N]` exactly like `run`/`record` and actually runs through Paratest — the difference that matters most, since `verify` runs the full suite and is the command the README recommends as the PR merge gate.
- **Data-integrity fix**: `verify` carrying a partial CLI selection (`--filter`/`--group`/`--testsuite`/an explicit path) unconditionally rewrote the graph's edges from a run that never covered the suite, pruned every sibling result the selection excluded, and published a baseline sha for a partial run — reported from a real 9056-test suite as two identical back-to-back `verify` runs claiming "would replay" of 6304 and then 9056. `RunPipeline::verify()` now derives `recordsEdges` from `hasPartialSelection()`, the same guarantee `run`'s results-only path already gave a partial selection. The same fix corrects the "would replay" counter itself, which iterated the whole cached corpus instead of the tests this run actually executed — an untouched entry trivially matched itself, so the figure could exceed the "N tests" printed beside it and disagreed between two otherwise-identical runs; it now iterates only `$partial->results`.
- `record` now exits `2` when it degrades to a plain PHPUnit run and that run itself reported success (exit `0`): a degrade never writes a graph or even the state directory, so forwarding PHPUnit's own "0" straight through made a `record` that recorded nothing indistinguishable from one that succeeded (reported: a removed PHPUnit method degraded a CI `record` step, which then ran all 9056 tests, printed a normal summary, and exited 0 with nothing on disk — noticed only by manually checking for the state directory). `run` is unaffected: its exit code is still PHPUnit's own regardless of whether it degrades, per the documented "never change PHPUnit's exit code" guarantee, since unlike `record`, `run`'s exit code is the actual pass/fail signal CI gates on.

## [0.1.0] — 2026-09-07

- Remote cache, content-addressed: filesystem (`file://`), HTTP (S3/MinIO presigned, WebDAV) and a dedicated git repository backend (`GitRemoteCache`: shallow mirror, append-only objects, reset+rewrite reconciliation, `prune --remote [--keep-months] [--squash]`).
- `push [--graph]`, `pull`; replay by content key across machines (`N replayed (R from remote)`), also in in-process mode; `remote_push` policy (objects|all|off); CI never publishes a baseline without `--allow-ci-baseline`.
- Nearest-baseline selection for git-flow: `baseline_branches`, fallback chain own → nearest → default.
- Coverage with replay: `--coverage-php=FILE` records per-test-file snapshots (piggyback on PHPUnit's coverage) and merges them for replayed files; works with nothing executed.
- Summary distinguishes `not cacheable` from `quarantined`; remote project key independent of the checkout directory name.
- PHPUnit 11.5 compatibility verified (configuration reader, replay hook via reflection); package CI matrix PHP 8.2–8.4 × PHPUnit 11.5/12 × pcov/xdebug; example workflows (`tia-baseline`, two-lane `ci`, `tia-gc`).
- Docs: README rewritten (problem-first, comparison, cross-language landscape), `docs/sharing-the-cache.md`.

## [0.1.0-beta1] — 2026-09-07 — phase 2

- In-process mode: `Manuglopez\Replay\PHPUnit\Replayable` trait / `ReplayableTestCase`; replay as pass with the original assertion count (PHPUnit 12 `invokeTestMethod()` hook, PHPUnit 11.5 reflection fallback); `#[Depends]` providers and failures never replayed.
- Commands `explain <path>`, `prune [--flaky|--branches|--all]`, `verify` (divergence.json, lifetime metric).
- Hermeticity: `#[NotCacheable]`, `never_cache` globs, automatic quarantine on result flips (`flaky.json`, `quarantine_release_after`).
- Laravel (auto-detected): query-listener table tracking, Blade view edges, RefreshDatabase files inherit all migration tables; Migration/Sibling/Blade selection rules; `laravel-lite` fixture.
- Paratest: `--parallel|-p[=N]` for `run`/`record`; worker partials merged.
- Summary counters count tests (`executed = affected + uncached + quarantined`); dry-run summary line.

## [0.1.0-alpha1] — 2026-09-06 — phase 1

- Filtered mode wrapper `vendor/bin/phpunit-replay` with `run` (default), `record`, `status`, `baseline-path`.
- Per-test-file dependency graph recorded with pcov or Xdebug (raw API), per-test results with assertion counts.
- Change detection: git diff since baseline sha + working tree + content-hash filter (comments/whitespace ignored) + last-run snapshot.
- Selection rules PhpEdge, TestFile, Watch (PHP/Laravel/Symfony defaults); unknown test files and cached failures always run.
- Structural/environmental fingerprint, per-branch baselines, atomic state writes, generated `.phpunit-replay.xml`.
- `--explain`, `--dry-run`, `--fresh`, `--log-junit` (merged JUnit with `replayed=true` properties).
- PHPUnit extension `Manuglopez\Replay\PHPUnit\ReplayExtension` (record / record-subset / results-only).
