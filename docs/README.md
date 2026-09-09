# Documentation index

Start with the project [README](../README.md) for what phpunit-replay does and how to use it.
This directory holds the full reference material, plus what is behind it: the original
specification, implementation contracts, deep dives, and development history.

| Document | What it's for |
|---|---|
| [configuration.md](configuration.md) | Complete reference: every `phpunit-replay.php` key, every environment variable, every extension parameter, and every command option. The README covers the handful you are likely to set; this covers all of them. |
| [SPEC.md](SPEC.md) | The original technical specification the package was built from — behaviour, data formats, and CLI surface as originally designed. |
| [INTERNALS.md](INTERNALS.md) | Working contract between components: class names, method signatures, and data shapes, for anyone modifying the package itself. Wins over SPEC.md on signatures; SPEC.md wins on behaviour. |
| [sharing-the-cache.md](sharing-the-cache.md) | Full guide to the remote cache: comparison of the five backends (local, shared folder, HTTP, dedicated git repository, CI artifacts), setup steps for each, `baseline_branches` for git-flow branching, and troubleshooting. |
| [proposals/remote-layout.md](proposals/remote-layout.md) | **Proposal, not implemented.** Scoping the remote object layout by generation and environment: `objects/<generation>/<environment>/<yyyy-mm>/<k>.json`. Closes the one way a shared cache can serve a wrong result — a content key excludes the environment and remote adoption never re-checks it — by making a cross-environment read unreachable rather than merely checked, and turns dead-generation purge from a full object scan into a directory delete. Includes the migration options and six open questions. |
| [reproducibility.md](reproducibility.md) | Measured answer to "is the dependency graph a function of the tree, or of the run?" on a real 9,056-test Laravel suite: what made two identical recording passes disagree, what was fixed, what is inherent to shared-process coverage attribution, and why that residual is a cache miss rather than a false green. Also the corrected cost of `static_declaration_edges`. |
| [spikes/in-process-replay.md](spikes/in-process-replay.md) | Findings from prototyping in-process replay against real PHPUnit 11.5/12 internals (the same `invokeTestMethod()` hook carries over unchanged to PHPUnit 13) — why the trait hooks `invokeTestMethod()` on 12 (and 13) and falls back to a reflection swap on 11.5, and what must never be replayed (`expectException()`, `#[Depends]` providers). |
| [reports/phase-1.md](reports/phase-1.md) | Development report: filtered-mode wrapper, the core dependency graph, and the `run`/`record`/`status`/`baseline-path` commands. |
| [reports/phase-2.md](reports/phase-2.md) | Development report: in-process replay, `explain`/`prune`/`verify`, hermeticity (`#[NotCacheable]`, quarantine), the Laravel integration, and Paratest support. |
| [reports/phase-3.md](reports/phase-3.md) | Development report: content-addressed remote cache (filesystem, HTTP, dedicated git repository), replay across machines, nearest-baseline selection, coverage merge, CI matrix. |
