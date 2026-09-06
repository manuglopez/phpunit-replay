# Fase 2 — informe

Estado: **cerrada** (tag `v0.1.0-beta1`). Sobre la fase 1 (filtered) se añaden: modo in-process (trait `Replayable`), comandos `explain`/`prune`/`verify`, hermeticidad (`#[NotCacheable]`, globs `never_cache`, cuarentena automática por flip, `divergence.json`), integración Laravel (tablas, Blade, reglas Migration/Sibling/Blade, fixture `laravel-lite`) y Paratest (`--parallel`).

## Qué se hizo

| Bloque | Ficheros clave | Notas |
|---|---|---|
| In-process | `PHPUnit/Replayable.php`, `ReplayableTestCase.php`, `PHPUnit/Decision/*`, `ReplayState::{bootInProcess,decide,persistInProcess}`, `Subscribers/{PersistInProcessOnExecutionFinished,PrintSummaryOnApplicationFinished}` | `runTest()` es private (D-004): hook `invokeTestMethod()` en PHPUnit 12, swap por reflexión en 11.5 (`PHPUNIT_REPLAY_LEGACY_HOOK=1` lo fuerza en 12 para probarlo). Spike en `docs/spikes/in-process-replay.md`. |
| Servicios compartidos | `Cache/{RunContext,BaselineWriter}`, `Select/{RunList,RunListBuilder}`, `Console/ExplainFormatter` | Extraídos de `RunPipeline`; wrapper y extensión usan la misma persistencia y la misma lista de ejecución. |
| Hermeticidad | `Attributes/NotCacheable`, `Record/NotCacheableCollector`, `Hermeticity/{Policy,Quarantine,DivergenceLog}`, `Support/Glob` | Flip = misma clave `k`, clase de estado distinta (D-033 excluye "falló → curado"). `flaky.json`, `divergence.json`. |
| Comandos | `Commands/{Explain,Prune,Verify}Command`, `Report/{VerifySummary,DryRunSummary}` | `verify` = suite completa en modo record + comparación con lo que se habría replayado. |
| Laravel | `Laravel/{TableExtractor,TableTracker,BladeTracker,BladeReferences,MigrationTables,LaravelDetector,LaravelIntegration,UsesDatabaseCollector}`, `Select/Rules/{Migration,Sibling,Blade}Rule`, `Subscribers/{ArmLaravelTrackersOnPrepared,FlushUsesDatabaseOnExecutionFinished}` | Sin dependencia `illuminate/*` en el paquete. Fixture `tests/Fixtures/Projects/laravel-lite` (Laravel 13.30, sqlite memoria, 3 migraciones, 2 modelos, 4 Feature, 3 vistas). |
| Paratest | `Console/Runner/ParatestProcess`, `RunWriter::pathFor`, `RunPartial::readMerged` | `brianium/paratest` 7.20 solo como dev-dep; workers escriben `worker-<TEST_TOKEN>-*.json`. |
| Contadores | `Report/Summary`, `RunPipeline::classifyExecuted` | Todos en tests: `executed = affected + uncached + quarantined` (D-041). |

Total: 117 ficheros en `src/` (12705 líneas), 81 clases de test. Decisiones D-018…D-043 en DECISIONS.md.

## Gate de fase 2

| Requisito | Resultado |
|---|---|
| `composer validate --strict` | OK |
| `vendor/bin/phpstan analyse` (max, php 8.2) | `[OK] No errors` |
| Suite pcov | `Tests: 599, Assertions: 1959, Skipped: 3` |
| Suite Xdebug (`XDEBUG_INI_DIR=… composer test:xdebug`) | `Tests: 599, Assertions: 1952, Skipped: 5` |
| Escenarios §15 | 1–12 completos (`Scenario10InProcessReplayTest`, `Scenario12QuarantinedTestAlwaysRunsTest` añadidos) |
| laravel-lite: migración → solo tests de esa tabla | `3 executed (3 affected) · 1 replayed` (los 3 con `RefreshDatabase`; `HomePageTest` replayado) — ver salida |
| laravel-lite: vista Blade → solo tests que la renderizan | `1 executed (1 affected) · 3 replayed` — ver salida |
| Paratest | `record -p 2` produce las mismas aristas que la grabación secuencial (`ParallelRunTest`) |

## Salida real

Fixtures copiados a tmp con `git init`, `HOME` aislado, `php bin/phpunit-replay` por subproceso. Salida íntegra:

