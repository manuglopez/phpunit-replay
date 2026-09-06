# plain-variants

Overlay files applied on top of a fresh `FixtureProject::plain()` copy by integration tests
(`FixtureProject::applyVariant($variantFile, $targetRel)`), to exercise the scenarios in
SPEC.md §15 without hand-editing the fixture during a test run.

| Variant file | Target path (relative to the fixture root) | What it does |
|---|---|---|
| `CommentedTest.comment-only.php` | `tests/CommentedTest.php` | Same logic as the original file; only comments, docblocks and whitespace differ. Used for the "cosmetic-only change executes nothing" scenario. |
| `Money.comment-only.php` | `src/Money.php` | Same logic as the original file; only comments and docblocks differ. Used for the "cosmetic-only change executes nothing" scenario on a source file. |
| `Money.behaviour.php` | `src/Money.php` | Real behaviour change: adds a public `negate()` method. Not called by any existing test, so the whole fixture suite stays green while the token stream (and therefore the content hash) changes. Used for the "source behaviour change re-executes dependents" scenario. |
| `Discount.broken.php` | `src/Discount.php` | Removes the zero-clamp on the flat-discount branch of `Discount::apply()`. Makes exactly one test fail: `DiscountTest::testFlatDiscountLargerThanPriceClampsToZero`. Used for the "a changed dependency breaks exactly one test" scenario. |
| `MoneyTest.extra-test.php` | `tests/MoneyTest.php` | `MoneyTest` with every original test method plus one new one (`testZeroIsNeitherNegativeNorPositive`). Used for the "new test in a known file is executed" scenario. |
