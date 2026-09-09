# Sharing the cache with your team

By default every machine — your laptop, a coworker's, each CI runner — keeps its own
`graph.json` under `~/.phpunit-replay/<project-key>/`, so each of them re-records affected test
files from a cold start the first time they see a given commit. Phase 3 adds a **remote cache**:
a content-addressed object store that any of these machines can push results to and pull results
from, so the work one of them already did — a laptop, a previous PR's CI run, the `main` baseline
job — is inherited instead of repeated.

Nothing here is required. `phpunit-replay` works fully locally with `remote` left at `null` (the
default); read this guide when re-recording the same test files on every machine and every CI job
starts costing more than it's worth.

## The five options, compared

| | Local only | Shared folder (`file://`) | HTTP (S3/MinIO, nginx WebDAV) | Dedicated git repository | CI artifacts |
|---|---|---|---|---|---|
| Prerequisites | none | a mounted path all machines can reach (NFS, `rclone mount`, a shared volume) | an HTTP endpoint with GET/PUT/HEAD (S3 presigned URLs, MinIO, nginx with `dav_methods`) | an empty git repo + a deploy key for CI | none — built into GitHub Actions |
| What's shared | nothing | `objects/**` and, from CI, `graph/**` | same | same, versioned in git history | the whole state directory, keyed by branch |
| Who writes what (`remote_push`) | — | whoever mounts it; `remote_push` still governs objects-only vs. objects+graph | same, gated by the bearer token | CI (`remote_push: all`) publishes baselines; everyone else defaults to `objects` (objects-only) | the job that ran `record`/`verify` |
| Failure behaviour | n/a | remote unreachable → warning to stderr, run continues local-only | same | same — an offline mirror still serves what it has | cache miss → that job does a fresh record |
| Size / GC | one `graph.json` per machine, no GC needed | grows unbounded; you delete under the mount by hand | same, or use your object store's lifecycle rules | ≈ 1–20 KB per test file per content version, monthly shards; `prune --remote --keep-months=N [--squash]` | governed by GitHub's own cache size/eviction limits |
| Best for | solo projects, evaluating the package | one office/VPN, or a CI runner class with a persistent disk | teams already running object storage | teams with git/GitHub but no object storage — no infrastructure to run | GitHub-only projects that want zero extra infrastructure |