```
############ A. plain fixture: explain / NotCacheable / flaky+quarantine / verify / prune
$ phpunit-replay record
Tests: 38, Assertions: 64, Skipped: 1.
Replay  ● recorded 38 tests in 9 test files · 14 source files · 21 edges · graph.json 7 KB · baseline main@d79a6f9 · 0s

$ phpunit-replay   (unchanged; NotCacheableTest must still run)
OK (2 tests, 2 assertions)
Replay  ✓ 2 executed (0 affected, 0 uncached) · 36 replayed · 2 quarantined · baseline main@d79a6f9

$ phpunit-replay explain src/Money.php
tests/CartTest.php                       ← PhpEdge  src/Money.php
tests/CommentedTest.php                  ← PhpEdge  src/Money.php
tests/DependsTest.php                    ← PhpEdge  src/Money.php
tests/DiscountTest.php                   ← PhpEdge  src/Money.php
tests/MoneyTest.php                      ← PhpEdge  src/Money.php
tests/TaxCalculatorTest.php              ← PhpEdge  src/Money.php

direct dependents: 6

$ phpunit-replay explain README.md

direct dependents: 0
no recorded test executes this file

$ phpunit-replay verify
Tests: 38, Assertions: 64, Skipped: 1.
Verify  ✓ 38 tests · 36 would replay · 0 divergences (lifetime: 0 in 1 runs)

$ FIXTURE_FLIP=1 phpunit-replay verify
Tests: 38, Assertions: 64, Failures: 1, Skipped: 1.
Verify  ✗ 38 tests · 36 would replay · 1 divergences (lifetime: 1 in 2 runs)
[exit=]

$ phpunit-replay   (FlakyTest quarantined → always runs)
Replay  ✓ 3 executed (0 affected, 1 uncached) · 35 replayed · 2 quarantined · baseline main@d79a6f9

$ phpunit-replay status
files:      14
test files: 9
edges:      21
tables:     0
graph.json: 7 KB

results:
  main                 complete   d79a6f9    38 results

fingerprint:
  structural:    composer_lock=4acce0fa692f2459e2ed9e17acaeade1 phpunit_xml=9afa8142347478a26ea1c4945e534b1a phpunit_xml_dist=null replay_config=null schema=1
  environmental: driver=pcov os=Linux php=8.4

quarantined: 1
  App\Tests\FlakyTest::testDependsOnAnExternalFlag  flips=1 stable=0 reason=divergence
not cacheable: 1 (1 files, 0 ids)
divergences: 1 in 2 verify runs

$ phpunit-replay prune --flaky
quarantine cleared (1 entries)

$ phpunit-replay
Replay  ✓ 2 executed (0 affected, 0 uncached) · 36 replayed · 2 quarantined · baseline main@d79a6f9

############ B. in-process fixture (trait Replayable, no wrapper)
$ php -d pcov.enabled=1 -d pcov.directory=$PWD vendor/bin/phpunit    (first run)
OK, but some tests were skipped!
Tests: 35, Assertions: 61, Skipped: 1.
Replay  ● recorded 35 tests in 7 test files · 13 source files · 25 edges · graph.json 6 KB · baseline main@5556b9b · 0s
setup-count=35

$ ... vendor/bin/phpunit    (second run, unchanged)
OK, but some tests were skipped!
Tests: 35, Assertions: 61, Skipped: 1.
Replay  ✓ 1 executed (0 affected, 1 uncached) · 34 replayed · 0 quarantined · baseline main@5556b9b
setup-count=1

$ PHPUNIT_REPLAY_LEGACY_HOOK=1 ... vendor/bin/phpunit    (reflection path)
Tests: 35, Assertions: 61, Skipped: 1.
Replay  ✓ 1 executed (0 affected, 1 uncached) · 34 replayed · 0 quarantined · baseline main@5556b9b

############ C. paratest
$ phpunit-replay record -p 2
OK, but some tests were skipped!
Tests: 38, Assertions: 64, Skipped: 1.
Replay  ● recorded 38 tests in 9 test files · 14 source files · 21 edges · graph.json 7 KB · baseline main@d79a6f9 · 1s

$ phpunit-replay -p 2
Replay  ✓ 2 executed (0 affected, 0 uncached) · 36 replayed · 2 quarantined · baseline main@d79a6f9

############ D. laravel-lite
$ phpunit-replay status | head -6
root:      <tmp>/laravel
branch:    main (default: main)
head:      9c350e4
state dir: <tmp>/home/.phpunit-replay/laravel-7583c52992ac0845
driver:    pcov (loaded, enabled per run)
framework: laravel

$ phpunit-replay record
[30;42mOK (4 tests, 8 assertions)[0m
Replay  ● recorded 4 tests in 4 test files · 27 source files · 67 edges · graph.json 2 KB · baseline main@9c350e4 · 0s

$ phpunit-replay
Replay  ✓ 0 executed (0 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline main@9c350e4

# edited database/migrations/2024_01_03_000000_create_comments_table.php
$ phpunit-replay --explain --dry-run
tests/Feature/PostJsonTest.php           ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)
tests/Feature/PostsIndexTest.php         ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)
tests/Feature/UserModelTest.php          ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)
Replay  3 test files would run (3 affected, 0 uncached, 0 quarantined), 1 tests would replay

$ phpunit-replay
[30;42mOK (3 tests, 6 assertions)[0m
Replay  ✓ 3 executed (3 affected, 0 uncached) · 1 replayed · 0 quarantined · baseline main@9c350e4

# edited resources/views/welcome.blade.php
$ phpunit-replay --explain --dry-run
tests/Feature/HomePageTest.php           ← PhpEdge  resources/views/welcome.blade.php
Replay  1 test files would run (1 affected, 0 uncached, 0 quarantined), 3 tests would replay

$ phpunit-replay
[30;42mOK (1 test, 2 assertions)[0m
Replay  ✓ 1 executed (1 affected, 0 uncached) · 3 replayed · 0 quarantined · baseline main@9c350e4

$ phpunit-replay explain database/migrations/2024_01_03_000000_create_comments_table.php
tests/Feature/PostJsonTest.php           ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)
tests/Feature/PostsIndexTest.php         ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)
tests/Feature/UserModelTest.php          ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)

direct dependents: 1
```

