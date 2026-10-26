# laravel-lite fixture

A reduced Laravel application used by the package's Laravel integration tests
(SPEC.md §10, docs/INTERNALS.md "Laravel"): 3 migrations (`users`, `posts`, `comments`),
2 models (`User`, `Post`), 3 routes (`/`, `/posts`, `/posts/{post}`), 2 Blade views plus a
partial, and 12 tests (4 Feature tests plus 8 under `tests/Feature/Translations/`).

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
# OK (12 tests, 19 assertions)
```

`tests/Support/FixtureProject::laravelLiteAvailable()` returns `false` (and the integration
tests in `tests/Integration/LaravelLiteFixtureTest.php` are skipped) whenever this `vendor/`
has not been installed.

## Translations

`lang/` exists for the translation-key tests (`translations => 'per-key'`). Locale `en` is the
default and the fallback; `es` is the other one, and lacks `messages.only_en` on purpose so that
`es` falls back to `en` for it. Nothing in the application reads these files: only
`tests/Feature/Translations/` does, and each file asserts the exact translated text.

| Lang file | Keys |
|---|---|
| `lang/{en,es}.json` | `Greetings, :name` (used), `Nobody uses this JSON key` (unused) |
| `lang/{en,es}/messages.php` | `welcome`, `apples` (plural), `nested.title`, `nested.body`, `unused` (unused); `only_en` is `en` only |
| `lang/en/validation.php` | `required`, `attributes` (minimal) |

What the recorder must store per test file (`test_translations`; `<locale>:<key>`, the fallback
chain included — an `es` lookup is also an `en` one):

| Test file | Entries |
|---|---|
| `GroupKeyTest` | `en:messages.welcome`, `es:messages.welcome` |
| `GroupSubtreeTest` | `en:messages.nested` (the whole subtree) |
| `JsonKeyTest` | `en:Greetings, :name`, `es:Greetings, :name` |
| `PluralFallbackTest` | `en:`/`es:` × `messages.apples` (`trans_choice` in `es`), `messages.only_en`, `messages.welcome` (shared with `GroupKeyTest`) |
| `ValidationMessageTest` | `en:validation.required`, `en:validation.attributes`, `en:validation.custom`, `en:validation.custom.name.required` |
| `SwappedTranslatorTest` | `*` (`Lang::swap()`: not traceable) |
| `NoTranslationsTest` and the 4 original Feature tests | no entry |

Never read by any test: `Nobody uses this JSON key` (both locales) and `messages.unused` (both
locales). `tests/Integration/LaravelLiteTranslationsTest.php` asserts the table above exactly.
