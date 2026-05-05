<?php

namespace App\Http\Controllers\Ux;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class UxHeatmapScreenshotController extends Controller
{
    private const CACHE_DIR = 'heatmap-screenshots';

    public function __invoke(Request $request, int $domainId): JsonResponse|Response
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $url = trim((string) $request->query('url', ''));
        if ($url === '') {
            return $this->error('url is required.', 422);
        }

        $token = $request->bearerToken();
        if (!$token) {
            return $this->error('Unauthorized.', 401);
        }

        try {
            $parsedUrl = parse_url($url);
            $host = strtolower((string) ($parsedUrl['host'] ?? ''));
            $domainHost = strtolower((string) $domain->domain);
            if ($host === '' || !($host === $domainHost || str_ends_with($host, '.' . $domainHost))) {
                return $this->error('This page does not belong to the selected domain.', 403);
            }
        } catch (\Throwable) {
            return $this->error('Invalid page URL.', 422);
        }

        $cacheTtl = (int) env('HEATMAP_SCREENSHOT_TTL_SECONDS', 60 * 60 * 24);
        $cacheKey = sha1($domain->id . '|' . $url);
        $cachePath = self::CACHE_DIR . '/' . $domain->id . '/' . $this->buildCacheFileName($url, $cacheKey);
        $disk = Storage::disk('local');

        if ($disk->exists($cachePath)) {
            $age = time() - (int) $disk->lastModified($cachePath);
            if ($age >= 0 && $age <= $cacheTtl) {
                return $this->screenshotResponse((string) $disk->get($cachePath), 'hit');
            }
        }

        $screenshotUrl = (string) env('HEATMAP_SCREENSHOT_UPSTREAM', 'http://node:3000/api/ux/screenshot');

        $response = Http::connectTimeout(10)
            ->timeout(45)
            ->withToken($token)
            ->withHeaders([
                'X-Internal-Request' => 'true',
                'X-Eye-Internal' => 'true',
            ])
            ->accept('image/png')
            ->get($screenshotUrl, ['url' => $url]);

        if (!$response->successful()) {
            if ($disk->exists($cachePath)) {
                return $this->screenshotResponse((string) $disk->get($cachePath), 'stale');
            }

            $payload = $response->json();
            $message = is_array($payload) && isset($payload['error'])
                ? (string) $payload['error']
                : 'Screenshot unavailable for this page.';
            return $this->error($message, $response->status() >= 400 ? $response->status() : 502);
        }

        $contentType = strtolower((string) ($response->header('Content-Type') ?: ''));
        if (!str_starts_with($contentType, 'image/')) {
            $payload = $response->json();
            $message = is_array($payload) && isset($payload['error'])
                ? (string) $payload['error']
                : 'Screenshot upstream returned a non-image payload.';

            return $this->error($message, 502);
        }

        $body = $response->body();

        try {
            $disk->put($cachePath, $body);
        } catch (\Throwable) {
            // Best effort cache write; do not fail the request.
        }

        return $this->screenshotResponse($body, 'miss');
    }

    private function buildCacheFileName(string $url, string $cacheKey): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($url)) ?? '';
        $name = trim($name, '-');
        $name = $name !== '' ? substr($name, 0, 120) : 'screenshot';

        return $name . '-' . substr($cacheKey, 0, 12) . '.png';
    }

    private function screenshotResponse(string $imageData, string $cacheStatus): Response
    {
        return response($imageData, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
            'X-Screenshot-Cache' => $cacheStatus,
        ]);
    }
}
