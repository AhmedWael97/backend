<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Finds prospect companies from open / licensed business directories.
 *
 * Deliberately NOT a scraper of Google results, LinkedIn, or a partner
 * directory — those all forbid it in their terms, and a blocked IP or a
 * cease-and-desist costs far more than the leads are worth. Both providers
 * here are meant to be queried programmatically:
 *
 *  - `osm`    OpenStreetMap via the Overpass API. Free, no key, ODbL-licensed
 *             open data. Coverage of agencies is patchy and skews to firms
 *             that bothered to map themselves, but it costs nothing.
 *  - `places` Google Places API (Text Search). Much better coverage and
 *             freshness, billed per request, needs GOOGLE_PLACES_API_KEY.
 *
 * Neither returns an email reliably — ContactFinderService fills that in from
 * the company's own published contact page.
 */
class LeadSourceService
{
    private const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';
    private const PLACES_URL = 'https://places.googleapis.com/v1/places:searchText';
    private const UA = 'EYE-Analytics-LeadResearch/1.0 (+https://eye-analysis.online)';

    /** Overpass `office=` values worth querying per shorthand category. */
    private const OSM_CATEGORIES = [
        'marketing' => ['advertising_agency', 'marketing'],
        'web' => ['it', 'web_design'],
        'agency' => ['advertising_agency', 'marketing', 'it', 'web_design'],
    ];

    /**
     * @return array<int, array{company: string, website: string, email: ?string, notes: string}>
     */
    public function search(string $provider, string $area, string $category, int $limit): array
    {
        return match ($provider) {
            'osm' => $this->fromOpenStreetMap($area, $category, $limit),
            'places' => $this->fromGooglePlaces($area, $category, $limit),
            default => throw new \InvalidArgumentException("Unknown lead provider [{$provider}]."),
        };
    }

    public function available(string $provider): bool
    {
        return $provider === 'osm' || (bool) config('services.google_places.key');
    }

    /**
     * @return array<int, array{company: string, website: string, email: ?string, notes: string}>
     */
    private function fromOpenStreetMap(string $area, string $category, int $limit): array
    {
        $offices = self::OSM_CATEGORIES[$category] ?? self::OSM_CATEGORIES['agency'];

        // Overpass has no parameter binding, so the area name is embedded in the
        // query. Strip everything but letters/spaces/hyphens so a crafted value
        // cannot close the string and append statements of its own.
        $safeArea = preg_replace('/[^\p{L}\p{N} \-\'\.]/u', '', $area);
        $clauses = '';
        foreach ($offices as $office) {
            $clauses .= "  nwr[\"office\"=\"{$office}\"][\"website\"](area.a);\n";
            $clauses .= "  nwr[\"office\"=\"{$office}\"][\"contact:website\"](area.a);\n";
        }

        $query = "[out:json][timeout:60];\n"
            . "area[\"name\"=\"{$safeArea}\"]->.a;\n"
            . "(\n{$clauses});\n"
            . 'out tags ' . ($limit * 3) . ";\n";

        try {
            $response = Http::withHeaders(['User-Agent' => self::UA])
                ->timeout(90)
                ->asForm()
                ->post(self::OVERPASS_URL, ['data' => $query]);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        $out = [];
        foreach ((array) ($response->json('elements') ?? []) as $element) {
            $tags = (array) ($element['tags'] ?? []);
            $website = (string) ($tags['website'] ?? $tags['contact:website'] ?? '');
            $name = (string) ($tags['name'] ?? '');
            if ($website === '' || $name === '') {
                continue;
            }

            $out[] = [
                'company' => mb_substr($name, 0, 255),
                'website' => mb_substr($website, 0, 255),
                // OSM occasionally carries a published contact address already.
                'email' => $this->cleanEmail($tags['contact:email'] ?? $tags['email'] ?? null),
                'notes' => trim("OpenStreetMap · {$area}"),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{company: string, website: string, email: ?string, notes: string}>
     */
    private function fromGooglePlaces(string $area, string $category, int $limit): array
    {
        $key = (string) config('services.google_places.key');
        if ($key === '') {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'places.displayName,places.websiteUri,places.formattedAddress',
                'User-Agent' => self::UA,
            ])->timeout(30)->post(self::PLACES_URL, [
                'textQuery' => trim("{$category} agency in {$area}"),
                // Places caps a single page at 20; the caller can re-run with a
                // narrower area rather than us paging through billed requests.
                'pageSize' => min(20, max(1, $limit)),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        $out = [];
        foreach ((array) ($response->json('places') ?? []) as $place) {
            $website = (string) ($place['websiteUri'] ?? '');
            $name = (string) ($place['displayName']['text'] ?? '');
            if ($website === '' || $name === '') {
                continue;
            }

            $out[] = [
                'company' => mb_substr($name, 0, 255),
                'website' => mb_substr($website, 0, 255),
                'email' => null,
                'notes' => trim('Google Places · ' . (string) ($place['formattedAddress'] ?? $area)),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function cleanEmail(mixed $value): ?string
    {
        $value = trim((string) (is_scalar($value) ? $value : ''));

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_substr($value, 0, 255) : null;
    }
}
