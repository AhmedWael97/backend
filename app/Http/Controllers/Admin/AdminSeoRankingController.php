<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Superadmin, cross-domain view of every tracked SEO keyword + its latest real Google position. */
class AdminSeoRankingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $domainId = $request->query('domain_id');

        $keywords = DB::table('seo_keywords as k')
            ->join('domains as d', 'd.id', '=', 'k.domain_id')
            ->when($domainId, fn ($q) => $q->where('k.domain_id', $domainId))
            ->select('k.id', 'k.domain_id', 'd.domain as domain_name', 'k.keyword', 'k.created_at')
            ->orderBy('d.domain')->orderBy('k.keyword')
            ->get();

        // Latest ranking per (domain_id, keyword) — Postgres DISTINCT ON, one query for all rows.
        $latestRows = collect(DB::select('
            SELECT DISTINCT ON (domain_id, keyword) domain_id, keyword, position, date, url
            FROM seo_rankings
            ORDER BY domain_id, keyword, date DESC
        '))->keyBy(fn ($r) => "{$r->domain_id}|{$r->keyword}");

        $bestRows = collect(DB::table('seo_rankings')
            ->select('domain_id', 'keyword', DB::raw('MIN(position) as best_position'), DB::raw('COUNT(*) as checks'))
            ->whereNotNull('position')
            ->groupBy('domain_id', 'keyword')
            ->get())
            ->keyBy(fn ($r) => "{$r->domain_id}|{$r->keyword}");

        $out = $keywords->map(function ($kw) use ($latestRows, $bestRows) {
            $key = "{$kw->domain_id}|{$kw->keyword}";
            $latest = $latestRows->get($key);
            $best = $bestRows->get($key);
            return [
                'id' => $kw->id,
                'domain_id' => $kw->domain_id,
                'domain' => $kw->domain_name,
                'keyword' => $kw->keyword,
                'latest_position' => $latest->position ?? null,
                'latest_date' => $latest->date ?? null,
                'latest_url' => $latest->url ?? null,
                'best_position' => $best->best_position ?? null,
                'checks' => $best->checks ?? 0,
                'tracked_since' => $kw->created_at,
            ];
        })->values();

        return $this->success(['keywords' => $out]);
    }
}
