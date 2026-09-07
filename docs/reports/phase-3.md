# Phase 3 — development report (distribution)

Status: **closed** (tag `v0.1.0`). On top of phases 1–2, the following are added: content-addressed remote cache (filesystem, HTTP, and **dedicated git repository** backends), `push`/`pull`, replay by content key `k` across machines, nearest-baseline selection (`baseline_branches`, git-flow), merged coverage (`--coverage-php`), `prune --remote`, example GitHub Actions workflows, and package CI (PHP 8.2/8.3/8.4 × PHPUnit 11.5/12 × pcov/xdebug matrix), real compatibility with PHPUnit 11.5.

## What was built

| Block | Key files | Notes |
|---|---|---|
| Remote | `Cache/Remote/{RemoteCache,NullRemoteCache,FilesystemRemoteCache,HttpRemoteCache,GitRemoteCache,RemoteCacheFactory,ObjectStore}` | Keys `graph/<shared-key>/<branch>.json` and `objects/<yyyy-mm>/<k>.json`; append-only objects; local mirror with a read cache. The remote key (`ProjectKey::shared`) depends only on the origin, not on the directory name. |
| Git backend | `GitRemoteCache` | Shallow mirror in the state dir; `begin()` refreshes it; `end()` = fetch + `reset --hard` to upstream + rewrite of what was written during the run + commit + push (no rebase: objects never collide, `graph/**` uses ours); `flock`; re-clone if upstream was rewritten. |
| Pipeline | `RunPipeline`, `ReplayState` (in-process too), `Change/BaselineResolver`, `Graph::setNearestBranch` | No local graph → adopts the remote `graph/<key>/<branch>`; an affected file whose `k_now` exists remotely → `replayed (from remote)`; after the run, `putObject` per executed file and `putGraph` with `remote_push=all`. Fallback chain `own → nearest → default` (branch baselines are deltas). `verify` and `--filter` never publish. |
| Commands | `PushCommand`, `PullCommand`, `PruneCommand --remote [--keep-months] [--squash]`, `status` (`remote:`/`push:`) | |
| Coverage | `Record/{PiggybackCoverageDriver,CoverageSnapshots}`, `Report/{CoverageMerger,NullCoverageDriver}`, `Console/Runner/CoveragePhpOption` | With `--coverage-*` the extension reads the per-test coverage that PHPUnit already collects (without colliding with raw pcov); snapshots `<stateDir>/coverage/<k>.cov`; merged into the requested file; with 0 executed it is built from snapshots with a no-op driver. |
| CI | `.github/workflows/ci.yml`, `.github/workflows/examples/{tia-baseline,ci,tia-gc}.yml` | 10 cells (8.2 excluded from PHPUnit 12). |
| Docs | `README.md` (rewritten), `docs/sharing-the-cache.md`, `docs/README.md`, `docs/SPEC.md`, `docs/reports/` | No phases in the README; comparison with links; overview of other ecosystems. |

Total: 132 files in `src/` (16731 lines), 96 test classes.

## Phase 3 gate

| Requirement | Result |
|---|---|
| `composer validate --strict` | OK |
| `vendor/bin/phpstan analyse` (max, php 8.2) | `[OK] No errors` on PHPUnit 12.5.34 and 11.5.56 |
| pcov suite (PHPUnit 12.5.34) | `Tests: 714, Assertions: 2527, Skipped: 3` |
| PHPUnit 11.5.56 suite (paratest 7.8.5) | `Tests: 714, Skipped: 14` (paratest/laravel-lite absent on that tree), 0 failures |
| Xdebug suite | see `composer test:xdebug` in the closing report |
| "Two machines" test | `TwoMachinesSharedCacheTest`, `InProcessRemoteCacheTest` (two `HOME`s, two clones with different names, shared `file://` remote): the second inherits everything without executing anything |
| Git backend | `GitRemoteCacheTest` (two concurrent clients, upstream squash, offline), `PruneRemoteCommandTest`, `PruneRemoteArgvTest` |
| Coverage | `CoverageMergeTest` (incl. wrapper with `pcov.enabled=0`) |
| git-flow | `Scenario13GitFlowNearestBaselineTest`, `BaselineResolverTest` |

