# Decisions log

Deviations from SPEC.md and other non-obvious choices, with reasons. Newest last.

## D-001 — pcov enabled per process, not globally

The build machine ships pcov 1.0.12 with `pcov.enabled=0` in `/etc/php84/conf.d/20-pcov.ini`
and no passwordless sudo. The wrapper already launches PHPUnit with `-d pcov.enabled=1`
(SPEC §2.4), so the package's own suite runs with `composer test`
(`php -d pcov.enabled=1 vendor/bin/phpunit`) instead of editing system ini files.
Driver detection distinguishes "extension loaded" (wrapper: can enable it) from
"loaded and enabled" (extension inside PHPUnit: can record now).

## D-002 — Xdebug matrix not run locally

Xdebug is not installed (`pacman -S xdebug` needs sudo). `XdebugDriver` is implemented
against the documented API and unit-tested with the function-existence guard; the
Xdebug integration matrix is left to CI (`.github/workflows/ci.yml`, phase 3).

## D-003 — Dev tooling

`phpstan/phpstan` (level max) and `laravel/pint` (PSR-12 preset + strict_types) as
dev dependencies. Pint chosen over raw php-cs-fixer for zero-config PSR-12.

## D-004 — `TestCase::runTest()` is private: in-process replay needs another hook

SPEC §2.2 and §6.3 assume `protected function runTest(): mixed` is overridable. Verified against
`vendor/phpunit/phpunit/src/Framework/TestCase.php` (12.5.34, line 1348) and a scratch install of
11.5.56 (line 1662): it is `private` in both. Plan for phase 2 (`Replayable` trait):

- PHPUnit 12.x exposes `protected function invokeTestMethod(string $methodName, array $testArguments): mixed`
  (12.5.34 line 1315), called from `runTest()`. The trait overrides it and short-circuits with the
  cached assertion count. PHPUnit emits `Test\CustomTestMethodInvocationUsed` when it is overridden;
  that event is informational.
- PHPUnit 11.5 has no such hook. Fallback: a `#[Before]`-phase hook in the trait swaps the private
  `methodName` property (via reflection) to a trait stub that adds the cached assertions and restores
  the property. `valueObjectForEvents()` is already cached at that point, so test ids stay intact.
  Fragile by nature; covered by an integration test per PHPUnit major and documented as a limitation.

## D-005 — `ContentHash::ofContent(string $content, string $pathForType)` argument order

Pest's original is `ofContent(string $path, string $raw)`. SPEC §4.4 defines
`ofContent(string $content, string $pathForType)`. The spec order is used; the port swaps the
parameters and is covered by the ported unit tests.

## D-006 — Xdebug for the local matrix built from source

The system `xdebug` package targets PHP 8.5 while `php` resolves to 8.4.23
(`undefined symbol: php_globfree` when loading it). Xdebug 3.5.3 is compiled for 8.4 with
`phpize84` in the session scratchpad and loaded with `-d zend_extension=<path>/xdebug.so -d xdebug.mode=coverage`
for the Xdebug run of the suite (`composer test:xdebug` takes the path from `XDEBUG_SO`).

## D-007 — All graph merging lives in one service

The wrapper (filtered mode) and, in phase 2, the extension (in-process mode) both need SPEC §7.3
(replace edges, merge results, prune, recompute `k`). Rather than two implementations, the
extension writes run partials (`runs/<run-id>/*.json`) and a single `Cache\GraphUpdater` applies
them to the graph. In-process mode calls the same service at `ExecutionFinished`.

## D-008 — Package name `manuglopez/phpunit-replay`, namespace `Orlegitech\Replay`

The repository lives at `github.com/manuglopez/phpunit-replay` and the Composer package is
`manuglopez/phpunit-replay` (owner's instruction, 2026-09-06). The PHP namespace stays
`Orlegitech\Replay` as in SPEC §1; the CLI binary stays `phpunit-replay`.
