# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

- Fixed: correctness — re-recording a subset of tests (`run`, `verify`, in-process replay) could silently drop a test's dependency edge instead of keeping it. A coverage driver attributes a file's declaration footprint (class/enum/const, or any top-level statement) to whichever test happened to load it first in that process, so a partial re-record whose attribution differed from a previous, complete one could make a test's content key stop including a file it still genuinely depends on — a false green: editing that file no longer changed the key, so the stale cached result kept being replayed instead of the test ever running again. `GraphUpdater::apply()` now unions a re-record's edges into the graph (`Graph::unionEdges()`) instead of replacing them (`Graph::replaceEdges()`, still used to seed/replace a graph's state directly); a genuinely stale edge is shed by the next full, fresh `record`, which always starts from an empty graph. Laravel's per-test table tracking (`replaceTestTables`) is unaffected — it comes from a live query listener re-attributed on every run, not a load-once coverage artifact.

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
