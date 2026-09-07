# manuglopez/phpunit-replay — Especificación técnica

> Test Impact Analysis + replay de resultados para PHPUnit puro (11.5+ / 12).
> Documento pensado para entregarse íntegro a Claude Code como prompt de implementación.
> Estado: v0.1 — 2026-09-06.

---

## 0. Resumen en un párrafo

`phpunit-replay` graba, por fichero de test, qué ficheros fuente del proyecto se ejecutan durante sus tests (driver de cobertura crudo: pcov o Xdebug) y el resultado de cada test (estado, aserciones, mensaje, duración). En pasadas posteriores compara el árbol de trabajo con el baseline (git + hash de contenido normalizado), calcula qué ficheros de test están afectados y **ejecuta solo esos**, sirviendo el resto desde caché como *passed* con su número real de aserciones. Funciona en dos modos: **filtered** (el wrapper `vendor/bin/phpunit-replay` genera una configuración que excluye los ficheros no afectados; cero cambios en los tests) y **in-process** (un trait en el `TestCase` base replaya dentro del runner, para cuando PHPUnit debe ver todos los tests). La caché es direccionable por contenido y puede compartirse entre máquinas.

Diferencias con lo que ya existe (`jasonmccreary/phpunit-tia`, `gosuperscript/phpunit-tia`, Pest 5 TIA):

| | Pest 5 TIA | phpunit-tia (ambos) | **phpunit-replay** |
|---|---|---|---|
| Runner | Solo Pest (aborta con clases PHPUnit) | PHPUnit 12 / 13 | PHPUnit 11.5+ y 12 |
| Tests no afectados | Pass sintético con aserciones | **Skipped** | Pass sintético con aserciones (in-process) o no cargados (filtered) |
| Resumen/JUnit completos | Sí | No | Sí: el wrapper fusiona resultados cacheados en el resumen y en el JUnit |
| Cambios cosméticos ignorados | Sí (tokenizer) | Parcial | Sí (tokenizer) |
| Baselines por rama | Sí | No | Sí |
| Caché remota | Artefacto GitHub vía `gh` | No | Content-addressed: filesystem, HTTP/S3, cualquier backend |
| Tests no herméticos | Sin detección | Sin detección | `#[NotCacheable]`, globs, cuarentena automática por flip |
| Laravel (tablas, Blade) | Sí | No | Sí (opcional, autodetectado) |
| Paralelo | Sí (Paratest interno) | No | Fase 2 (Paratest) |

---

## 1. Nombre, identidad y layout

**Nombre Composer:** `manuglopez/phpunit-replay`
**Namespace PHP:** `Manuglopez\Replay`
**Binario:** `vendor/bin/phpunit-replay`
**Directorio de estado local:** `~/.phpunit-replay/<project-key>/` (configurable; alternativa `.phpunit-replay/` en el repo, gitignored)
**Extensión PHPUnit:** `Manuglopez\Replay\PHPUnit\ReplayExtension`
**Trait opcional:** `Manuglopez\Replay\PHPUnit\Replayable`
**Atributo:** `Manuglopez\Replay\Attributes\NotCacheable`

Motivo del nombre: "replay" describe el diferenciador (los resultados se replayan, no se saltan), es buscable ("phpunit replay cache") y no colisiona en Packagist. Nombres alternativos considerados y descartados: `phpunit-tia` (ocupado dos veces), `phpunit-impact` (genérico), `dejavu` (bonito pero no buscable).

```
phpunit-replay/
├── bin/
│   └── phpunit-replay                 # CLI wrapper (Symfony Console)
├── src/
│   ├── Attributes/NotCacheable.php
│   ├── Cache/
│   │   ├── Graph.php                  # modelo en memoria + encode/decode JSON
│   │   ├── GraphStore.php             # lectura/escritura atómica de graph.json
│   │   ├── ContentHash.php            # xxh128 normalizado por tipo de fichero
│   │   ├── Fingerprint.php            # invalidación estructural / ambiental
│   │   ├── ProjectKey.php             # clave del proyecto a partir del remote git
│   │   └── Remote/
│   │       ├── RemoteCache.php        # interface get/put/has
│   │       ├── NullRemoteCache.php
│   │       ├── FilesystemRemoteCache.php
│   │       └── HttpRemoteCache.php    # GET/PUT (S3 presigned, MinIO, nginx WebDAV…)
│   ├── Change/
│   │   ├── Git.php                    # wrappers de comandos git (symfony/process)
│   │   ├── ChangedFiles.php           # diff + status + check-ignore + hash filter
│   │   └── LastRunTree.php            # segunda capa: ficheros sucios ya testeados
│   ├── Record/
│   │   ├── CoverageDriver.php         # interface start/stop/collect
│   │   ├── PcovDriver.php
│   │   ├── XdebugDriver.php
│   │   ├── Recorder.php               # begin/end por test, reduce a ficheros
│   │   ├── SourceScope.php            # qué ficheros cuentan como "fuente"
│   │   └── ResultCollector.php        # estado/aserciones/tiempo por test id
│   ├── Select/
│   │   ├── Selector.php               # algoritmo affected()
│   │   ├── WatchPatterns.php          # globs → directorios de test
│   │   ├── TestPaths.php              # qué es un fichero de test (phpunit.xml)
│   │   └── Rules/                     # una clase por regla (PHP edges, test file, watch, sibling, tables, blade)
│   ├── Hermeticity/
│   │   ├── Quarantine.php             # flaky.json: tests que flipearon sin cambios
│   │   └── Policy.php                 # NotCacheable + globs + cuarentena → ¿cacheable?
│   ├── PHPUnit/
│   │   ├── ReplayExtension.php        # PHPUnit\Runner\Extension\Extension
│   │   ├── Replayable.php             # trait: override runTest() + guard setUp
│   │   ├── ReplayState.php            # singleton estático compartido trait ↔ extensión
│   │   ├── Subscribers/               # uno por evento
│   │   └── ConfigurationReader.php    # lee Registry::get(): source, testsuites, failOn*
│   ├── Report/
│   │   ├── Summary.php                # "N affected, M uncached, K replayed"
│   │   └── JUnitMerger.php            # fusiona junit.xml real + resultados cacheados
│   ├── Laravel/                       # solo se carga si existe Illuminate\Container\Container
│   │   ├── TableTracker.php
│   │   ├── TableExtractor.php
│   │   ├── BladeTracker.php
│   │   └── MigrationTables.php
│   ├── Console/
│   │   ├── Application.php
│   │   └── Commands/{RunCommand,RecordCommand,StatusCommand,PruneCommand,BaselinePathCommand,ExplainCommand}.php
│   └── Config.php                     # carga de phpunit-replay.php / parámetros de extensión / env
├── tests/
│   ├── Unit/                          # cada componente aislado
│   ├── Integration/                   # fixtures de proyectos mínimos ejecutados con PHPUnit real
│   └── Fixtures/Projects/{plain,laravel-lite}/
├── phpunit.xml.dist
├── composer.json
├── README.md
└── CHANGELOG.md
```

