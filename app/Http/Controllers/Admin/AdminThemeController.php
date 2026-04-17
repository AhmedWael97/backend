<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ThemeSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class AdminThemeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ThemeSetting::all()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['required'],
        ]);

        foreach ($data['settings'] as $item) {
            ThemeSetting::where('key', $item['key'])->update([
                'value' => $item['value'],
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
        }

        Cache::forget('theme_settings');

        return response()->json(['message' => 'Theme settings updated.']);
    }

    /**
     * POST /api/admin/theme/logo — upload light or dark logo.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
            'type' => ['required', 'in:light,dark'],
        ]);

        $file = $request->file('logo');
        $variant = $request->input('type');
        $path = $file->storeAs(
            'public/logos',
            "logo_{$variant}." . $file->extension()
        );

        $url = Storage::url($path);
        $key = "logo_{$variant}_url";

        ThemeSetting::where('key', $key)->update([
            'value' => $url,
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ]);

        Cache::forget('theme_settings');

        return response()->json(['url' => $url]);
    }
}
