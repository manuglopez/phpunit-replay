<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class PostController extends Controller
{
    public function index(): View
    {
        return view('posts.index', [
            'posts' => Post::query()->latest('id')->get(),
        ]);
    }

    public function show(Post $post): JsonResponse
    {
        return response()->json([
            'id' => $post->id,
            'title' => $post->title,
            'body' => $post->body,
        ]);
    }
}