### composer.json (esqueleto)

```json
{
  "name": "manuglopez/phpunit-replay",
  "description": "Test Impact Analysis and result replay for PHPUnit: run only what your changes affect, replay the rest from cache.",
  "type": "library",
  "license": "MIT",
  "keywords": ["phpunit", "testing", "test-impact-analysis", "cache", "tia", "speed"],
  "require": {
    "php": "^8.2",
    "ext-json": "*",
    "ext-tokenizer": "*",
    "phpunit/phpunit": "^11.5 || ^12.0",
    "symfony/process": "^6.4 || ^7.0 || ^8.0",
    "symfony/console": "^6.4 || ^7.0 || ^8.0",
    "symfony/finder": "^6.4 || ^7.0 || ^8.0"
  },
  "suggest": {
    "ext-pcov": "Fastest coverage driver for recording the dependency graph",
    "ext-xdebug": "Alternative coverage driver (mode=coverage)",
    "brianium/paratest": "Parallel execution support"
  },
  "autoload": { "psr-4": { "Manuglopez\\Replay\\": "src/" } },
  "autoload-dev": { "psr-4": { "Manuglopez\\Replay\\Tests\\": "tests/" } },
  "bin": ["bin/phpunit-replay"],
  "config": { "sort-packages": true },
  "minimum-stability": "stable"
}
```

---

## 2. Requisitos y restricciones de PHPUnit que condicionan el diseño

Estas restricciones son hechos verificados del API de PHPUnit 10+ y determinan la arquitectura; no las discutas, diséñalo alrededor:

1. **Las extensiones son de solo lectura.** Reciben eventos (`PHPUnit\Event\...`) y no pueden saltar tests, alterar resultados ni modificar la suite. Por tanto la selección "dura" solo puede hacerse **antes** de que PHPUnit cargue los tests (modo filtered) o **dentro** del `TestCase` (modo in-process).
2. **`TestCase::runBare()` es `final`.** No se puede interceptar el ciclo completo. Lo que sí es sobreescribible: `protected function runTest(): mixed` (invoca el método de test por reflexión) y `protected function setUp(): void`.
3. **`setUp()` de la clase concreta gana sobre el del trait del `TestCase` base.** Si el usuario sobreescribe `setUp()` en su test y llama `parent::setUp()`, nuestro guard del padre puede retornar pronto, pero el código que el usuario ponga *después* de `parent::setUp()` se ejecutará. Por eso el modo in-process no puede garantizar que se ahorre el coste de `setUp()`; sí garantiza que se ahorre el cuerpo del test y que el resultado sea un pass con las aserciones cacheadas. El ahorro grande viene del modo filtered.
4. **pcov solo instrumenta bajo `pcov.directory`.** El wrapper debe lanzar PHP con `-d pcov.directory=<root>` y `-d pcov.enabled=1`. Y la cobertura propia de PHPUnit debe estar apagada (`--no-coverage`) mientras grabamos con el driver crudo, o se pisan.
5. **Los ids de test** son `PHPUnit\Event\Code\TestMethod::id()` → `Fully\Qualified\Class::method` y, con data providers, `Class::method#datasetName` (o `#0`, `#1`). Se tratan como strings opacos.
6. **Configuración disponible en runtime** vía `PHPUnit\TextUI\Configuration\Registry::get()`: `source()->includeDirectories()/excludeDirectories()`, `testSuite()`, `failOnRisky()`, `failOnWarning()`, `failOnNotice()`, `failOnDeprecation()`, `failOnSkipped()`, `failOnIncomplete()`, `displayDetailsOn*()`, `testSuffixes()`.

---

## 3. Modos de funcionamiento

### 3.1 Modo `filtered` (por defecto en el wrapper, cero cambios en los tests)

```
vendor/bin/phpunit-replay [opciones de phpunit-replay] [-- opciones de phpunit]
```

1. Resuelve raíz del repo, rama, fingerprint; carga `graph.json` (local o remoto).
2. Si no hay grafo válido → **record**: ejecuta `phpunit` completo con la extensión en modo grabación y guarda el grafo. Fin.
3. Si hay grafo → calcula `changed` y `affected` (§7).
4. Construye la lista de ficheros de test a ejecutar: `affected ∪ desconocidos ∪ conFallosCacheados ∪ noCacheables`.
5. Si la lista está vacía: imprime resumen "0 executed, N replayed", opcionalmente escribe el JUnit fusionado, sale con 0.
6. Genera `phpunit.replay.xml` temporal: copia del `phpunit.xml` del usuario con los `<testsuite>` sustituidos por un único testsuite que lista `<file>` por cada test a ejecutar (mantiene `<source>`, `<php>`, `<extensions>`, bootstrap, etc.). Inyecta `<extensions><bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension"/></extensions>` si no está.
7. Ejecuta `php -d pcov.directory=<root> vendor/bin/phpunit -c phpunit.replay.xml --no-coverage [args del usuario]` con el entorno `PHPUNIT_REPLAY_MODE=record-subset`, `PHPUNIT_REPLAY_STATE_DIR=...`, `PHPUNIT_REPLAY_RUN_ID=...`. Stdout/stderr pasan tal cual al usuario.
8. La extensión graba aristas y resultados de los tests ejecutados en `runs/<run-id>/{edges,results}.json`.
9. El wrapper fusiona en el grafo, actualiza baseline de rama, hace snapshot del árbol, poda, escribe `graph.json`, sube al remoto si está configurado.
10. Imprime una línea de resumen propia debajo de la de PHPUnit:
    `Replay: 38 executed (31 affected, 7 uncached), 1202 replayed from cache, 0 quarantined · saved ~4m12s`
11. Si el usuario pasó `--log-junit=X` (a phpunit-replay, no a phpunit), el wrapper genera un JUnit **completo** fusionando el JUnit real de la ejecución con los resultados cacheados (`JUnitMerger`), marcando los cacheados con `<property name="replayed" value="true"/>`.
12. Código de salida: el de PHPUnit.