## Real output

Two clones (`m1`, `m2`) of the same origin, a different `HOME` per machine, `php bin/phpunit-replay` as a subprocess. Script: `scratchpad/walk3.sh`. Full output:

```
############ A. two machines, shared folder remote (file://), remote_push=all
[m1] $ phpunit-replay record
Tests: 35, Assertions: 61, Skipped: 1.
Replay  ● recorded 35 tests in 7 test files · 12 source files · 18 edges · graph.json 6 KB · baseline main@8e3c1e3 · 0s

[m2] $ phpunit-replay        (fresh machine, no local graph)
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed (35 from remote) · 0 quarantined · baseline main@8e3c1e3

[m2] $ phpunit-replay        (after editing src/Money.php)
Tests: 31, Assertions: 53, Skipped: 1.
Replay  ✓ 31 executed (31 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline main@8e3c1e3

[m1] $ phpunit-replay        (same edit → results already in the remote)
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed (31 from remote) · 0 quarantined · baseline main@8e3c1e3

[m1] $ phpunit-replay status | grep -E "remote|push"
remote:    file file://<tmp>/shared-remote
push:      all

# remote tree:
graph/p-bd59392561f15310/main.json
objects/2026-09/140d2fc516c27f85b5bde522437d4293.json
objects/2026-09/30731e1fd57506b8b0d9f4d62e94c898.json
objects/2026-09/30e2dbf2b35471cb5ff0b1708b7af74f.json
objects/2026-09/3e76defbb68baeff99aeacd1cb81e857.json
objects/2026-09/636dd359041fe84331e869d3671fb7b2.json
objects/2026-09/69fe0d5b463721c2e51a486bc5120653.json
objects/2026-09/8d453ad52cd2278135a06bc894469e77.json
objects/2026-09/8e0154e32b293ac06a37e137a11c8b67.json
objects/2026-09/911ef9b24bdf6489d5b25d878bf4a42c.json
objects/2026-09/a5445f19a1b79b58d7345f0a81859d6d.json
objects/2026-09/b9bcc1de32dfbff353fef78a6993b6bb.json
objects/2026-09/d4137586d75b1a15726c1135de6d6eea.json
objects/2026-09/e3608e093208d551516b714cf4c949e1.json

############ B. dedicated git repository as remote (bare repo), fresh machines
[m1] $ phpunit-replay record
Replay  ● recorded 35 tests in 7 test files · 12 source files · 18 edges · graph.json 6 KB · baseline main@8e3c1e3 · 0s

[m2] $ phpunit-replay        (fresh machine → pulls baseline + objects from the cache repo)
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed (35 from remote) · 0 quarantined · baseline main@8e3c1e3

$ git -C cache.git log --oneline
d2bebf3 replay: +8 objects
65e9dee replay: init

$ git -C cache.git ls-tree -r --name-only HEAD | head
graph/p-bd59392561f15310/main.json
objects/2026-09/30731e1fd57506b8b0d9f4d62e94c898.json
objects/2026-09/3e76defbb68baeff99aeacd1cb81e857.json
objects/2026-09/636dd359041fe84331e869d3671fb7b2.json
objects/2026-09/69fe0d5b463721c2e51a486bc5120653.json
objects/2026-09/911ef9b24bdf6489d5b25d878bf4a42c.json
objects/2026-09/b9bcc1de32dfbff353fef78a6993b6bb.json
objects/2026-09/e3608e093208d551516b714cf4c949e1.json

[m1] $ phpunit-replay prune --remote --keep-months=3 --squash
remote prune: removed 0 object(s), kept 0 referenced object(s)
remote prune: squashed remote history
$ git -C cache.git rev-list --count HEAD
1

############ C. coverage with replay (--coverage-php)
[m1] $ phpunit-replay record -- --coverage-php=cov.php
Generating code coverage report in PHP format ... done [00:00]
Replay  ● recorded 35 tests in 7 test files · 5 source files · 11 edges · graph.json 6 KB · baseline main@8e3c1e3 · 0s

[m1] $ phpunit-replay -- --coverage-php=cov2.php      (nothing executed)
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed · 0 quarantined · baseline main@8e3c1e3

# text summary of cov2.php:
 Summary:
  Classes: 60.00% (3/5)
  Methods: 93.94% (31/33)
  Lines:   97.59% (81/83)

App\Cart
  Methods: 100.00% ( 7/ 7)   Lines: 100.00% ( 23/ 23)
App\Discount
  Methods: 100.00% ( 5/ 5)   Lines: 100.00% ( 13/ 13)
App\Greeter
  Methods: 100.00% ( 2/ 2)   Lines: 100.00% ( 11/ 11)
App\Money

############ D. git-flow: baseline_branches=develop,main
[m1 main] $ phpunit-replay record
Replay  ● recorded 35 tests in 7 test files · 12 source files · 18 edges · graph.json 6 KB · baseline main@8e3c1e3 · 0s

[m1 develop] $ phpunit-replay        (31 affected by the develop commit)
Replay  ✓ 31 executed (31 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline develop@bf92cc6

[m1 feature/x from develop] $ phpunit-replay --dry-run
baseline develop@bf92cc6 (nearest, 0 files away)
Replay  0 test files would run (0 affected, 0 uncached, 0 quarantined), 35 tests would replay

[m1 hotfix/y from main] $ phpunit-replay --dry-run
baseline main@8e3c1e3 (nearest, 0 files away)
Replay  0 test files would run (0 affected, 0 uncached, 0 quarantined), 35 tests would replay
```

