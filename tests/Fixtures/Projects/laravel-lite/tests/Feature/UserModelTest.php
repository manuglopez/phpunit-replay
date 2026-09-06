<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_a_user(): void
    {
        User::factory()->create(['name' => 'Ada Lovelace']);

        $this->assertDatabaseHas('users', ['name' => 'Ada Lovelace']);
    }
}