Opciones parciales de PHPUnit (`--filter`, `--group`, `--exclude-group`, `--testsuite`, ruta explícita, `--covers`, `--uses`) **desactivan la selección**: se ejecuta lo que el usuario pidió con la extensión en modo `results-only` (actualiza resultados, no aristas ni sha). `--random-order` con seed distinto no importa (aristas a nivel de fichero) pero `--order-by=random` sin seed se acepta sin más.

### 3.2 Modo `in-process` (trait `Replayable`)

Para cuando PHPUnit debe recorrer toda la suite (IDE que lanza `phpunit` directamente, `--coverage`, o simplemente no querer el wrapper). El usuario añade el trait a su `TestCase` base y registra la extensión en `phpunit.xml`:

```php
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use \Manuglopez\Replay\PHPUnit\Replayable;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->isReplaying()) { return; }   // opcional: ahorra el boot caro
        // ...boot de la app, RefreshDatabase, etc.
    }
}
```

```xml
<extensions>
    <bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension">
        <parameter name="mode" value="auto"/>          <!-- auto|record|replay|off -->
        <parameter name="stateDir" value=""/>          <!-- vacío = ~/.phpunit-replay/<key> -->
        <parameter name="remote" value=""/>            <!-- file:///mnt/cache | https://cache.example/replay/ -->
    </bootstrap>
</extensions>
```

Comportamiento del trait (§6.3): al llamarse `runTest()`, consulta `ReplayState`; si el test es replayable, hace `addToAssertionCount($cached)` (y `expectNotToPerformAssertions()` si eran 0) y retorna `null` sin invocar el método. Skipped/incomplete cacheados se replayan con `markTestSkipped/markTestIncomplete($message)`. Fallos **nunca** se replayan.

Este modo también permite `phpunit --coverage-html` con TIA: la extensión no puede fusionar cobertura serializada como hace Pest en su primera versión; en fase 3 se implementa `CoverageMerger` procesando `--coverage-php`.

### 3.3 Modo `record` explícito

`vendor/bin/phpunit-replay record [--fresh]` → suite completa con grabación. Es lo que se lanza en CI tras merge a `main` para publicar baseline.

---

## 4. Estado y formato de caché

### 4.1 Directorio

```
~/.phpunit-replay/<slug>-<hash16>/
├── graph.json
├── flaky.json              # cuarentena (§8)
├── last-run.json           # {branch, sha, tree:{rel:hash}, finishedAt}
├── runs/<run-id>/          # parciales de una ejecución en curso; se borran al fusionar
│   ├── edges.json
│   ├── results.json
│   └── meta.json           # {driver, phpVersion, truncated, startedAt}
└── remote/                 # cache local de objetos remotos descargados
```

`ProjectKey`: `slug(basename(root)) . '-' . substr(sha256(normalizedOriginUrl ?? realpath(root)), 0, 16)`. Normalización de URL: quitar protocolo, usuario, `.git`, pasar a minúsculas → `github.com/manuglopez/phpunit-replay`. Así clones y worktrees comparten estado.

### 4.2 graph.json (schema 1)

```json
{
  "schema": 1,
  "generator": "manuglopez/phpunit-replay 0.1.0",
  "fingerprint": {
    "structural": {
      "schema": 1,
      "composer_lock": "xxh128…",
      "phpunit_xml": "xxh128…|null",
      "phpunit_xml_dist": "xxh128…|null",
      "replay_config": "xxh128…|null"
    },
    "environmental": { "php": "8.3", "driver": "pcov", "os": "Darwin" }
  },
  "files": ["app/Models/Ad.php", "app/Services/Pricing.php", "tests/Feature/AdTest.php"],
  "edges": { "tests/Feature/AdTest.php": [0, 1, 2] },
  "test_tables": { "tests/Feature/AdTest.php": ["ads", "users"] },
  "not_cacheable": ["tests/Feature/ExternalApiTest.php"],
  "baselines": {
    "main": {
      "sha": "40-hex",
      "complete": true,
      "results": {
        "Tests\\Feature\\AdTest::test_publishes": {"s": 0, "a": 3, "t": 0.041, "m": "", "f": "tests/Feature/AdTest.php", "k": "xxh128…"},
        "Tests\\Feature\\AdTest::test_prices#premium": {"s": 0, "a": 1, "t": 0.012, "m": "", "f": "tests/Feature/AdTest.php", "k": "xxh128…"}
      }
    }
  }
}
```

Reglas:

- `files` es una tabla de ficheros con ids enteros; `edges` es **test file → ids** (única dirección almacenada; la inversa se construye en memoria al cargar).
- Un test file presente en `edges` con lista vacía es "conocido sin dependencias" (distinto de desconocido). `knowsTest(rel)`.
- `s` = `PHPUnit\Framework\TestStatus\TestStatus::asInt()`: 0 success, 1 skipped, 2 incomplete, 3 notice, 4 deprecation, 5 risky, 6 warning, 7 failure, 8 error. `a` aserciones, `t` segundos, `m` mensaje, `f` fichero relativo, `k` **clave de contenido** (§4.3).
- Baselines por rama. Lectura en rama `X`: `array_replace(results[default], results[X])` salvo que `X.complete === true`, en cuyo caso los resultados del default solo se usan para ficheros no cubiertos por `X`.
- `decode()` defensivo: `schema` distinto → grafo descartado con aviso; secciones malformadas → se ignoran esas entradas.
- Escritura atómica: `tmp` + `rename`.

### 4.3 Clave de contenido por test file (content-addressed)

```
k = xxh128(
  fingerprint.structural (json canónico) .
  ContentHash(testFile) .
  join(sorted(map(deps, f => rel(f) . ':' . ContentHash(f))))
)
```

`k` se calcula al grabar y se guarda en cada resultado. Sirve para dos cosas: (a) la caché remota se indexa por `k` (`objects/<k>.json` con los resultados de todos los tests de ese fichero), así cualquier máquina con los mismos contenidos obtiene los mismos resultados sin necesitar el mismo `sha`; (b) la cuarentena detecta flips: mismo `k`, distinto `s` → no hermético.

### 4.4 ContentHash (normalización)

- `.php` (no `.blade.php`): `token_get_all`, descartar `T_WHITESPACE`, `T_COMMENT`, `T_DOC_COMMENT`, concatenar `text` de cada token, `hash('xxh128', ...)`. Si el tokenizer devuelve vacío → hash del contenido crudo.
- `.blade.php`: quitar `{{-- ... --}}` (multilínea), colapsar `\s+` a un espacio, trim.
- `.js .mjs .cjs .ts .mts .tsx .jsx .vue .svelte`: quitar líneas que son solo `// ...` y bloques `/* */` que ocupan líneas completas, colapsar espacios, trim.
- resto: hash crudo.

