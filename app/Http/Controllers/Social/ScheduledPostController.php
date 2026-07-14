<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Models\ScheduledPost;
use App\Services\AnthropicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AI-assisted post composer + queue. No server-side auto-publish (see
 * extension README) — a queued post is "due" once `scheduled_at` passes, and
 * the Chrome extension fills it into the compose box + notifies the user
 * next time that platform's tab is open. The user still clicks Post.
 *
 *   GET    /scheduled-posts                — list mine
 *   POST   /scheduled-posts/generate-text  — AI draft (Claude), not saved
 *   POST   /scheduled-posts/generate-image — AI image (user's own OpenAI key), not saved
 *   POST   /scheduled-posts                — save a queued post
 *   PUT    /scheduled-posts/{id}           — edit
 *   DELETE /scheduled-posts/{id}
 *   GET    /scheduled-posts/due            — polled by the extension
 *   POST   /scheduled-posts/{id}/status    — extension/user reports filled/posted
 */
class ScheduledPostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = ScheduledPost::where('user_id', $request->user()->id)
            ->orderByDesc('scheduled_at')
            ->limit(200)
            ->get();
        return $this->success($items);
    }

    public function generateText(Request $request, AnthropicService $ai): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'in:facebook,x,instagram'],
            'language' => ['required', 'string', 'max:10'],
            'prompt' => ['required', 'string', 'max:2000'],
        ]);

        $limits = ['x' => 280, 'facebook' => 2000, 'instagram' => 2200];
        $limit = $limits[$data['platform']];

        $system = "You write social media posts for a business account on {$data['platform']}. "
            . "Write in the language: {$data['language']}. Keep it under {$limit} characters, "
            . "no hashtag spam (max 3), no false claims. Return ONLY JSON: {\"content\": \"...\"}.";

        $content = '';
        try {
            $res = $ai->complete($system, $data['prompt'], 500);
            $content = (string) ($res['content'] ?? '');
        } catch (\Throwable $e) {
            report($e);
            return $this->error('AI text generation failed (check Anthropic config).', 502);
        }

        return $this->success(['content' => $content]);
    }

    public function generateImage(Request $request): JsonResponse
    {
        $data = $request->validate(['prompt' => ['required', 'string', 'max:2000']]);

        $user = $request->user();
        $key = $user->openai_api_key;
        if (!$key) {
            return $this->error('Add your OpenAI API key in Social Settings first.', 422);
        }

        $response = Http::withToken($key)
            ->timeout(90)
            ->post('https://api.openai.com/v1/images/generations', [
                'model' => 'dall-e-3',
                'prompt' => $data['prompt'],
                'n' => 1,
                'size' => '1024x1024',
                'response_format' => 'b64_json',
            ]);

        if ($response->failed()) {
            report(new \RuntimeException('OpenAI image error: ' . $response->body()));
            return $this->error('Image generation failed — check your OpenAI key/quota.', 502);
        }

        $b64 = $response->json('data.0.b64_json');
        if (!$b64) {
            return $this->error('Image generation returned no image.', 502);
        }

        $filename = 'social-posts/' . $user->id . '/' . Str::random(24) . '.png';
        Storage::disk('public')->put($filename, base64_decode($b64));

        return $this->success(['image_url' => Storage::disk('public')->url($filename)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'in:facebook,x,instagram'],
            'language' => ['required', 'string', 'max:10'],
            'prompt' => ['nullable', 'string', 'max:2000'],
            'content' => ['required', 'string', 'max:5000'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'scheduled_at' => ['required', 'date'],
        ]);
        $data['user_id'] = $request->user()->id;
        $data['status'] = 'queued';

        return $this->success(ScheduledPost::create($data), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $post = ScheduledPost::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $request->validate([
            'content' => ['sometimes', 'string', 'max:5000'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'scheduled_at' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:queued,filled,posted,cancelled'],
        ]);
        $post->update($data);
        return $this->success($post);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        ScheduledPost::where('user_id', $request->user()->id)->findOrFail($id)->delete();
        return $this->success(['deleted' => true]);
    }

    /** Polled by the extension's background alarm. */
    public function due(Request $request): JsonResponse
    {
        $items = ScheduledPost::where('user_id', $request->user()->id)
            ->where('status', 'queued')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(20)
            ->get();
        return $this->success($items);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:filled,posted,cancelled']]);
        $post = ScheduledPost::where('user_id', $request->user()->id)->findOrFail($id);
        $post->update(['status' => $data['status']]);
        return $this->success($post);
    }
}
