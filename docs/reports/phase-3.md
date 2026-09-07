# Fase 3 — informe (distribución)

Estado: **cerrada** (tag `v0.1.0`). Sobre las fases 1–2 se añaden: caché remota content-addressed (backends filesystem, HTTP y **repositorio git dedicado**), `push`/`pull`, replay por clave de contenido `k` entre máquinas, selección de baseline más cercana (`baseline_branches`, git-flow), cobertura fusionada (`--coverage-php`), `prune --remote`, workflows de GitHub Actions de ejemplo y CI del paquete (matriz PHP 8.2/8.3/8.4 × PHPUnit 11.5/12 × pcov/xdebug), compatibilidad real con PHPUnit 11.5.

## Qué se hizo

| Bloque | Ficheros clave | Notas |
|---|---|---|
| Remoto | `Cache/Remote/{RemoteCache,NullRemoteCache,FilesystemRemoteCache,HttpRemoteCache,GitRemoteCache,RemoteCacheFactory,ObjectStore}` | Claves `graph/<clave-compartida>/<rama>.json` y `objects/<aaaa-mm>/<k>.json`; objetos append-only; espejo local con caché de lectura. La clave remota (`ProjectKey::shared`) depende solo del origin, no del nombre del directorio. |
| Backend git | `GitRemoteCache` | Espejo shallow en el state dir; `begin()` refresca; `end()` = fetch + `reset --hard` a upstream + reescritura de lo escrito en la pasada + commit + push (sin rebase: los objetos nunca chocan, `graph/**` gana el nuestro); `flock`; re-clone si upstream fue reescrito. |
| Pipeline | `RunPipeline`, `ReplayState` (in-process también), `Change/BaselineResolver`, `Graph::setNearestBranch` | Sin grafo local → adopta `graph/<clave>/<rama>` remoto; fichero afectado cuya `k_now` existe en el remoto → `replayed (from remote)`; tras la pasada `putObject` por fichero ejecutado y `putGraph` con `remote_push=all`. Cadena de fallback `propia → más cercana → default` (las baselines de rama son deltas). `verify` y `--filter` nunca publican. |
| Comandos | `PushCommand`, `PullCommand`, `PruneCommand --remote [--keep-months] [--squash]`, `status` (`remote:`/`push:`) | |
| Cobertura | `Record/{PiggybackCoverageDriver,CoverageSnapshots}`, `Report/{CoverageMerger,NullCoverageDriver}`, `Console/Runner/CoveragePhpOption` | Con `--coverage-*` la extensión lee la cobertura por test que PHPUnit ya recoge (sin chocar con pcov crudo); snapshots `<stateDir>/coverage/<k>.cov`; fusión al fichero pedido; con 0 ejecutados se construye desde snapshots con un driver no-op. |
| CI | `.github/workflows/ci.yml`, `.github/workflows/examples/{tia-baseline,ci,tia-gc}.yml` | 10 celdas (8.2 excluido de PHPUnit 12). |
| Docs | `README.md` (reescrito), `docs/sharing-the-cache.md`, `docs/README.md`, `docs/SPEC.md`, `docs/reports/` | Sin fases en README; comparativa con enlaces; panorama de otros ecosistemas. |

Total: 132 ficheros en `src/` (16731 líneas), 96 clases de test.

## Gate de fase 3