`ContentHash::of(path): ?string` (null si no existe) y `ContentHash::ofContent(string $content, string $pathForType): string`.

### 4.5 Fingerprint

- **Estructural** (cambio → grafo completo descartado, record fresco): `composer.lock`, `phpunit.xml`, `phpunit.xml.dist`, `phpunit-replay.php`, y la constante `SCHEMA_VERSION` del paquete. Solo se hashean si están trackeados por git.
- **Ambiental** (cambio → se descartan resultados, se conservan aristas): versión `MAJOR.MINOR` de PHP, driver, `PHP_OS_FAMILY`.
- Se comprueba al inicio **y al final** de la pasada: si cambió durante la ejecución, las aristas grabadas en esa pasada se descartan.

---

## 5. Grabación

### 5.1 Drivers

```php
interface CoverageDriver {
    public static function available(): bool;
    public function start(): void;
    /** @return array<string, array<int,int>> file => [line => hits] */
    public function stop(): array;
}
```

- `PcovDriver::available()`: `function_exists('pcov\start') && filter_var(ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL)`. `start()`: `\pcov\clear(); \pcov\start();`. `stop()`: `\pcov\stop(); $files = array_filter(\pcov\waiting(), $scope->contains(...)); return \pcov\collect(\pcov\inclusive, $files);`.
- `XdebugDriver::available()`: `function_exists('xdebug_start_code_coverage') && in_array('coverage', xdebug_info('mode'), true)`. `start()`: `xdebug_start_code_coverage()` (sin flags: solo hits). `stop()`: `$d = xdebug_get_code_coverage(); xdebug_stop_code_coverage(true); return filtrado por scope`.
- Si el wrapper detecta pcov con `pcov.directory` distinto de la raíz, relanza PHP con `-d pcov.directory=<root>` (variable de entorno `PHPUNIT_REPLAY_RESTARTED=1` evita bucles).

### 5.2 SourceScope

Incluye: directorios de `<source><include>` del `phpunit.xml` **más** todos los directorios de primer nivel del proyecto salvo `vendor, node_modules, .git, .idea, .vscode, .github, .phpunit.cache, .cache, storage/framework, storage/logs, bootstrap/cache` y los `<source><exclude>`. Si no hay includes, la raíz entera. `tests/` **sí** está en scope (un test file es nodo fuente de sí mismo; así "cambió el test" se resuelve por aristas). `vendor/` se excluye siempre al relativizar.

### 5.3 Recorder

Por test: `beginTest(testFile)` en `Test\PreparationStarted` (para capturar `setUp`), `endTest()` en `Test\Finished` (tras `tearDown`). Se reduce a nivel de fichero con esta heurística, copiada de Pest porque funciona: un fichero cuenta si tiene alguna línea con hits > 0 **salvo** que el driver reporte líneas no ejecutadas y la única ejecutada sea la de mayor número (fichero que solo se autocargó). `perTestFiles[testFile][sourceFile] = true`.

Aristas por **fichero de test**, no por método. Test file = `TestMethod::file()`; fallback `(new ReflectionClass($className))->getFileName()`.

### 5.4 ResultCollector (subscribers PHPUnit)

| Evento | Acción |
|---|---|
| `Test\PreparationStarted` | `start(id, file)`; `hrtime` |
| `Test\Passed` | `status = success` salvo que ya haya un issue registrado para este id (entonces solo refresca tiempo) |
| `Test\Failed` / `Errored` | `status = failure/error` con `throwable()->message()`; sobreescribe cualquier issue |
| `Test\Skipped` / `MarkedIncomplete` | `status = skipped/incomplete` con mensaje |
| `Test\ConsideredRisky` | `status = risky` si no hay uno peor |
| `Test\{Warning,PhpWarning,Notice,PhpNotice,Deprecation,PhpDeprecation}Triggered` | si `!$event->wasSuppressed()`, `recordIssue(status)` — solo sube, nunca baja |
| `Test\Finished` | `assertions = $event->numberOfAssertionsPerformed()`, `time`, cierra |
| `TestRunner\ExecutionFinished` | `flush()` → `runs/<run-id>/results.json`, `edges.json`, `meta.json` |
| `TestRunner\ExecutionAborted`, `Bail` | `meta.truncated = true` |

Precedencia de estados: `error > failure > warning > risky > deprecation > notice > incomplete > skipped > success`.

---

## 6. Extensión, estado compartido y trait

### 6.1 ReplayExtension

```php
final class ReplayExtension implements \PHPUnit\Runner\Extension\Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $config = Config::fromExtensionParameters($parameters)->mergeEnv($_SERVER);
        $state  = ReplayState::boot($config, $configuration);      // decide mode: record | replay | results-only | off
        if ($state->mode() === Mode::Off) return;

        $facade->registerSubscribers(...ResultCollector::subscribers($state));
        if ($state->recordsEdges()) {
            $facade->registerSubscribers(new StartRecordingOnPreparationStarted($state), new StopRecordingOnFinished($state));
        }
        $facade->registerSubscriber(new FlushOnExecutionFinished($state));
    }
}
```

Decisión de modo en la extensión (cuando no la orquesta el wrapper):

- `PHPUNIT_REPLAY_MODE` env (lo pone el wrapper) manda.
- Sin env, parámetro `mode=auto`: si hay grafo válido y fingerprint coincide → `replay` (con grabación de aristas de lo que se ejecute si hay driver); si no hay grafo y hay driver → `record`; sin driver → `off` con aviso.
- Si se detecta selección parcial en `$configuration` (`hasFilter()`, `hasGroups()`, `hasExcludeGroups()`, `includeTestSuite()`, `cliArguments()` con ruta) → `results-only`.

### 6.2 ReplayState

Singleton estático (necesario porque el trait en el `TestCase` no tiene inyección de dependencias). Expone:

```php
ReplayState::decide(string $testFile, string $testId): Decision  // Run | ReplayPass(assertions) | ReplaySkipped(msg) | ReplayIncomplete(msg)
ReplayState::counters(): array{affected:int, uncached:int, replayed:int, quarantined:int}
```

`decide()` en modo replay:

```
rel = relative(realpath(testFile))
if rel ∈ affected            → Run  (affected++)
if !graph.knowsTest(rel)     → Run  (uncached++)
if policy.notCacheable(rel, testId) → Run (quarantined++)
r = graph.result(branch, testId)
if r === null                → Run  (uncached++)         // test nuevo en fichero conocido
if shouldRerun(r.status)     → Run
replayed++ ; return Replay*(r)
```