None of these can ever break your test run. A remote that's down, unreachable, or misconfigured
always degrades to a warning on stderr and a local-only pass — this is the same rule the rest of
the package follows for every other internal failure (see [Known
limitations](../README.md#known-limitations)). `--no-remote` disables the remote for one run;
setting `remote` back to `null` disables it permanently. Environment variables always win over
`phpunit-replay.php`: `PHPUNIT_REPLAY_REMOTE`, `PHPUNIT_REPLAY_REMOTE_TOKEN`,
`PHPUNIT_REPLAY_REMOTE_PUSH`, `PHPUNIT_REPLAY_BASELINE_BRANCHES`.

Relevant config keys, all optional: `remote`, `remote_token`, `remote_push`
(`objects`|`all`|`off`, default `objects`), `remote_branch` (git backend, default `main`),
`remote_refresh_seconds` (git backend, default 300), `remote_timeout` (git backend, default 60),
`baseline_branches`, `default_branch`.

**Test ids are the cache keys.** Every shared object (`objects/<yyyy-mm>/<k>.json`) and every
published `graph/**` baseline stores its results in a dictionary keyed by PHPUnit's own test id
(`Class::method`, or `Class::method with data set "…"` for a data provider case) — a machine
pulling one of those only finds a given test's result if it computes that exact same id itself. A
data provider whose dataset name embeds something machine-specific — most commonly an absolute
filesystem path, e.g. a dataset built from `glob()`/`scandir()`/`realpath()` over an absolute
directory — produces a different id on every machine and in CI, so that one test's result can
never be found by anyone but the machine that recorded it: not a defect in phpunit-replay, but a
real sharp edge that silently degrades remote-cache sharing to a local cold start for exactly the
tests whose dataset names aren't stable across machines. Keep dataset names relative and
deterministic (a fixture's basename, an index) if you want their results to actually travel.

## Setup: local only

Nothing to do — this is the default. Skip straight to [CI in two
lanes](../README.md#ci-in-two-lanes) if that's all you need.

## Setup: shared folder (`file://`)

Any path every machine can read and write (NFS mount, `rclone mount`, a shared Docker volume)
works. `phpunit-replay.php`:

```php
<?php
return [
    'remote' => 'file:///mnt/replay-cache',
];
```

Make sure the directory exists and is writable by whichever user/CI runner writes to it —
`phpunit-replay` creates the `objects/` and `graph/` subpaths itself the first time it writes, but
won't create the mount point. There's no built-in garbage collection for this backend; when the
folder gets too large, delete old entries under `objects/` by hand (they're plain files, safe to
remove — a missing object is just a cache miss, never an error).

## Setup: HTTP (S3/MinIO, nginx WebDAV)

The backend speaks plain GET/PUT/HEAD, so anything that answers those verbs works: an S3 bucket
behind presigned URLs, a MinIO instance, or nginx configured with `dav_methods PUT`.
`phpunit-replay.php`:

```php
<?php
return [
    'remote' => 'https://cache.example.com/replay/',
    'remote_token' => getenv('PHPUNIT_REPLAY_REMOTE_TOKEN') ?: null,
];
```

Minimal nginx snippet for a self-hosted option:

```nginx
location /replay/ {
    root /var/www;
    dav_methods PUT DELETE;
    create_full_put_path on;
    client_max_body_size 20m;
    # add bearer-token auth in front of this (auth_request, or a reverse proxy) —
    # phpunit-replay only sends the token, it doesn't set up the server side.
}
```

In CI, keep the token in a secret and pass it as `PHPUNIT_REPLAY_REMOTE_TOKEN` — see
`.github/workflows/examples/ci.yml`. Like the filesystem backend, there's no automatic GC; rely on
your object store's lifecycle/expiration rules (an S3 lifecycle policy on the `objects/` prefix is
the usual choice).

## Setup: dedicated git repository (recommended default when you have neither)

This is the backend to reach for when there's no S3/MinIO and no shared filesystem, but the team
is already on GitHub (or any git host). It needs **its own** repository — never the project's own
code repo, whose `graph.json`-adjacent objects would churn on every single pass and collide
constantly with real commits.

1. **Create an empty repository**, e.g. `org/project-replay-cache`. It doesn't need any initial
   content — the first `push --graph` from CI creates `graph/` and `objects/` in it.
2. **Give CI a deploy key with write access** to that repository (GitHub: repo Settings → Deploy
   keys → Add deploy key, tick "Allow write access"). Store the private key as a repository secret,
   e.g. `REPLAY_CACHE_DEPLOY_KEY`, and load it with
   [`webfactory/ssh-agent`](https://github.com/webfactory/ssh-agent) — see the deploy-key step in
   `.github/workflows/examples/tia-baseline.yml`, `examples/ci.yml`, and `examples/tia-gc.yml`.
3. **Developers use their own SSH access** — nothing extra to configure locally beyond whatever
   git setup already lets them clone/push other repos on the same host. Give them **read** access
   to the cache repo.

   Be clear about what enforces what: **`remote_push` is client-side self-restraint, not access
   control.** It is a value in a config file the developer controls, so anyone holding a write
   credential can publish whatever their client is configured to publish. Repo permissions are the
   only thing that actually enforces the boundary. And on a git backend those permissions are
   per-repository, never per-path, so "may write `objects/**` but not `graph/**`" is not
   expressible — that distinction only exists on the HTTP backend (prefix-scoped bucket policies)
   or the filesystem backend (directory permissions).
4. **`phpunit-replay.php`:**

   ```php
   <?php
   return [
       'remote' => 'git@github.com:org/project-replay-cache.git',
       'remote_push' => getenv('CI') ? 'objects' : 'off',
       'remote_branch' => 'main',
       'baseline_branches' => ['develop', 'main'],
   ];
   ```

   **Nothing here needs `all`.** `push --graph` publishes the branch baseline on the strength of
   its own flag and is not gated by `remote_push` at all, so the baseline job's explicit
   `push --graph` is what writes `graph/**` — an operator action, in one job, by name. Setting
   `all` instead would make *every* CI job publish a branch graph automatically, because the
   automatic graph publish checks `remote_push` and `--allow-ci-baseline` does **not** guard it
   (that flag guards the *local* baseline only).

   So the three roles come out as: developers `off`; every CI job `objects`, which publishes
   `objects/<month>/<k>.json` — test-file results keyed by content — and can never publish a
   graph; and the baseline job additionally running `push --graph`.

   This is the flow that makes a read-only team work. A developer writes a new test and runs it
   locally, where it executes for real because it is new to the graph — which is what you want for
   a test you just wrote. The PR job then runs it and publishes its object, so from that point the
   whole team replays it, without waiting for the merge. The graph catches up at the next baseline
   run.

5. **First `push --graph` from CI.** The very first time the baseline workflow
   (`tia-baseline.yml`) runs `phpunit-replay run --allow-ci-baseline` followed by
   `phpunit-replay push --graph`, it populates `graph/<project-key>/main.json` and the first batch
   of `objects/<yyyy-mm>/<k>.json` in the cache repo. Nothing needs to exist there beforehand.
6. **A new developer's first run** pulls that baseline automatically:

   ```
   $ vendor/bin/phpunit-replay
   Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed (35 from remote) · 0 quarantined · baseline main@a1b2c3d
   ```

   `(35 from remote)` means all 35 results came from the shared cache rather than a local
   recording — a brand-new checkout on a machine that has never run the suite still gets a
   near-instant first pass.
7. **Maintenance** runs on a schedule via `.github/workflows/examples/tia-gc.yml`
   (`phpunit-replay prune --remote --keep-months=3 --squash`), monthly by default.
8. **Rebase / force-push behaviour.** `objects/**` entries are append-only and content-addressed —
   two machines writing the same key at the same time is a no-op, never a conflict. `graph/**`
   writes use "keep ours" on a rebase (the objects underneath are unaffected either way). When the
   GC job squashes the branch into a single orphan commit and force-pushes it, every client
   notices on its next `begin()` (the start of the next push or pull): a fetch reporting
   unrelated/rewritten history makes it wipe its local mirror and re-clone, automatically, with a
   warning — no manual step on anyone's machine.
9. **A failed push never loses data.** Publishing an object is two steps for this backend: stage
   it in the local mirror, then `push` it. If the push itself fails — a network blip, an expired
   credential, a rejected fast-forward that could not be resolved after retrying — the object is
   never marked as published locally, regardless of how the staging step went. The next `run` (or
   `phpunit-replay push`) simply tries again; retrying an already-published, content-addressed
   object is the no-op described above, so nothing is ever pushed twice for real, and nothing is
   ever silently dropped because of a failure that looked like it belonged to a different object.
10. **Sizes.** Each object is small — roughly 1–20 KB per test file per distinct content version
    (bigger test files or ones with more source dependencies land at the high end). Objects are
    sharded by the month they were written (`objects/2026-09/<k>.json`, ...), and
    `prune --remote --keep-months=3` (the `tia-gc.yml` default) drops shards older than that window
    except for any object still referenced by a current `graph/**` baseline — so the retained
    history stays bounded regardless of how long the project lives.

## Git-flow branching (`baseline_branches`)

Teams working on `develop` and releasing through `main` want a feature branch cut from `develop`
to inherit `develop`'s baseline, and a hotfix branch cut from `main` to inherit `main`'s — not
always fall back to the same single default branch. Configure an ordered list of candidates:

```php
<?php
return [
    'baseline_branches' => ['develop', 'main'],
];
```

(env equivalent: `PHPUNIT_REPLAY_BASELINE_BRANCHES=develop,main`). On a branch with no baseline of
its own, `phpunit-replay` walks the candidates in order, keeps only the ones whose recorded sha is
an ancestor of `HEAD`, and picks whichever is **fewest files different** from the current tree —
ties broken by list order. `default_branch` still works as shorthand for a single-candidate list;
when both are set, `baseline_branches` wins. `status` and `--explain` report which one was chosen:
`baseline develop@abc1234 (nearest, 3 files away)`.

Recommended CI: both `develop` and `main` run `phpunit-replay run --allow-ci-baseline` followed by
`push --graph` after every merge (same shape as `tia-baseline.yml`, just triggered on both
branches) — only structural fingerprint drift (`composer.lock`, `phpunit.xml`,
`phpunit-replay.php`) should ever force a full `record --fresh`.

## Setup: CI artifacts

No object storage, no dedicated repository, no secrets beyond what GitHub Actions already gives
you: cache the state directory itself between runs of the same branch with `actions/cache`, keyed
on `phpunit-replay baseline-path` (the directory that would otherwise live under
`~/.phpunit-replay/...`, printed without hard-coding that path):

```yaml
- id: state-dir
  run: echo "path=$(vendor/bin/phpunit-replay baseline-path)" >> "$GITHUB_OUTPUT"
- uses: actions/cache@v4
  with:
    path: ${{ steps.state-dir.outputs.path }}
    key: phpunit-replay-${{ github.ref_name }}
    restore-keys: |
      phpunit-replay-main
```

See the commented-out `full-with-artifact-cache` job in `.github/workflows/examples/ci.yml` for
the complete version, save step included. This needs neither `remote` nor any of the
`remote_*`/`PHPUNIT_REPLAY_REMOTE*` configuration — it's a substitute for the remote cache, not a
sixth configuration of it. A cache miss (first run on a new branch, or after GitHub evicts an
old entry) just costs that one job a full record, same as any other cold start.

## Troubleshooting

- **`PHPUNIT_REPLAY_DEBUG=1 vendor/bin/phpunit-replay`** prints every decision to stderr, remote
  lookups included — which key was requested, whether it hit, and what the backend reported on
  failure.
- **`phpunit-replay status`** prints a `remote:` line showing the configured backend, whether it
  was reachable on the last run, and (git backend) when the local mirror was last refreshed.
- **`--no-remote`** disables the remote for a single `run`/`record`/`verify` invocation without
  touching the config — useful to confirm a slow or flaky run is remote-related.
- **`phpunit-replay pull`** fetches the graph for the current branch (falling back through
  `baseline_branches`/`default_branch`) and stores it locally as the baseline, without running
  anything — the fastest way to force a stale local mirror to catch up, or to recover after
  `--fresh` was used by mistake.
