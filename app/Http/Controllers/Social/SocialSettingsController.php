<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Services\ComfyUiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user settings for the social-manager feature. The OpenAI key is
 * needed only for AI image generation (Claude/Anthropic already covers
 * text) — stored encrypted (User::$casts), never returned to the client.
 * If our own ComfyUI GPU box is configured, image (and video) generation
 * work without any per-user key.
 */
class SocialSettingsController extends Controller
{
    public function show(Request $request, ComfyUiService $comfy): JsonResponse
    {
        return $this->success([
            'has_openai_key' => (bool) $request->user()->openai_api_key,
            'has_comfyui' => $comfy->isConfigured(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['openai_api_key' => ['nullable', 'string', 'max:255']]);
        $request->user()->update(['openai_api_key' => $data['openai_api_key'] ?: null]);
        return $this->success(['has_openai_key' => (bool) $data['openai_api_key']]);
    }
}
