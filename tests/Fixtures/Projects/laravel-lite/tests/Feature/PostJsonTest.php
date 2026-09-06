<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_post_as_json(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        $response = $this->getJson('/posts/' . $post->id);

        $response->assertOk();
        $response->assertJson([
            'id' => $post->id,
            'title' => $post->title,
        ]);
    }
}
