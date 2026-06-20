<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\SeoKeyword;
use App\Models\SeoRanking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Keyword rank tracking. Positions are imported manually / via CSV (works with
 * no paid SERP API); an automated provider can later POST into `import`.
 *
 *   GET    /api/v1/analytics/{domainId}/seo-rank             — keywords + trend
 *   POST   /api/v1/analytics/{domainId}/seo-rank/keywords    — track a keyword
 *   POST   /api/v1/analytics/{domainId}/seo-rank/import      — CSV: date,keyword,position[,url]
 *   DELETE /api/v1/analytics/{domainId}/seo-rank/keywords/{id} — untrack
 */
class SeoRankController extends Controller
{
    public function index(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);

        $keywords = SeoKeyword::where('domain_id', $domain->id)->orderBy('keyword')->get();

        // All rankings for this domain in the last 90 days, grouped by keyword.
        $since = now()->subDays(90)->toDateString();
        $rankings = SeoRanking::where('domain_id', $domain->id)
            ->where('date', '>=', $since)
            ->orderBy('keyword')->orderBy('date')
            ->get(['keyword', 'date', 'position', 'url']);

        $byKeyword = [];
        foreach ($rankings as $r) {
            $byKeyword[$r->keyword][] = [
                'date' => $r->date instanceof \Carbon\Carbon ? $r->date->toDateString() : (string) $r->date,
                'position' => $r->position,
                'url' => $r->url,
            ];
        }

        $out = $keywords->map(function ($kw) use ($byKeyword) {
            $hist = $byKeyword[$kw->keyword] ?? [];
            $positions = array_values(array_filter(array_map(fn($h) => $h['position'], $hist), fn($p) => $p !== null));
            $latest = !empty($hist) ? end($hist) : null;
            $prev = count($hist) >= 2 ? $hist[count($hist) - 2] : null;
            return [
                'id' => $kw->id,
                'keyword' => $kw->keyword,
                'latest_position' => $latest['position'] ?? null,
                'latest_date' => $latest['date'] ?? null,
                'latest_url' => $latest['url'] ?? null,
                // Lower position number = better rank; positive delta = improved.
                'change' => ($latest && $prev && $latest['position'] !== null && $prev['position'] !== null)
                    ? ($prev['position'] - $latest['position']) : null,
                'best_position' => !empty($positions) ? min($positions) : null,
                'history' => $hist,
            ];
        })->values();

        return $this->success(['keywords' => $out]);
    }

    public function storeKeyword(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        $data = $request->validate(['keyword' => ['required', 'string', 'max:200']]);
        $kw = SeoKeyword::firstOrCreate(['domain_id' => $domain->id, 'keyword' => trim($data['keyword'])]);
        return $this->success($kw, 201);
    }

    public function import(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        $request->validate(['csv' => ['required', 'string']]);

        $lines = preg_split('/\r\n|\r|\n/', trim((string) $request->input('csv')));
        if (count($lines) < 2) {
            return $this->error('CSV needs a header row and at least one data row.', 422);
        }
        $header = array_map(fn($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $idx = array_flip($header);
        foreach (['date', 'keyword', 'position'] as $req) {
            if (!isset($idx[$req])) {
                return $this->error("CSV is missing the required '{$req}' column.", 422);
            }
        }

        $imported = 0;
        $skipped = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $get = fn(string $k) => isset($idx[$k], $cols[$idx[$k]]) ? trim((string) $cols[$idx[$k]]) : null;
            $date = $get('date');
            $keyword = $get('keyword');
            $pos = $get('position');
            if (!$date || !$keyword || strtotime($date) === false) {
                $skipped++;
                continue;
            }
            SeoKeyword::firstOrCreate(['domain_id' => $domain->id, 'keyword' => $keyword]);
            SeoRanking::updateOrCreate(
                ['domain_id' => $domain->id, 'keyword' => $keyword, 'date' => date('Y-m-d', strtotime($date))],
                ['position' => is_numeric($pos) ? (int) $pos : null, 'url' => $get('url')]
            );
            $imported++;
        }

        return $this->success(['imported' => $imported, 'skipped' => $skipped]);
    }

    public function destroyKeyword(Request $request, int $domainId, int $id): JsonResponse
    {
        $domain = $this->authorizeDomain($request, $domainId);
        $kw = SeoKeyword::where('domain_id', $domain->id)->where('id', $id)->firstOrFail();
        SeoRanking::where('domain_id', $domain->id)->where('keyword', $kw->keyword)->delete();
        $kw->delete();
        return $this->success(['deleted' => true]);
    }

    private function authorizeDomain(Request $request, int $domainId): Domain
    {
        $user = $request->user();
        return Domain::where('id', $domainId)
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();
    }
}