Readings:
- **A** (shared folder): machine 2, with no local graph, inherits the baseline and the 35 results (`35 replayed (35 from remote)`) without starting PHPUnit; after editing `Money.php` on machine 2 (31 executed, objects published), the same edit on machine 1 is served from the remote (`31 from remote`, 0 executed).
- **B** (git repository): identical with a bare repo as the remote; history `replay: init` + `replay: +8 objects`; `prune --remote --squash` leaves a single commit.
- **C** (coverage): the run with no changes executes nothing and `cov2.php` is built solely from snapshots (97.59% of lines, the 5 files in `src/`).
- **D** (git-flow): with `baseline_branches=develop,main` a feature branch cut from `develop` uses `develop@…` and a hotfix cut from `main` uses `main@…`, both "0 files away".

## What was left out, and why

- **Hermeticity heuristic** (`hermeticity_heuristics`): the key exists; flagging "suspicious" items in `status` (edges to `Carbon/`, `Faker/`, `Http/Client` without a fake) is not implemented.
- **`HttpRemoteCache::keys()`**: HTTP has no generic listing → `prune --remote` requires the `file` or `git` backend.
- **Coverage in `verify`/`--filter`**: `--coverage-php` is neither redirected nor merged in those modes.
- **Coverage for risky/incomplete/skipped tests**: PHPUnit does not attach it, so the snapshots don't have it.
- **Real CI**: the example workflows were validated as YAML; the package's own matrix runs on GitHub Actions on every push to `main`.

## Trying it on a real project

In addition to the steps in the README ("Trying it on your project") and in `docs/sharing-the-cache.md`:

1. Create an empty repo `org/proyecto-replay-cache`; in `phpunit-replay.php`: `'remote' => 'git@github.com:org/proyecto-replay-cache.git', 'remote_push' => 'objects'`; in CI `PHPUNIT_REPLAY_REMOTE_PUSH=all` with a deploy key.
2. `vendor/bin/phpunit-replay record && vendor/bin/phpunit-replay push --graph` once (or let the baseline job do it).
3. On another clone/machine: `vendor/bin/phpunit-replay` → should say `N replayed (N from remote)` without executing anything; `status` shows `remote: git …` and `push:`.
4. git-flow: `'baseline_branches' => ['develop', 'main']`; on a feature branch `status` should show `baseline develop@… (nearest, …)`.
5. Coverage: `vendor/bin/phpunit-replay record -- --coverage-php=build/cov.php`, then `vendor/bin/phpunit-replay -- --coverage-php=build/cov.php` with 0 executed still produces the full file.
6. Maintenance: `vendor/bin/phpunit-replay prune --remote --keep-months=3 --squash` (monthly job `tia-gc.yml`).
