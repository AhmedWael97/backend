<?php

namespace App\Http\Controllers\Analytics;

use App\Models\Domain;
use App\Services\ClickHouseService;
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
        $summary = $this->clickhouse->select("
            SELECT
                JSONExtractString(props,'form') AS form,
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
            return [
                'form' => $f['form'],
                'starts' => $starts,
                'submits' => $submits,
                'abandons' => (int) $f['abandons'],
                'completion_rate' => $starts > 0 ? round($submits / $starts * 100, 1) : 0,
                'fields' => $fieldsBy[$f['form']] ?? [],
                'drop_points' => $dropsBy[$f['form']] ?? [],
            ];
        }, $summary);

        return $this->success(['forms' => $forms, 'days' => $days]);
    }
}
