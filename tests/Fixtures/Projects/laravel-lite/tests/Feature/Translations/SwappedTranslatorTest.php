<?php

declare(strict_types=1);

namespace Tests\Feature\Translations;

use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class SwappedTranslatorTest extends TestCase
{
    public function test_a_swapped_translator_cannot_be_traced(): void
    {
        Lang::swap(new class () {
            public function get(string $key): string
            {
                return 'swapped:' . $key;
            }
        });

        $this->assertSame('swapped:messages.welcome', __('messages.welcome'));
    }
}
