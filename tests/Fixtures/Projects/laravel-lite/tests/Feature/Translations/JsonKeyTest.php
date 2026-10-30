<?php

declare(strict_types=1);

namespace Tests\Feature\Translations;

use Tests\TestCase;

class JsonKeyTest extends TestCase
{
    public function test_a_json_key_is_translated_in_english_and_spanish(): void
    {
        $this->assertSame('Greetings, Ana', __('Greetings, :name', ['name' => 'Ana']));

        $this->app->setLocale('es');

        $this->assertSame('Saludos, Ana', __('Greetings, :name', ['name' => 'Ana']));
    }
}
