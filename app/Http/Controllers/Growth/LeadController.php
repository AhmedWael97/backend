<?php

namespace App\Http\Controllers\Growth;

use App\Http\Controllers\Controller;
use App\Models\CompanyEnrichment;
use App\Models\Domain;
use App\Models\EmailSuppression;
use App\Models\Lead;
use App\Models\OutreachEmail;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mini-CRM of prospects. The highest-value, fully-compliant source is "warm
 * leads": companies that already visited the user's sites (B2B enrichment).
 *
 *   GET    /api/v1/leads               — list (filter status/source/q)
 *   POST   /api/v1/leads               — add one
 *   POST   /api/v1/leads/import        — CSV import
 *   POST   /api/v1/leads/warm          — pull companies that visited my sites
 *   PUT    /api/v1/leads/{id}          — update status/notes/contact
 *   DELETE /api/v1/leads/{id}
 */
class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Lead::where('user_id', $request->user()->id);
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($src = $request->query('source')) {
            $q->where('source', $src);
        }
        if ($term = $request->query('q')) {
            $q->where(function ($w) use ($term) {
                $w->where('company', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")->orWhere('website', 'ilike', "%{$term}%");
            });
        }
        $perPage = max(5, min(100, (int) $request->query('per_page', 25)));
        $page = $q->orderByDesc('score')->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return $this->success([
            'data' => $page->items(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]);
    }

    /**
     * Counts for the header tiles. Deliberately unfiltered by the list's own
     * status filter — the point of the tiles is to show the whole pipeline
     * while you are looking at one slice of it.
     */
    public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $byStatus = Lead::where('user_id', $userId)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $total = (int) $byStatus->sum();

        // "Contacted" is the plain fact that a message went out, taken from
        // last_contacted_at rather than from status. Deriving it from status
        // undercounted: a lead that later bounced becomes `lost` and dropped
        // out of the tile entirely, so three sends reported as two.
        $contacted = Lead::where('user_id', $userId)->whereNotNull('last_contacted_at')->count();
        $replied = (int) ($byStatus['replied'] ?? 0) + (int) ($byStatus['won'] ?? 0);

        // `lost` covers both a dead address and a human opt-out, which are very
        // different signals — one is a data-quality problem, the other is
        // feedback on the message. The suppression reason separates them.
        $suppressed = EmailSuppression::where('user_id', $userId)
            ->selectRaw('reason, count(*) as c')
            ->groupBy('reason')
            ->pluck('c', 'reason');

        $bounced = (int) ($suppressed['bounce'] ?? 0);
        $delivered = max(0, $contacted - $bounced);

        return $this->success([
            'total' => $total,
            'new' => (int) ($byStatus['new'] ?? 0),
            'contacted' => $contacted,
            'delivered' => $delivered,
            'bounced' => $bounced,
            'unsubscribed' => (int) ($suppressed['unsubscribe'] ?? 0) + (int) ($suppressed['complaint'] ?? 0),
            'replied' => $replied,
            'won' => (int) ($byStatus['won'] ?? 0),
            'lost' => (int) ($byStatus['lost'] ?? 0),
            'with_email' => Lead::where('user_id', $userId)->whereNotNull('email')->count(),
            'drafts_pending' => OutreachEmail::where('user_id', $userId)->where('status', 'draft')->count(),
            // Measured against what actually landed. Counting bounces in the
            // denominator would make a list full of dead addresses look like a
            // failing offer, which is the wrong thing to go and fix.
            'reply_rate' => $delivered > 0 ? round($replied / $delivered * 100, 1) : 0.0,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'domain_id' => ['nullable', 'integer'],
        ]);
        $data['user_id'] = $request->user()->id;
        $data['source'] = 'manual';
        return $this->success(Lead::create($data), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $lead = Lead::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $request->validate([
            'status' => ['sometimes', 'in:new,contacted,replied,won,lost'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'score' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);
        $lead->update($data);
        return $this->success($lead);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Lead::where('user_id', $request->user()->id)->findOrFail($id)->delete();
        return $this->success(['deleted' => true]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['csv' => ['required', 'string']]);
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $request->input('csv')));
        if (count($lines) < 2) {
            return $this->error('CSV needs a header row and at least one data row.', 422);
        }
        $idx = array_flip(array_map(fn($h) => strtolower(trim($h)), str_getcsv(array_shift($lines))));
        $userId = $request->user()->id;
        $imported = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $get = fn(string $k) => isset($idx[$k], $cols[$idx[$k]]) ? trim((string) $cols[$idx[$k]]) : null;
            Lead::create([
                'user_id' => $userId,
                'company' => $get('company'),
                'website' => $get('website'),
                'contact_name' => $get('contact_name') ?? $get('name'),
                'email' => $get('email'),
                'source' => 'import',
                'status' => 'new',
            ]);
            $imported++;
        }
        return $this->success(['imported' => $imported]);
    }

    /**
     * Warm leads: companies that visited the user's domains (joining ClickHouse
     * visitor ip_hash → company_enrichments). Fully compliant — they came to us.
     */
    public function warm(Request $request, ClickHouseService $ch): JsonResponse
    {
        $user = $request->user();
        // Use centralised access (superadmin→all, owner→own, org member→granted)
        // so multi-tenant users get warm leads for every site they can see.
        $domainIds = Domain::accessibleBy($user)->where('is_demo', false)->pluck('id')->all();

        if (empty($domainIds)) {
            return $this->success(['created' => 0, 'leads' => []]);
        }

        $hashes = [];
        try {
            $inList = implode(',', array_map('intval', $domainIds));
            $rows = $ch->select("
                SELECT DISTINCT ip_hash FROM events
                WHERE domain_id IN ({$inList}) AND ip_hash != '' AND ts >= '" . now()->subDays(90)->format('Y-m-d H:i:s') . "'
                LIMIT 5000
            ");
            $hashes = array_values(array_filter(array_map(fn($r) => (string) ($r['ip_hash'] ?? ''), $rows)));
        } catch (\Throwable $e) {
            report($e);
        }
        if (empty($hashes)) {
            return $this->success(['created' => 0, 'leads' => []]);
        }

        $companies = CompanyEnrichment::whereIn('ip_hash', $hashes)
            ->whereNotNull('company_name')
            ->where('company_name', '!=', '')
            ->get()
            ->unique('company_domain');

        $created = 0;
        $out = [];
        foreach ($companies as $c) {
            // Skip if we already have a lead for this company/domain.
            $exists = Lead::where('user_id', $user->id)
                ->where(function ($w) use ($c) {
                    $w->where('website', $c->company_domain)->orWhere('company', $c->company_name);
                })->exists();
            if ($exists) {
                continue;
            }
            $lead = Lead::create([
                'user_id' => $user->id,
                'company' => $c->company_name,
                'website' => $c->company_domain,
                'source' => 'visitor',
                'status' => 'new',
                'score' => 70, // warm — they visited us
                'notes' => trim(($c->industry ? "Industry: {$c->industry}. " : '') . ($c->employee_range ? "Size: {$c->employee_range}. " : '') . ($c->country ? "Country: {$c->country}." : '')),
            ]);
            $created++;
            $out[] = $lead;
        }

        return $this->success(['created' => $created, 'leads' => $out]);
    }
}
