<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ToolUsageLog extends Model
{
    protected $fillable = ['tool', 'url', 'checked_host', 'score', 'user_id', 'ip'];

    /** Best-effort — logging failure must never break the tool for the user. */
    public static function log(Request $request, string $tool, string $url, ?int $score = null): void
    {
        try {
            self::create([
                'tool' => $tool,
                'url' => substr($url, 0, 2048),
                'checked_host' => parse_url($url, PHP_URL_HOST),
                'score' => $score,
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