`shouldRerun(status)`: failure/error/unknown → siempre; risky → `failOnRisky()`; warning → `failOnWarning() || displayDetailsOnTestsThatTriggerWarnings()`; notice/deprecation → análogo; incomplete → `failOnIncomplete() || displayDetailsOnIncompleteTests()`; skipped → `failOnSkipped() || displayDetailsOnSkippedTests()`.

### 6.3 Trait Replayable

```php
trait Replayable
{
    private ?Decision $__replayDecision = null;

    protected function isReplaying(): bool
    {
        return $this->__replayDecision()->isReplay();
    }

    private function __replayDecision(): Decision
    {
        return $this->__replayDecision ??= ReplayState::decide(
            (new \ReflectionClass(static::class))->getFileName(),
            $this->valueObjectForEvents()->id(),
        );
    }

    protected function runTest(): mixed
    {
        $decision = $this->__replayDecision();

        return match (true) {
            $decision instanceof ReplayPass => $this->__replayPass($decision),
            $decision instanceof ReplaySkipped => $this->markTestSkipped($decision->message),
            $decision instanceof ReplayIncomplete => $this->markTestIncomplete($decision->message),
            default => parent::runTest(),
        };
    }

    private function __replayPass(ReplayPass $d): null
    {
        if ($d->assertions === 0) {
            $this->expectNotToPerformAssertions();
        }
        $this->addToAssertionCount($d->assertions);
        ReplayState::markReplayed($this->valueObjectForEvents()->id(), $d);
        return null;
    }
}
```

Notas: `valueObjectForEvents()` es público en `TestCase` desde PHPUnit 10 y devuelve `TestMethod` con `id()`. Un test replayado como *risky* (0 aserciones, sin `expectNotToPerformAssertions`) vuelve a salir risky de forma natural; por eso `ReplayPass` con `wasRisky=true` no llama a `expectNotToPerformAssertions()`. Al persistir, el resultado de un test replayado conserva el estado/tiempo/aserciones **originales**, no los de la ejecución sintética.

Limitación documentada: `#[Depends]` sobre un test replayado recibe `null`. La política por defecto es **no replayar tests que son dependencia de otros** (se detecta con `MetadataRegistry::parser()->forMethod()` buscando `Depends*`) — coste mínimo, elimina el problema.

---

## 7. Detección de cambios y selección

### 7.1 ChangedFiles::since(sha)

1. `git merge-base --is-ancestor <sha> HEAD`; si falla → baseline inalcanzable → record fresco (con aviso).
2. `git diff --name-only --no-renames <sha>..HEAD` (los renames aparecen como delete+add).
3. `git status --porcelain=v1 -z --untracked-files=all`.
4. Unión → `git check-ignore --no-index -z --stdin` para descartar ignorados.
5. Filtro de contenido: para cada candidato, `ContentHash::of(workingTree) === ContentHash::ofContent(git show <sha>:<path>)` → descartado. Borrados y nuevos permanecen.
6. `LastRunTree`: candidatos ∪ claves de `last-run.tree`; se conserva solo si el hash actual difiere del snapshot (o el fichero desapareció). Un fichero sucio ya testeado no se repite; uno revertido sí se re-ejecuta.

### 7.2 Selector::affected(changed): set<testFile>

Cadena de reglas, cada una recibe los cambios que las anteriores no consumieron:

1. **MigrationRule** (si hay `test_tables`): `database/migrations/**/*.php` cambiado → `TableExtractor::fromMigrationSource()` (`Schema::create|table|drop|dropIfExists|rename`, `CREATE|ALTER|DROP TABLE`, `DB::table('x')`) → tests cuyas tablas intersectan. Migración no parseable → cae a WatchRule.
2. **PhpEdgeRule**: fichero cambiado con id en `files` → todos los test files cuyas aristas lo contienen. Fichero **borrado** con id → igual (las aristas siguen).
3. **TestFileRule**: fichero cambiado que `TestPaths::isTestFile()` (directorios y sufijos de `<testsuites>`) y existe en disco → afectado.
4. **SiblingRule** (Laravel opcional): fichero `.php` nuevo/desconocido bajo `app/Providers/, app/Listeners/, app/Events/, app/Observers/, app/Policies/, app/Console/Commands/, database/factories/, database/seeders/` → tests con aristas a algún fichero del mismo directorio.
5. **BladeRule** (Laravel opcional): `.blade.php` cambiado que no está en el grafo → calcula ancestros estáticos (`@include`, `@extends`, `@component`, `view('x')`, `<x-nombre`) y afecta a tests con aristas a algún ancestro.
6. **WatchRule**: lo que queda y no conoce el grafo → `WatchPatterns` (globs → directorios de test). Defaults:
   - Genérico: `.env*`, `phpunit.xml*`, `docker-compose*.y*ml`, `tests/**/Fixtures/**`, `tests/**/__snapshots__/**` → `tests`.
   - Laravel (si existe `artisan`): `config/**`, `routes/**`, `database/migrations/**`, `resources/views/**`, `lang/**`, `resources/lang/**`, `app/** !*.php`, `bootstrap/*.php` → `tests`.
   - Symfony (si existe `config/bundles.php`): `config/**`, `migrations/**`, `templates/**`, `translations/**` → `tests`.
   - Usuario: `phpunit-replay.php` → `'watch' => ['config/billing/**' => 'tests/Feature/Billing']`. Se fusionan con los defaults.
7. Ficheros que no casan con nada (README, docs) → no afectan a nada. Esto es deliberado y hay que documentarlo: si un `.php` de `app/` no está en ninguna arista es porque ningún test lo ejecutó.

Post-proceso: si cambió cualquier `.php` fuente y **no hay driver disponible** → suite completa (no podemos refrescar aristas). Se descartan test files que ya no existen en disco.

### 7.3 Escritura tras la pasada

- Pasada completa (record o replay sin truncar): `setRecordedSha(branch, HEAD)`, `replaceEdges` de los test files ejecutados (reemplazo completo del set por fichero), merge de resultados, `pruneStaleResults` (ids de ficheros ejecutados que ya no aparecieron: tests renombrados/borrados), `pruneMissingTestFiles`, `pruneMissingBranches` (`git for-each-ref`), `complete = true`, snapshot `last-run.tree` de los ficheros sucios.
- Pasada parcial / truncada / results-only: solo merge de resultados de test files ya conocidos; sin sha, sin poda, sin aristas.
- Siempre: recalcular `k` de cada test file tocado y, si hay remoto, `put(objects/<k>.json)`.

---

## 8. Hermeticidad (lo que Pest no hace)

`Hermeticity\Policy::cacheable(testFile, testId): bool` combina:

