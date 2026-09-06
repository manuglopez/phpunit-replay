# Changelog

All notable changes to this project will be documented in this file.

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
