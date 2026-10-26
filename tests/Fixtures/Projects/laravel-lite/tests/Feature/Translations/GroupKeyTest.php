<?php

declare(strict_types=1);

namespace Tests\Feature\Translations;

use Tests\TestCase;

class GroupKeyTest extends TestCase
{
    public function test_a_group_key_is_translated_in_english_and_spanish(): void
    {
        $this->assertSame('Welcome', trans('messages.welcome'));

        $this->app->setLocale('es');

        $this->assertSame('Bienvenido', trans('messages.welcome'));
    }
}