1. **Atributo** `#[NotCacheable(reason: '')]` en clase o método (leído por reflexión al grabar; se persiste en `not_cacheable`).
2. **Globs** en config: `'never_cache' => ['tests/Feature/External/**', 'tests/Browser/**']`.
3. **Cuarentena automática** (`flaky.json`): al fusionar resultados, si para un `testId` la clave `k` coincide con la cacheada pero el estado cambió (pass↔fail, pass↔error), se anota `{testId, firstSeen, flips: n}`. Con `flips >= 1` el test se ejecuta siempre y se muestra en `phpunit-replay status`. Se sale de cuarentena con `phpunit-replay prune --flaky` o tras N pasadas consecutivas estables (config `quarantine_release_after: 20`).
4. **Heurística opcional** (`hermeticity_heuristics: true`, off por defecto): si las aristas de un test incluyen ficheros que casan con `**/Carbon/**`, `**/Faker/**` sin seed, o el test usa `Http::` sin `Http::fake()` (detectado por aristas a `Illuminate/Http/Client/PendingRequest.php` sin `Factory.php` de fakes), se marca "sospechoso" en `status`, no se descachea automáticamente.

---

## 9. Caché remota (content-addressed)

```php
interface RemoteCache {
    public function get(string $key): ?string;       // null si no existe
    public function put(string $key, string $body): void;
    public function has(string $key): bool;
}
```

Claves: `graph/<project-key>/<branch>.json` (grafo completo de baseline por rama, subido tras pasadas completas) y `objects/<k>.json` (resultados de un test file por clave de contenido).

Flujo de arranque sin grafo local: `get(graph/<key>/<branch>)` → si no, `get(graph/<key>/<defaultBranch>)` → reconciliar fingerprint y ancestría del sha → usar. Flujo por test file en replay: si un test file está afectado pero `objects/<k_actual>.json` existe en el remoto (otra máquina ya ejecutó exactamente ese contenido) → se trata como **replayed-remote** y no se ejecuta. Esto es lo que permite que el CI de una PR herede el trabajo del portátil o de otra PR con los mismos ficheros.

Implementaciones v1: `FilesystemRemoteCache` (cualquier ruta: NFS, `rclone mount`, volumen compartido), `HttpRemoteCache` (GET/PUT/HEAD con token Bearer opcional; funciona contra S3/MinIO con URLs prefirmadas o un nginx con `dav_methods PUT`). Config:

```php
// phpunit-replay.php
return [
    'state_dir' => null,                       // null = ~/.phpunit-replay/<key>
    'remote'    => env('PHPUNIT_REPLAY_REMOTE'), // 'file:///mnt/replay-cache' | 'https://cache.example.com/replay/'
    'remote_token' => env('PHPUNIT_REPLAY_REMOTE_TOKEN'),
    'default_branch' => null,                  // null = autodetect (origin/HEAD, init.defaultBranch)
    'watch' => [],
    'never_cache' => [],
    'quarantine_release_after' => 20,
    'laravel' => 'auto',                       // auto|on|off
    'junit_merge' => true,
];
```

Fallos de red nunca rompen la pasada: aviso y se continúa en local.

---

## 10. Integración Laravel (opcional, autodetectada)

Se activa si `class_exists(\Illuminate\Container\Container::class)` y existe `artisan`. Los trackers se arman **una vez por instancia de aplicación**, desde el subscriber de `Test\Prepared` (la app ya está booteada porque `Prepared` se emite tras `setUp`): se obtiene `Container::getInstance()`, se comprueba un binding marcador `phpunit-replay.armed` y si no existe:

- `TableTracker`: `$app['db']->listen(fn (QueryExecuted $q) => foreach (TableExtractor::fromSql($q->sql) as $t) $recorder->linkTable($t))`. `fromSql` solo mira DML (`select|insert|update|delete|with|replace`) y extrae identificadores tras `from|into|update|join`, quitando comillas, esquema, y descartando `migrations`, `sqlite_*`, `pg_*`, `information_schema*`.
- `BladeTracker`: `$app['view']->composer('*', fn ($view) => $recorder->linkSource($view->getPath()))`.
- `MigrationTables`: al escribir el grafo, todo test file cuya clase use `RefreshDatabase|DatabaseMigrations|DatabaseTransactions` recibe además **todas** las tablas de todas las migraciones (conservador: cualquier migración afecta a cualquier test de BD).

Nada de esto requiere Pest ni toca el código del usuario.

---

## 11. CLI

```
phpunit-replay run [--filtered|--in-process] [--fresh] [--no-remote] [--explain] [--log-junit=FILE] [--dry-run] [-- <phpunit args>]
phpunit-replay record [--fresh]
phpunit-replay verify [-- <phpunit args>]   # suite completa + comparación con caché (§12.2)
phpunit-replay status            # grafo: ficheros, aristas, ramas, tamaño, cuarentena, fingerprint
phpunit-replay explain <path>    # qué tests afectaría cambiar ese fichero y por qué regla
phpunit-replay prune [--flaky] [--branches] [--all]
phpunit-replay baseline-path     # imprime el directorio de estado (para subir artefactos en CI)
phpunit-replay push / pull       # sincronización manual con el remoto
```

`run` sin subcomando es el default: `vendor/bin/phpunit-replay -- --testdox`.

Salida: PHPUnit imprime lo suyo; el wrapper añade al final una línea con formato estable (parseable) y colores si TTY:

```
Replay  ✓ 38 executed (31 affected, 7 uncached) · 1202 replayed (14 from remote) · 2 quarantined · baseline main@a1b2c3d · saved 4m12s
```

`--explain` imprime, por test file afectado, la regla y el fichero que lo provocó:

```
tests/Feature/AdTest.php        ← PhpEdge  app/Services/Pricing.php
tests/Feature/BillingTest.php   ← Watch    config/billing/plans.php (config/billing/** → tests/Feature/Billing)
tests/Unit/PricingTest.php      ← TestFile tests/Unit/PricingTest.php
```

Variables de entorno: `PHPUNIT_REPLAY=1` equivale a `run`; `PHPUNIT_REPLAY=0` desactiva incluso con la extensión registrada; `PHPUNIT_REPLAY_MODE`, `PHPUNIT_REPLAY_STATE_DIR`, `PHPUNIT_REPLAY_REMOTE`, `PHPUNIT_REPLAY_RUN_ID` son internas wrapper→extensión.

---

## 12. CI

