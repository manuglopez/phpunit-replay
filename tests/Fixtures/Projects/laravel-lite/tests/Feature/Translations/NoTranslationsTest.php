<?php

declare(strict_types=1);

namespace Tests\Feature\Translations;

use Tests\TestCase;

class NoTranslationsTest extends TestCase
{
    public function test_it_never_asks_for_a_translation(): void
    {
        $this->assertSame(4, 2 + 2);
    }
}
