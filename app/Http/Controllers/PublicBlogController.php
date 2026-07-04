<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;

class PublicBlogController extends Controller
{
    /** GET /blog — published posts (list cards). */
    public function index(): JsonResponse
    {
        $posts = BlogPost::published()
            ->latest('published_at')
            ->get(['id', 'slug', 'title_en', 'title_ar', 'excerpt_en', 'excerpt_ar', 'cover_image', 'published_at']);

        return $this->success($posts);
    }

    /** GET /blog/{slug} — single published post. */
    public function show(string $slug): JsonResponse
    {
        $post = BlogPost::published()->where('slug', $slug)->firstOrFail();
        return $this->success($post);
    }
}
