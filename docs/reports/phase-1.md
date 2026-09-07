# Fase 1 — informe

Estado: **cerrada** (tag `v0.1.0-alpha1`). Paquete `manuglopez/phpunit-replay`, namespace `Manuglopez\Replay`, PHP 8.4.23, PHPUnit 12.5.34, pcov 1.0.12, Xdebug 3.5.3 (compilado localmente para 8.4).

## Qué se hizo

| Área | Ficheros | Origen |
|---|---|---|
| `Support/` Paths, AtomicFile, Json | 3 | nuevo |
| `Cache/` ContentHash, Fingerprint, ProjectKey, StateDirectory, Graph, GraphStore, ContentKey, GraphUpdater | 8 | portado de Pest (ContentHash, Fingerprint, ProjectKey, Graph-modelo) + nuevo |
| `Change/` Git, ChangedFiles, LastRunTree | 3 | portado de Pest + nuevo |
| `Record/` CoverageDriver, PcovDriver, XdebugDriver, DriverDetector, SourceScope, Recorder, ResultCollector, RunWriter, RunPartial | 9 | portado (SourceScope, Recorder, ResultCollector) + nuevo |
| `PHPUnit/` Mode, ConfigurationReader, ConfigurationWriter, ReplayState, ReplayExtension, 18 subscribers | 23 | subscribers portados de Pest; resto nuevo |
| `Select/` TestPaths, WatchPatterns, WatchDefaults (Php/Laravel/Symfony), Rules (PhpEdge/TestFile/Watch), Selector | 14 | portado (TestPaths, WatchPatterns, defaults) + nuevo |
| `Report/` Summary, RecordSummary, JUnitMerger | 4 | nuevo |
| `Console/` Application, comandos run/record/status/baseline-path, RunPipeline, PhpunitProcess, ProjectLocator | 12 | nuevo |
| `Config.php`, `Version.php`, `bin/phpunit-replay` | 3 | nuevo |

Total: 77 ficheros en `src/` (8116 líneas), 51 clases de test.

Fixture `tests/Fixtures/Projects/plain`: 5 clases (`Money`, `TaxCalculator`, `Cart`, `Discount`, `Greeter`), 7 ficheros de test (35 tests: data provider, `#[Depends]`, fallo controlado por `FIXTURE_FAIL=1`, test skipped, fichero con solo comentarios), variantes en `plain-variants/`. `tests/Support/FixtureProject` copia el fixture a un tmp con `git init` y un shim de `vendor/` (sin `composer install`), y lanza `bin/phpunit-replay` por subproceso con `HOME` aislado.

## Gate de fase 1

| Requisito | Resultado |
|---|---|
| `composer validate --strict` | OK |
| `vendor/bin/phpstan analyse` (nivel max, `phpVersion: 80200`) | `[OK] No errors` |
| Suite con pcov (`composer test`) | `Tests: 395, Assertions: 1200, Skipped: 3` (los 3 skips son guards de Xdebug ausente) — 15,7 s |
| Suite con Xdebug (`XDEBUG_INI_DIR=… composer test:xdebug`) | `Tests: 395, Assertions: 1193, Skipped: 5` (skips = guards de pcov) — 17,3 s |
| 12 escenarios §15 | 1–9 y 11 en `tests/Integration/Scenario*Test.php`; **10 (in-process) y 12 (cuarentena) son de fase 2** |
| 8 criterios §16 | `tests/Integration/AcceptanceCriteriaTest.php` (ver métodos abajo) |
| Escrituras atómicas | `Support\AtomicFile` (tmp + rename); criterio 8 mata el wrapper con SIGKILL y comprueba `graph.json` |

Métodos de `AcceptanceCriteriaTest`:

