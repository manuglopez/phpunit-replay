<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_posts(): void
    {
        $user = User::factory()->create();
        Post::factory()->for($user)->create(['title' => 'Hello World']);

        $response = $this->get('/posts');

        $response->assertOk();
        $response->assertViewIs('posts.index');
        $response->assertSee('Hello World');
    }
}
