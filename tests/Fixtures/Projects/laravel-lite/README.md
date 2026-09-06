# laravel-lite fixture

A reduced Laravel application used by the package's Laravel integration tests
(SPEC.md §10, docs/INTERNALS.md "Laravel"): 3 migrations (`users`, `posts`, `comments`),
2 models (`User`, `Post`), 3 routes (`/`, `/posts`, `/posts/{post}`), 2 Blade views plus a
partial, and 4 Feature tests.

Installed version: **Laravel `laravel/framework` v13.30.1**, via
`composer create-project laravel/laravel` on 2026-09-06 (PHP 8.4, PHPUnit `^12.5.12`
resolved to 12.5.34). `manuglopez/phpunit-replay` itself is required as a `path` repository
(`"url": "../../../.."`, `"options": {"symlink": true}`) pointing at the package root, so its
`vendor/manuglopez/phpunit-replay` is a symlink — no separate copy of the package is ever
installed here.

## (Re)installing vendor/

`vendor/` is gitignored and never committed. To (re)install it:

```sh
cd tests/Fixtures/Projects/laravel-lite
composer install --no-interaction
```

Then verify the fixture's own suite is green:

```sh
php -d pcov.enabled=1 -d pcov.directory=$(pwd) vendor/bin/phpunit
# OK (4 tests, 8 assertions)
```

`tests/Support/FixtureProject::laravelLiteAvailable()` returns `false` (and the integration
tests in `tests/Integration/LaravelLiteFixtureTest.php` are skipped) whenever this `vendor/`
has not been installed.