Recomendación documentada en el README, igual que Pest: **el CI de PRs ejecuta la suite completa**; un workflow separado en `main` graba el baseline y lo publica al remoto (`phpunit-replay record --fresh && phpunit-replay push`). Los desarrolladores hacen `phpunit-replay pull` implícito al arrancar sin grafo. Opcionalmente, un job de PR "rápido" con `phpunit-replay run` que informa en minutos, seguido del job completo como gate.

---

### 12.1 Requisitos en CI

- Caché remota configurada (`PHPUNIT_REPLAY_REMOTE`); sin ella cada job efímero sería una grabación completa.
- Checkout con historia suficiente para que el sha del baseline sea ancestro de HEAD (`fetch-depth: 0` o equivalente).
- pcov en el runner para regrabar aristas de los tests afectados.
- El wrapper detecta `CI=true` y en ese caso: no escribe el grafo local como baseline de rama salvo `--allow-ci-baseline`, sube solo `objects/<k>.json` de los ficheros ejecutados, y hace `pull` del baseline de la rama por defecto.

### 12.2 Modo `verify` y métrica de divergencia

`phpunit-replay verify -- <args>` ejecuta la suite **completa** con la extensión en modo `record`, y al terminar compara cada resultado real con el que la caché *habría* replayado para ese `testId`/`k`. Cualquier diferencia (pass cacheado que hoy falla, o viceversa) se registra en `divergence.json` (`{testId, k, cached, actual, sha, at}`), se mete en cuarentena automáticamente y se imprime:

```
Verify  ✓ 1240 tests · 1198 would replay · 0 divergences (lifetime: 2 in 143 runs)
```

Este es el comando del carril completo en `main`/nightly. La cifra `lifetime divergences` es el dato objetivo para decidir cuándo el carril rápido puede convertirse en gate de PR. `status` muestra la serie histórica.

## 13. Paralelo (fase 2)

Soporte de Paratest: el wrapper detecta `--parallel`/`-p` y lanza `vendor/bin/paratest` con la misma configuración filtrada. Los workers heredan `PHPUNIT_REPLAY_*` por entorno; cada uno escribe `runs/<run-id>/worker-<TEST_TOKEN>-{edges,results}.json`; el wrapper fusiona por unión (aristas) y por último-escribe (resultados). Se pasa `-d pcov.directory` a los workers vía `--passthru-php`.

---

## 14. Fases de implementación

**Fase 1 — núcleo (objetivo: usable en un proyecto real)**
ContentHash, Fingerprint, Git, ChangedFiles, LastRunTree, Graph + GraphStore, SourceScope, drivers pcov/Xdebug, Recorder, ResultCollector + subscribers, ReplayExtension, ReplayState, Selector con PhpEdgeRule + TestFileRule + WatchRule, CLI `run` (filtered) / `record` / `status` / `baseline-path`, Summary, JUnitMerger. Tests unitarios + 1 fixture de proyecto plain.

**Fase 2 — fidelidad y ergonomía**
Trait Replayable (in-process), `explain`, `prune`, Hermeticity (atributo, globs, cuarentena), Laravel (TableTracker, BladeTracker, MigrationTables, SiblingRule, BladeRule), Paratest. Fixture laravel-lite.

**Fase 3 — distribución**
RemoteCache filesystem + HTTP, `push/pull`, replayed-remote por `k`, CoverageMerger para `--coverage-php`, heurísticas de hermeticidad, docs y GitHub Action de ejemplo.

---

## 15. Tests del propio paquete

- **Unit**: ContentHash (comentarios/espacios no cambian hash; renombrar variable sí), Fingerprint drift estructural vs ambiental, Graph encode/decode round-trip y hostilidad (JSON corrupto, secciones mal tipadas), ChangedFiles con repo git temporal (commit, modificar, revertir, borrar, renombrar, untracked, ignorado), Selector por regla, ResultCollector precedencia de estados, TableExtractor con SQL variado y migraciones, Policy y cuarentena.
- **Integration** (ejecutan PHPUnit real sobre `tests/Fixtures/Projects/plain` en un tmp con git init): (1) primera pasada graba grafo; (2) segunda pasada sin cambios ejecuta 0 y replaya todo, exit 0; (3) cambiar un fuente ejecuta solo sus dependientes; (4) cambio solo de comentario ejecuta 0; (5) test que falla se guarda y vuelve a ejecutarse aunque nada cambie; (6) `--filter` no toca aristas ni sha; (7) `composer.lock` distinto fuerza record; (8) test nuevo en fichero conocido se ejecuta; (9) test borrado se poda; (10) modo in-process replaya como pass con las aserciones originales y `--fail-on-risky` no se dispara; (11) JUnit fusionado contiene todos los tests; (12) test en cuarentena siempre se ejecuta.
- CI del paquete: matriz PHP 8.2/8.3/8.4 × PHPUnit 11.5/12 × driver pcov/xdebug.

---

## 16. Criterios de aceptación de la v0.1

1. En un proyecto PHPUnit 11.5+ sin modificar sus tests, `vendor/bin/phpunit-replay` en segunda pasada sin cambios termina en < 2 s + tiempo de bootstrap, con exit 0 y resumen "0 executed, N replayed".
2. Cambiar un fichero fuente ejecuta exactamente los test files con arista a él (verificable con `--explain`).
3. Un test que falló nunca se replaya.
4. Un cambio solo de comentarios/espacios no ejecuta nada.
5. Cambiar `composer.lock` o `phpunit.xml` provoca grabación completa con aviso claro.
6. `--filter`, `--group`, `--testsuite` o una ruta explícita ejecutan lo pedido y no corrompen el grafo.
7. Sin pcov ni Xdebug el paquete se desactiva con un aviso y PHPUnit funciona con normalidad.
8. Los ficheros del estado se escriben de forma atómica; un `Ctrl-C` a mitad nunca deja un `graph.json` corrupto.

---

## 16b. Reutilización de código de Pest (MIT)

Pest (`pestphp/pest`, © Nuno Maduro, licencia MIT) contiene en `src/Plugins/Tia/` un motor TIA cuyo 60% es agnóstico de Pest. Se porta por copia con cambio de namespace, **nunca** como dependencia Composer (clases `@internal`, arrastra el framework y sus funciones globales). Referencia: commit `17d709e32bed028005c8e8a825c7161d73af3468` (2026-09-04).

