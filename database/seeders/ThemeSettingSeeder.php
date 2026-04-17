<?php

namespace Database\Seeders;

use App\Models\ThemeSetting;
use Illuminate\Database\Seeder;

class ThemeSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'brand_primary' => '#6366f1',
            'brand_secondary' => '#8b5cf6',
            'brand_accent' => '#a78bfa',
            'platform_name' => 'EYE',
            'default_locale' => 'en',
            'default_appearance' => 'system',
            'font_latin' => 'Inter',
            'font_arabic' => 'Tajawal',
            'border_radius' => 'rounded',
            'sidebar_style' => 'expanded',
            'logo_light_url' => null,
            'logo_dark_url' => null,
        ];

        foreach ($settings as $key => $rawValue) {
            ThemeSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $rawValue, 'updated_by' => null]
            );
        }

        ThemeSetting::flush();
    }
}