| Requisito | Resultado |
|---|---|
| `composer validate --strict` | OK |
| `vendor/bin/phpstan analyse` (max, php 8.2) | `[OK] No errors` en PHPUnit 12.5.34 y 11.5.56 |
| Suite pcov (PHPUnit 12.5.34) | `Tests: 714, Assertions: 2527, Skipped: 3` |
| Suite PHPUnit 11.5.56 (paratest 7.8.5) | `Tests: 714, Skipped: 14` (paratest/laravel-lite ausentes en ese árbol), 0 fallos |
| Suite Xdebug | ver `composer test:xdebug` en el informe de cierre |
| Test "dos máquinas" | `TwoMachinesSharedCacheTest`, `InProcessRemoteCacheTest` (dos `HOME`, dos clones con distinto nombre, remoto `file://` compartido): la segunda hereda todo sin ejecutar nada |
| Backend git | `GitRemoteCacheTest` (dos clientes concurrentes, squash upstream, offline), `PruneRemoteCommandTest`, `PruneRemoteArgvTest` |
| Cobertura | `CoverageMergeTest` (incl. wrapper con `pcov.enabled=0`) |
| git-flow | `Scenario13GitFlowNearestBaselineTest`, `BaselineResolverTest` |

## Salida real

Dos clones (`m1`, `m2`) del mismo origin, `HOME` distinto por máquina, `php bin/phpunit-replay` por subproceso. Script: `scratchpad/walk3.sh`. Salida íntegra:

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

Lecturas:
- **A** (carpeta compartida): la máquina 2, sin grafo local, hereda la baseline y los 35 resultados (`35 replayed (35 from remote)`) sin arrancar PHPUnit; tras editar `Money.php` en la máquina 2 (31 ejecutados, objetos publicados), la misma edición en la máquina 1 se sirve del remoto (`31 from remote`, 0 ejecutados).
- **B** (repositorio git): idéntico con un repo bare como remoto; historial `replay: init` + `replay: +8 objects`; `prune --remote --squash` deja un único commit.
- **C** (cobertura): la pasada sin cambios no ejecuta nada y `cov2.php` se construye solo con snapshots (97,59 % de líneas, los 5 ficheros de `src/`).
- **D** (git-flow): con `baseline_branches=develop,main` una feature cortada de `develop` usa `develop@…` y un hotfix cortado de `main` usa `main@…`, ambos "0 files away".

## Qué quedó fuera y por qué

- **Heurística de hermeticidad** (`hermeticity_heuristics`): la clave existe; el marcado de "sospechosos" en `status` (aristas a `Carbon/`, `Faker/`, `Http/Client` sin fake) no está implementado.
- **`HttpRemoteCache::keys()`**: HTTP no tiene listado genérico → `prune --remote` requiere backend `file` o `git`.
- **Cobertura en `verify`/`--filter`**: no se redirige ni fusiona `--coverage-php` en esos modos.
- **Cobertura de tests risky/incomplete/skipped**: PHPUnit no la anexa, los snapshots no la tienen.
- **CI real**: los workflows de ejemplo se validaron como YAML; la matriz propia del paquete se ejecuta en GitHub Actions con cada push a `main`.

## Cómo probarlo en un proyecto real

Además de los pasos del README ("Trying it on your project") y de `docs/sharing-the-cache.md`:

1. Crea un repo vacío `org/proyecto-replay-cache`; en `phpunit-replay.php`: `'remote' => 'git@github.com:org/proyecto-replay-cache.git', 'remote_push' => 'objects'`; en CI `PHPUNIT_REPLAY_REMOTE_PUSH=all` con deploy key.
2. `vendor/bin/phpunit-replay record && vendor/bin/phpunit-replay push --graph` una vez (o deja que lo haga el job de baseline).
3. En otro clon/máquina: `vendor/bin/phpunit-replay` → debe decir `N replayed (N from remote)` sin ejecutar nada; `status` muestra `remote: git …` y `push:`.
4. git-flow: `'baseline_branches' => ['develop', 'main']`; en una feature `status` debe mostrar `baseline develop@… (nearest, …)`.
5. Cobertura: `vendor/bin/phpunit-replay record -- --coverage-php=build/cov.php`, luego `vendor/bin/phpunit-replay -- --coverage-php=build/cov.php` con 0 ejecutados sigue produciendo el fichero completo.
6. Mantenimiento: `vendor/bin/phpunit-replay prune --remote --keep-months=3 --squash` (job mensual `tia-gc.yml`).