```
40  test_criterion_1_second_pass_with_no_changes_is_fast_and_replays_everything
57  test_criterion_2_changing_a_source_executes_exactly_its_dependents
82  test_criterion_3_a_failed_test_is_never_replayed
103  test_criterion_4_comment_only_change_executes_nothing
117  test_criterion_5_structural_change_forces_a_full_record_with_a_warning
132  test_criterion_6_filter_runs_only_what_was_asked_without_corrupting_the_graph
152  test_criterion_7_without_a_coverage_driver_phpunit_still_runs_normally
171  test_criterion_8_a_killed_wrapper_never_corrupts_state
```

## Salida real sobre el fixture

Copia del fixture en un tmp (`git init` + commit), `HOME` aislado, `php bin/phpunit-replay` por subproceso. Salida íntegra de `scratchpad/walk.sh`:

```
$ phpunit-replay status
root:      <tmp>/proj
branch:    main (default: main)
head:      392d00e
state dir: <tmp>/home/.phpunit-replay/proj-a8138fc79c736026
driver:    pcov (loaded, enabled per run)
framework: plain

no baseline yet
[exit=0]

$ phpunit-replay record
PHPUnit 12.5.34 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.23
Configuration: <tmp>/proj/.phpunit-replay.xml

........................S..........                               35 / 35 (100%)

Time: 00:00.025, Memory: 10.00 MB

OK, but some tests were skipped!
Tests: 35, Assertions: 61, Skipped: 1.
Replay  ● recorded 35 tests in 7 test files · 12 source files · 18 edges · graph.json 6 KB · baseline main@392d00e · 0s
[exit=0, wall=316 ms]

$ phpunit-replay 
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed · 0 quarantined · baseline main@392d00e
[exit=0, wall=176 ms]

# edited src/Money.php (behaviour change)
$ phpunit-replay --explain --dry-run
tests/CartTest.php                       ← PhpEdge  src/Money.php
tests/CommentedTest.php                  ← PhpEdge  src/Money.php
tests/DependsTest.php                    ← PhpEdge  src/Money.php
tests/DiscountTest.php                   ← PhpEdge  src/Money.php
tests/MoneyTest.php                      ← PhpEdge  src/Money.php
tests/TaxCalculatorTest.php              ← PhpEdge  src/Money.php
Replay  ✓ 0 executed (6 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline main@392d00e
[exit=0]

$ phpunit-replay 
PHPUnit 12.5.34 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.23
Configuration: <tmp>/proj/.phpunit-replay.xml

....................S..........                                   31 / 31 (100%)

Time: 00:00.022, Memory: 10.00 MB

OK, but some tests were skipped!
Tests: 31, Assertions: 53, Skipped: 1.
Replay  ✓ 31 executed (6 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline main@392d00e
[exit=0, wall=325 ms]

# edited src/Money.php (comments only, clean baseline)
$ phpunit-replay 
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed · 0 quarantined · baseline main@392d00e
[exit=0, wall=175 ms]

# FIXTURE_FAIL=1

<tmp>/proj/tests/GreeterTest.php:41
<pkg>/vendor/phpunit/phpunit/phpunit:104

FAILURES!
Tests: 4, Assertions: 8, Failures: 1.
[exit=1]

# failed test must re-run without changes

OK (4 tests, 8 assertions)
Replay  ✓ 4 executed (0 affected, 1 uncached) · 31 replayed · 0 quarantined · baseline main@392d00e
[exit=0]

$ phpunit-replay 
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed · 0 quarantined · baseline main@392d00e
[exit=0, wall=180 ms]

$ phpunit-replay status
root:      <tmp>/proj
branch:    main (default: main)
head:      392d00e
state dir: <tmp>/home/.phpunit-replay/proj-a8138fc79c736026
driver:    pcov (loaded, enabled per run)
framework: plain

files:      12
test files: 7
edges:      18
graph.json: 6 KB

results:
  main                 complete   392d00e    35 results

fingerprint:
  structural:    composer_lock=4acce0fa692f2459e2ed9e17acaeade1 phpunit_xml=9afa8142347478a26ea1c4945e534b1a phpunit_xml_dist=null replay_config=null schema=1
  environmental: driver=pcov os=Linux php=8.4

quarantined: 0
[exit=0]

$ phpunit-replay baseline-path
<tmp>/home/.phpunit-replay/proj-a8138fc79c736026
[exit=0]

# state dir contents:
total 12
drwxr-xr-x 3 mglopez mglopez  100 sep  6 22:40 .
drwxr-xr-x 3 mglopez mglopez   60 sep  6 22:40 ..
-rw-r--r-- 1 mglopez mglopez 6450 sep  6 22:40 graph.json
-rw-r--r-- 1 mglopez mglopez  100 sep  6 22:40 last-run.json
drwxr-xr-x 2 mglopez mglopez   40 sep  6 22:40 runs
# project dir after runs (no .phpunit-replay.xml left):
. .. composer.json composer.lock .git .gitignore .phpunit.cache phpunit.xml src tests vendor 
```

