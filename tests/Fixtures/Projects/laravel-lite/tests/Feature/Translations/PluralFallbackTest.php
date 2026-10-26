<?php

declare(strict_types=1);

namespace Tests\Feature\Translations;

use Tests\TestCase;

class PluralFallbackTest extends TestCase
{
    public function test_a_plural_in_spanish_is_recorded_for_spanish_and_its_english_fallback(): void
    {
        $this->app->setLocale('es');

        $this->assertSame('3 manzanas', trans_choice('messages.apples', 3));
    }

    public function test_a_key_missing_in_spanish_is_served_by_the_english_fallback(): void
    {
        $this->app->setLocale('es');

        $this->assertSame('Only in English', __('messages.only_en'));
        // Shared with GroupKeyTest: two test files read `messages.welcome`.
        $this->assertSame('Bienvenido', __('messages.welcome'));
    }
}
