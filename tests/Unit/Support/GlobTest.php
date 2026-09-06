<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Support;

use Manuglopez\Replay\Support\Glob;
use PHPUnit\Framework\TestCase;

final class GlobTest extends TestCase
{
    public function test_star_matches_within_one_segment_only(): void
    {
        self::assertTrue(Glob::matches('tests/Feature/*.php', 'tests/Feature/AdTest.php'));
        self::assertFalse(Glob::matches('tests/Feature/*.php', 'tests/Feature/Sub/AdTest.php'));
    }

    public function test_double_star_matches_any_depth_including_none(): void
    {
        self::assertTrue(Glob::matches('tests/Browser/**', 'tests/Browser/LoginTest.php'));
        self::assertTrue(Glob::matches('tests/Browser/**', 'tests/Browser/Sub/Deep/LoginTest.php'));
        self::assertTrue(Glob::matches('tests/Feature/External/**', 'tests/Feature/External/PaymentTest.php'));
        self::assertFalse(Glob::matches('tests/Browser/**', 'tests/Unit/LoginTest.php'));
    }

    public function test_question_mark_matches_exactly_one_non_separator_character(): void
    {
        self::assertTrue(Glob::matches('tests/Foo?Test.php', 'tests/FooATest.php'));
        self::assertFalse(Glob::matches('tests/Foo?Test.php', 'tests/FooTest.php'));
        self::assertFalse(Glob::matches('tests/Foo?Test.php', 'tests/FooABTest.php'));
    }

    public function test_literal_characters_are_matched_exactly_and_regex_metacharacters_are_escaped(): void
    {
        self::assertTrue(Glob::matches('tests/Foo.Test.php', 'tests/Foo.Test.php'));
        self::assertFalse(Glob::matches('tests/Foo.Test.php', 'tests/FooXTest.php'));
    }

    public function test_backslashes_in_pattern_and_path_are_normalised_to_forward_slashes(): void
    {
        self::assertTrue(Glob::matches('tests\\Feature\\*.php', 'tests/Feature/AdTest.php'));
        self::assertTrue(Glob::matches('tests/Feature/*.php', 'tests\\Feature\\AdTest.php'));
    }

    public function test_a_non_matching_pattern_is_false(): void
    {
        self::assertFalse(Glob::matches('config/billing/**', 'tests/Feature/AdTest.php'));
    }
}