| Fichero Pest (`src/Plugins/Tia/`) | Destino | Acción |
|---|---|---|
| `ContentHash.php`, `TableExtractor.php` | `Cache/ContentHash.php`, `Laravel/TableExtractor.php` | copiar tal cual |
| `FileState.php`, `Storage.php` | `Cache/GraphStore.php`, `Cache/ProjectKey.php` | copiar; ruta `~/.phpunit-replay` |
| `ResultCollector.php` | `Record/ResultCollector.php` | copiar tal cual (solo usa `TestStatus`) |
| `SourceScope.php` | `Record/SourceScope.php` | copiar tal cual (usa `Registry`) |
| `Fingerprint.php` | `Cache/Fingerprint.php` | copiar; sustituir lista de ficheros estructurales por la de §4.5 |
| `ChangedFiles.php` + `src/Support/Git.php` | `Change/ChangedFiles.php`, `Change/Git.php` | copiar; quitar `MissingDependency` de Pest |
| `Recorder.php` | `Record/Recorder.php` | copiar; `TestSuite::getInstance()->rootPath` → raíz inyectada; `$__filename` → `ReflectionClass::getFileName()` |
| `TestPaths.php`, `WatchPatterns.php`, `WatchDefaults/*` | `Select/…` | copiar; inyectar raíz; eliminar `WatchDefaults\Browser` (depende de pest-plugin-browser) |
| `CoverageMerger.php` | `Report/CoverageMerger.php` (fase 3) | copiar; quitar `Pest\Support\Container` |
| `Graph.php` | `Cache/Graph.php` + `Select/Selector.php` + `Select/Rules/*` | adaptar: separar modelo (encode/decode/baselines/poda) de selección (`affected()` y `apply*`); reescribir `archTestFiles()` con reflexión sobre `#[Group('arch')]`; eliminar `View::render`, `Container`, `TestCaseFactory` |
| `src/Subscribers/EnsureTia*.php` | `PHPUnit/Subscribers/*` | copiar; son subscribers PHPUnit estándar; descartar `EnsureTiaIsRunningPestTestsOnly` |
| `tests/Unit/Plugins/Tia/*`, `tests/Fixtures/Suites/Tia*` | `tests/Unit/*`, `tests/Fixtures/*` | portar de sintaxis Pest a clases PHPUnit; conservan los casos límite |
| `Tia.php` (plugin, 2.300 líneas), `Concerns/Testable.php`, `Plugins/Tia/BaselineSync.php`, `JsModuleGraph.php`, `Restarters/*` | — | **no portar**: se sustituyen por `Console/RunCommand`, `PHPUnit/ReplayExtension`, `Replayable`, `Cache/Remote/*` |

Atribución obligatoria: fichero `LICENSE-PEST.md` con el texto MIT original; párrafo en README ("Portions derived from Pest, © Nuno Maduro, MIT"); docblock en cada fichero portado: `@see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/<File>.php`. No usar "Pest" en el nombre ni sugerir afiliación.

## 17. Prompt para Claude Code

Copia desde aquí:

```
Vas a crear desde cero el paquete Composer `manuglopez/phpunit-replay` siguiendo al pie de la letra la especificación adjunta (phpunit-replay-spec.md). Es una librería de Test Impact Analysis y replay de resultados para PHPUnit 11.5+/12, sin dependencia de Pest.

Reglas de trabajo:
- Antes de escribir nada, clona `https://github.com/pestphp/pest` (checkout del commit 17d709e32bed028005c8e8a825c7161d73af3468) en un directorio temporal y porta los ficheros según la tabla de la sección 16b de la spec: copia, cambia el namespace a Manuglopez\Replay\..., elimina las dependencias de Pest indicadas y añade el docblock @see de origen. Crea LICENSE-PEST.md con el MIT original. Solo escribe desde cero lo que la tabla marca como "no portar" o lo que no existe en Pest (wrapper CLI, ReplayExtension, trait Replayable, caché remota, cuarentena, JUnitMerger).
- PHP 8.2+, strict_types en todos los ficheros, `final` por defecto, readonly donde aplique, PSR-12, PHPStan nivel max limpio.
- Implementa la Fase 1 completa antes de tocar nada de las fases 2 y 3. Dentro de la fase 1, este orden: Cache/ContentHash → Cache/Fingerprint → Change/Git + ChangedFiles + LastRunTree → Cache/Graph + GraphStore → Record/SourceScope + drivers + Recorder → Record/ResultCollector + PHPUnit/Subscribers → PHPUnit/ReplayState + ReplayExtension → Select/TestPaths + WatchPatterns + Selector (reglas PhpEdge, TestFile, Watch) → Console (run filtered, record, status, baseline-path) → Report/Summary + JUnitMerger.
- Cada componente lleva sus tests unitarios antes de pasar al siguiente. Los tests de integración usan un proyecto fixture real en tests/Fixtures/Projects/plain (con phpunit.xml, src/ y tests/), copiado a un tmp con `git init` + commit inicial, y ejecutan PHPUnit real por subproceso.
- Verifica contra el código fuente de PHPUnit instalado en vendor/ (no de memoria) la firma exacta de: PHPUnit\Runner\Extension\Extension::bootstrap, los eventos PHPUnit\Event\Test\* que se usan y sus métodos (test()->id(), test()->file(), numberOfAssertionsPerformed(), wasSuppressed(), throwable()->message()), PHPUnit\TextUI\Configuration\Configuration (source(), testSuite(), failOn*, displayDetailsOn*, hasFilter, hasGroups, includeTestSuite, cliArguments), PHPUnit\Framework\TestCase::runTest, valueObjectForEvents, addToAssertionCount, expectNotToPerformAssertions, y PHPUnit\Framework\TestStatus\TestStatus::asInt(). Si algo difiere entre 11.5 y 12, abstrae la diferencia en PHPUnit/ConfigurationReader.
- El driver pcov debe usarse con la API cruda (\pcov\start, \pcov\stop, \pcov\waiting, \pcov\collect(\pcov\inclusive, $files), \pcov\clear). Comprueba que ext-pcov está disponible en el entorno; si no, instálala (pecl install pcov) para poder ejecutar los tests de integración con driver real, y ejecuta también la matriz con Xdebug si está disponible.
- El wrapper CLI nunca debe ocultar la salida de PHPUnit ni cambiar su exit code. Los ficheros de estado se escriben con tmp + rename. Cualquier error del propio paquete (git no disponible, JSON corrupto, remoto caído) degrada a "ejecutar PHPUnit normal con un aviso", jamás a romper la pasada.
- README.md en inglés con: qué es, comparación honesta con Pest 5 TIA y phpunit-tia, instalación, los dos modos, configuración, CI, limitaciones conocidas (aristas a nivel de fichero, #[Depends], tests no herméticos, sin cobertura fusionada hasta fase 3).
- Al terminar la fase 1: composer validate, phpstan, toda la suite verde con pcov, y un informe corto de qué quedó fuera y por qué. Después continúa con la fase 2 en el orden de la spec.
```
