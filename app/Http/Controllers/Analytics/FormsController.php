<?php

namespace App\Http\Controllers\Analytics;

use App\Models\Domain;
use App\Services\ClickHouseService;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auto form-field analytics. The tracker emits form_start / form_field /
 * form_submit / form_abandon(last_field) with no client code. This surfaces, per
 * form: how many engaged, submitted, abandoned, the field-by-field reach funnel,
 * and the field where people quit — so clients fix form abandonment directly.
 *
 * GET /api/analytics/{domainId}/forms?days=30
 */
class FormsController extends BaseAnalyticsController
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)->accessibleBy($user)->firstOrFail();

        $days = max(1, min(365, (int) $request->query('days', 30)));
        $from = now()->subDays($days)->format('Y-m-d H:i:s');
        $to = now()->format('Y-m-d H:i:s');
        $did = $domain->id;

        // Per-form summary. starts = distinct sessions that touched any field.
        // page_title / page_url = a page where this form appears (for a readable name).
        $summary = $this->clickhouse->select("
            SELECT
                JSONExtractString(props,'form') AS form,
                anyLastIf(title, title != '') AS page_title,
                anyLast(url) AS page_url,
                uniqExactIf(session_id, type='form_field') AS starts,
                countIf(type='form_submit')  AS submits,
                countIf(type='form_abandon') AS abandons
            FROM events
            WHERE domain_id = {$did}
              AND type IN ('form_field','form_submit','form_abandon')
              AND ts >= '{$from}' AND ts < '{$to}'
              AND JSONExtractString(props,'form') != ''
            GROUP BY form ORDER BY starts DESC LIMIT 50
        ");

        // Field reach funnel (how many focused each field).
        $fieldRows = $this->clickhouse->select("
            SELECT
                JSONExtractString(props,'form')  AS form,
                JSONExtractString(props,'field') AS field,
                count() AS reached
            FROM events
            WHERE domain_id = {$did} AND type = 'form_field'
              AND ts >= '{$from}' AND ts < '{$to}'
              AND JSONExtractString(props,'field') != ''
            GROUP BY form, field ORDER BY reached DESC
        ");

        // Drop points — last field touched before abandoning.
        $dropRows = $this->clickhouse->select("
            SELECT
                JSONExtractString(props,'form')       AS form,
                JSONExtractString(props,'last_field') AS field,
                count() AS drops
            FROM events
            WHERE domain_id = {$did} AND type = 'form_abandon'
              AND ts >= '{$from}' AND ts < '{$to}'
              AND JSONExtractString(props,'last_field') != ''
            GROUP BY form, field ORDER BY drops DESC
        ");

        $fieldsBy = [];
        foreach ($fieldRows as $r) {
            $fieldsBy[$r['form']][] = ['field' => $r['field'], 'reached' => (int) $r['reached']];
        }
        $dropsBy = [];
        foreach ($dropRows as $r) {
            $dropsBy[$r['form']][] = ['field' => $r['field'], 'drops' => (int) $r['drops']];
        }

        $forms = array_map(function ($f) use ($fieldsBy, $dropsBy) {
            $starts = (int) $f['starts'];
            $submits = (int) $f['submits'];
            $rate = $starts > 0 ? round($submits / $starts * 100, 1) : 0;
            $drops = $dropsBy[$f['form']] ?? [];

            return [
                'form' => $f['form'],
                'page_title' => $f['page_title'] ?? '',
                'page_url' => $f['page_url'] ?? '',
                'starts' => $starts,
                'submits' => $submits,
                'abandons' => (int) $f['abandons'],
                'completion_rate' => $rate,
                'fields' => $fieldsBy[$f['form']] ?? [],
                'drop_points' => $drops,
                'diagnosis' => $this->diagnose($starts, $rate, $drops),
            ];
        }, $summary);

        return $this->success(['forms' => $forms, 'days' => $days]);
    }

    /**
     * POST /api/analytics/{domainId}/forms/analyze
     * AI (Gemini) recommendation from one form's stats; falls back to rule-based.
     */
    public function analyze(Request $request, int $domainId, GeminiService $gemini): JsonResponse
    {
        $user = $request->user();
        Domain::where('id', $domainId)->accessibleBy($user)->firstOrFail();

        $d = $request->validate([
            'page_title' => ['nullable', 'string', 'max:255'],
            'starts' => ['required', 'integer'],
            'submits' => ['required', 'integer'],
            'completion_rate' => ['required', 'numeric'],
            'fields' => ['array'],
            'drop_points' => ['array'],
        ]);

        $fields = collect($d['fields'] ?? [])->map(fn ($x) => ($x['field'] ?? '?') . '=' . ($x['reached'] ?? 0))->implode(', ');
        $drops = collect($d['drop_points'] ?? [])->map(fn ($x) => ($x['field'] ?? '?') . '=' . ($x['drops'] ?? 0))->implode(', ');

        $prompt = "You are a conversion-rate-optimization expert. Analyze this website form and advise the site owner.\n\n"
            . 'Form on page: ' . ($d['page_title'] ?: 'unknown') . "\n"
            . "Started (touched a field): {$d['starts']}\nSubmitted: {$d['submits']}\nCompletion rate: {$d['completion_rate']}%\n"
            . "Field reach (field=people who focused it): {$fields}\n"
            . "Abandon points (last field before leaving = field=count): {$drops}\n\n"
            . "In 3–5 short bullet points, plain language: what is most likely going wrong, and the exact changes to fix it. "
            . 'Be specific to the field where people quit. No preamble.';

        $ai = $gemini->generate($prompt);

        return $this->success([
            'advice' => $ai,
            'source' => $ai ? 'gemini' : 'fallback',
            'fallback' => $this->diagnose((int) $d['starts'], (float) $d['completion_rate'], $d['drop_points'] ?? [])['text'],
        ]);
    }

    /** Rule-based, data-only advice: what's wrong + how to fix. */
    private function diagnose(int $starts, float $rate, array $drops): array
    {
        if ($starts < 10) {
            return ['level' => 'info', 'text' => 'Not enough data yet — needs ~10+ form starts for a reliable read.'];
        }
        $worst = $drops[0]['field'] ?? null;
        if ($rate >= 60) {
            return ['level' => 'good', 'text' => "Healthy completion ({$rate}%). Keep it simple."];
        }
        $where = $worst ? " Most quit at the \"{$worst}\" field." : '';
        $fix = $worst
            ? "Fix that field first: make it optional, autofocus it, add a helper/why-we-ask note, or reduce typing (e.g. Google/social sign-in)."
            : 'Cut fields to the minimum, autofocus the first field, and remove any step that isn\'t essential.';
        $level = $rate < 25 ? 'bad' : 'warn';
        return ['level' => $level, 'text' => "Only {$rate}% who start finish this form.{$where} {$fix}"];
    }
}
