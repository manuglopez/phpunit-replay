<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_it_renders_the_welcome_view(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('welcome');
    }
}