Lectura: primera pasada graba (35 tests, 7 test files, 12 fuentes, 18 aristas); segunda pasada sin cambios 0 ejecutados / 35 replayed en **176 ms** de pared (bootstrap PHP incluido); cambiar `src/Money.php` ejecuta exactamente los 6 test files con arista a él (31 tests) y replaya los 4 de `GreeterTest`; cambiar solo comentarios ejecuta 0; un test fallido se guarda y se vuelve a ejecutar sin cambios (`1 uncached`); `--filter` no toca aristas ni sha (escenario 6).

## Qué quedó fuera y por qué

- **Modo in-process (trait `Replayable`)** — fase 2 por spec. Además `TestCase::runTest()` es `private` en 11.5 y 12; el spike `docs/spikes/in-process-replay.md` verifica los dos mecanismos viables (`invokeTestMethod()` en 12.5; swap por reflexión de `methodName` en 11.5/12.5).
- **`explain`, `prune`, hermeticidad (`#[NotCacheable]`, globs, cuarentena), `verify`, Laravel, Paratest** — fase 2. `--explain` como opción de `run` sí está.
- **Caché remota, `push`/`pull`, `CoverageMerger`** — fase 3. `--no-remote` se acepta y no hace nada.
- **`generator` en graph.json** — no se escribe todavía (`Version::ID` se añade en fase 2).
- **CI del paquete (matriz 8.2/8.3/8.4 × 11.5/12 × pcov/xdebug)** — fase 3 (`.github/workflows/ci.yml`). Localmente solo 8.4 + 12.5; 11.5 verificado a nivel de API (firmas) y en el spike, no con la suite completa.
- **Xdebug del sistema** — el paquete `xdebug` de pacman es para PHP 8.5; se compiló 3.5.3 para 8.4 en el scratchpad. No se ha tocado `/etc/php84`.

## Cómo probarlo en un proyecto real

Pasos exactos en `README.md` → "Trying it on your project". Resumen:

1. `composer config repositories.replay path ../phpunit-replay && composer require --dev manuglopez/phpunit-replay:@dev`
2. `vendor/bin/phpunit-replay status` → `no baseline yet`, driver, root git, rama por defecto, framework.
3. `vendor/bin/phpunit-replay record` → suite completa + línea `Replay  ● recorded …`.
4. `vendor/bin/phpunit-replay` sin cambios → `0 executed … N replayed`, < 2 s + bootstrap.
5. Tocar una clase → `vendor/bin/phpunit-replay --explain` (añade `--dry-run` para no ejecutar).
6. `verify` → fase 2 (no existe aún).
7. Si algo no cuadra: `--fresh`, `status`, `PHPUNIT_REPLAY_DEBUG=1` (decisiones a stderr), `PHPUNIT_REPLAY_KEEP_RUN=1` conserva `runs/<id>/` y `.phpunit-replay.xml`.

Nota pcov: en el proyecto real basta con tener `ext-pcov` cargada; el wrapper lanza PHPUnit con `-d pcov.enabled=1 -d pcov.directory=<root>`. Con Xdebug, el wrapper añade `-d xdebug.mode=coverage`; la extensión debe estar cargada por ini para que el proceso hijo la vea.
