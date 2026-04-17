<?php

namespace App\Http\Controllers;

use App\Models\ThemeSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ThemeController extends Controller
{
    public function show(): JsonResponse
    {
        $data = Cache::remember('theme_settings', 3600, function () {
            return ThemeSetting::pluck('value', 'key')->all();
        });

        return response()->json($data);
    }
}