Lecturas:
- **A**: `NotCacheableTest` (2 tests) se ejecuta en cada pasada y se contabiliza en el hueco `quarantined` del resumen (el único que la spec reserva para "siempre se ejecuta"); `verify` limpio da 0 divergencias; con `FIXTURE_FLIP=1` detecta 1 divergencia, la mete en cuarentena y el histórico pasa a `1 in 2 runs`; `prune --flaky` la libera.
- **B**: in-process sin wrapper: PHPUnit ve 35 tests / 61 aserciones en ambas pasadas; la segunda ejecuta solo `DependsTest::testFirst` (proveedor de `#[Depends]`, nunca se replaya, D-020) y `.setup-count` pasa de 35 a 1: el guard `isReplaying()` ahorró el `setUp()` caro de los 34 replayados. La ruta por reflexión da lo mismo.
- **C**: Paratest: mismos números que la grabación secuencial.
- **D**: Laravel: `record` traza 27 fuentes (vistas Blade incluidas) y 3 tablas; tocar la migración de `comments` selecciona exactamente los 3 tests que usan `RefreshDatabase` (todas las tablas de migraciones les pertenecen, spec §10) y replaya `HomePageTest`; tocar `welcome.blade.php` selecciona solo `HomePageTest` por arista (`BladeTracker` la registró al grabar).

## Qué quedó fuera y por qué

- **Heurística de hermeticidad** (`hermeticity_heuristics`, §8.4): la clave de config existe pero no marca "sospechosos" en `status`. Fase 3 junto con el remoto (necesita aristas a `Carbon/`, `Faker/`, `Http/Client`).
- **Cobertura fusionada** (`CoverageMerger`, `--coverage-php`) — fase 3 por spec.
- **Etiqueta del resumen**: los tests `#[NotCacheable]` se cuentan como `quarantined`; en fase 3 el resumen distinguirá `N quarantined · M not cacheable`.
- **PHPUnit 11.5 con suite completa**: solo verificado por API y en el spike (`docs/spikes`); la matriz real queda para `.github/workflows/ci.yml` (fase 3). El fixture laravel-lite resolvió PHPUnit 12.5, no 11.5.
- **Paratest en modo in-process**: no probado (Paratest + trait); el wrapper con `-p` sí.

## Cómo probarlo en un proyecto real

Además de los 7 pasos de fase 1 (README → "Trying it on your project"):

1. **In-process**: en tu `TestCase` base `use \Manuglopez\Replay\PHPUnit\Replayable;` (o extiende `ReplayableTestCase`), registra `<extensions><bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension"><parameter name="mode" value="auto"/></bootstrap></extensions>` en `phpunit.xml` y lanza `vendor/bin/phpunit` normal dos veces: la segunda debe imprimir `Replay  ✓ … replayed` tras el resumen de PHPUnit con el mismo número de aserciones. Añade `if ($this->isReplaying()) return;` tras `parent::setUp()` para ahorrar el boot.
2. **Explain**: `vendor/bin/phpunit-replay explain app/Models/User.php`.
3. **Flaky**: `vendor/bin/phpunit-replay verify` en `main` (o nightly); `status` muestra `divergences: N in R verify runs` y los ids en cuarentena; `prune --flaky` limpia.
4. **Laravel**: `status` debe decir `framework: laravel` y `tables: N` tras `record`; toca una migración y comprueba con `--explain --dry-run` que salen solo los tests de BD.
5. **Paralelo**: `composer require --dev brianium/paratest` y `vendor/bin/phpunit-replay record -p`.
