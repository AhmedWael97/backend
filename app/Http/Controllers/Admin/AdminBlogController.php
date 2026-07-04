<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminBlogController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success(BlogPost::latest()->get());
    }

    public function show(int $id): JsonResponse
    {
        return $this->success(BlogPost::findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title_en']);
        $data['cover_image'] = $this->handleImage($request);
        $data['published_at'] = ($data['status'] ?? 'draft') === 'published' ? now() : null;

        return $this->success(BlogPost::create($data), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $post = BlogPost::findOrFail($id);
        $data = $this->validateData($request, $post->id);
        if (!empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $post->id);
        }
        $img = $this->handleImage($request);
        if ($img) {
            $data['cover_image'] = $img;
        }
        // Set published_at when transitioning to published, keep it otherwise.
        if (($data['status'] ?? $post->status) === 'published' && !$post->published_at) {
            $data['published_at'] = now();
        }
        $post->update($data);

        return $this->success($post->refresh());
    }

    public function destroy(int $id): JsonResponse
    {
        BlogPost::findOrFail($id)->delete();
        return $this->success(['message' => 'Deleted.']);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'excerpt_en' => ['nullable', 'string', 'max:500'],
            'excerpt_ar' => ['nullable', 'string', 'max:500'],
            'body_en' => ['nullable', 'string'],
            'body_ar' => ['nullable', 'string'],
            'slug' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published'],
        ]);
    }

    private function handleImage(Request $request): ?string
    {
        if (!$request->hasFile('cover_image')) {
            return null;
        }
        $request->validate(['cover_image' => ['image', 'max:4096']]);
        return $request->file('cover_image')->store('blog', 'public');
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base) ?: 'post';
        $orig = $slug;
        $i = 1;
        while (BlogPost::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $orig . '-' . (++$i);
        }
        return $slug;
    }
}
