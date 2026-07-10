<?php

namespace App\Services;

use App\Models\Pipeline;

/**
 * Deterministic "what should I do" engine — analyst-grade findings without an LLM.
 *
 * Runs statistical detectors over ClickHouse and returns ranked, plain-language
 * findings: anomalies vs the site's own baseline, trend slopes, root-cause
 * decomposition (which slice of traffic explains a problem), and traffic
 * concentration risk. Every finding carries an impact score so the worst-first
 * ordering is money/visitors at stake, not rule order.
 *
 * No thresholds pulled from thin air where a baseline exists — "3σ below normal
 * for this weekday" beats "< 10".
 */
class InsightEngine
{
    public function __construct(private readonly ClickHouseService $ch)
    {
    }

    /** @return array<int, array<string, mixed>> ranked findings for the overview page */
    public function overview(int $domainId): array
    {
        $daily = $this->dailyTraffic($domainId, 28);

        $findings = array_filter([
            $this->trafficAnomaly($daily),
            $this->trafficTrend($daily),
            $this->bounceRootCause($domainId),
            $this->concentrationRisk($domainId),
            $this->engagementDrop($domainId),
        ]);

        usort($findings, fn ($a, $b) => ($b['impact'] <=> $a['impact']));

        return array_values($findings);
    }

    /**
     * Marketing pages (campaigns / channels / ltv): join tracked revenue
     * (conversions) to ad spend (ad_spend) per channel and surface money moves —
     * wasted spend, winners to scale, untracked spend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function marketing(int $domainId): array
    {
        $channels = $this->revenueVsSpend($domainId, 30);

        $findings = [];
        foreach ($channels as $c) {
            $medium = $c['medium'];
            $spend = $c['spend'];
            $revenue = $c['revenue'];
            $roas = $spend > 0 ? $revenue / $spend : null;

            if ($spend >= 10 && $roas !== null && $roas < 1) {
                $findings[] = [
                    'kind' => 'wasted_spend',
                    'severity' => $roas < 0.5 ? 'critical' : 'warning',
                    'title' => sprintf('"%s" is losing money — %.2f× ROAS', $medium, $roas),
                    'detail' => sprintf('Spent %s, made back only %s in the last 30 days.', $this->money($spend), $this->money($revenue)),
                    'action' => "Pause or cut the budget on \"{$medium}\" until the ad or landing page is fixed.",
                    'impact' => ($spend - $revenue) * 3,
                ];
            } elseif ($spend >= 5 && $roas !== null && $roas >= 3) {
                $findings[] = [
                    'kind' => 'scale_winner',
                    'severity' => 'good',
                    'title' => sprintf('"%s" is a winner — %.2f× ROAS', $medium, $roas),
                    'detail' => sprintf('Every %s spent returned %s. It has room to scale.', $this->money(1), $this->money($roas)),
                    'action' => "Increase budget on \"{$medium}\" gradually while ROAS holds.",
                    'impact' => $revenue * 1.5,
                ];
            } elseif ($revenue > 0 && $spend == 0 && !in_array($medium, ['(direct)', 'organic', '(none)', ''], true)) {
                $findings[] = [
                    'kind' => 'untracked_spend',
                    'severity' => 'info',
                    'title' => sprintf('"%s" drove %s with no recorded spend', $medium, $this->money($revenue)),
                    'detail' => 'Without its cost, you cannot tell if this channel is actually profitable.',
                    'action' => "Add \"{$medium}\" spend (manually or CSV) so its true ROAS shows.",
                    'impact' => $revenue * 0.2,
                ];
            }
        }

        usort($findings, fn ($a, $b) => $b['impact'] <=> $a['impact']);

        // If there's no revenue/spend signal yet, marketing pages still benefit
        // from the traffic-level findings.
        return $findings ?: $this->overview($domainId);
    }

    /**
     * Funnels page: for every pipeline, find the single step with the biggest
     * session loss, then decompose the drop-off sessions by device/source to
     * name which segment is actually quitting there.
     *
     * @return array<int, array<string, mixed>>
     */
    public function funnels(int $domainId): array
    {
        $pipelines = Pipeline::where('domain_id', $domainId)->with('steps')->get();

        $findings = [];
        foreach ($pipelines as $pipeline) {
            $finding = $this->funnelDropFinding($domainId, $pipeline);
            if ($finding) {
                $findings[] = $finding;
            }
        }

        usort($findings, fn ($a, $b) => $b['impact'] <=> $a['impact']);

        return $findings ?: $this->overview($domainId);
    }

    /**
     * Retention page: is week-1 retention improving or decaying, comparing the
     * most recent cohorts to the ones before them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retention(int $domainId): array
    {
        $finding = $this->retentionTrend($domainId);

        return $finding ? [$finding] : $this->overview($domainId);
    }

    // ── Detectors ─────────────────────────────────────────────────────────────

    /** Today's visitors vs the mean/σ of the same weekday over the prior 4 weeks. */
    private function trafficAnomaly(array $daily): ?array
    {
        if (count($daily) < 15) {
            return null;
        }

        $today = end($daily);
        $weekday = (int) date('w', strtotime($today['d']));

        $sameWeekday = [];
        foreach (array_slice($daily, 0, -1) as $row) {
            if ((int) date('w', strtotime($row['d'])) === $weekday) {
                $sameWeekday[] = (float) $row['visitors'];
            }
        }
        if (count($sameWeekday) < 3) {
            return null;
        }

        [$mean, $std] = $this->meanStd($sameWeekday);
        if ($std < 1e-6) {
            return null;
        }

        $z = ((float) $today['visitors'] - $mean) / $std;
        if (abs($z) < 2.0) {
            return null;
        }

        $down = $z < 0;
        $pct = $mean > 0 ? round((($today['visitors'] - $mean) / $mean) * 100) : 0;

        return [
            'kind' => 'traffic_anomaly',
            'severity' => $down ? ($z < -3 ? 'critical' : 'warning') : 'good',
            'title' => $down
                ? "Traffic is unusually low today ({$today['visitors']} visitors)"
                : "Traffic is unusually high today ({$today['visitors']} visitors)",
            'detail' => sprintf(
                'A typical %s sees about %d visitors; today is %s%d%% (%.1fσ from normal).',
                date('l', strtotime($today['d'])),
                round($mean),
                $pct >= 0 ? '+' : '',
                $pct,
                $z
            ),
            'action' => $down
                ? 'Check if a campaign paused, a page broke, or tracking stopped on a key page.'
                : 'Find which source spiked and double down while it lasts.',
            'impact' => abs($z) * max(1, $mean),
        ];
    }

    /** Slope of daily visitors over the last 14 days (least-squares). */
    private function trafficTrend(array $daily): ?array
    {
        $recent = array_slice($daily, -14);
        if (count($recent) < 10) {
            return null;
        }

        $y = array_map(fn ($r) => (float) $r['visitors'], $recent);
        $slope = $this->slope($y);
        [$mean] = $this->meanStd($y);
        if ($mean < 1e-6) {
            return null;
        }

        // Slope as % of the average day; ignore flat noise.
        $pctPerDay = ($slope / $mean) * 100;
        if (abs($pctPerDay) < 1.5) {
            return null;
        }

        $down = $slope < 0;
        // Clamp: a low-traffic mean can inflate the percentage past anything real.
        $perWeek = (int) max(-95, min(200, round($pctPerDay * 7)));

        return [
            'kind' => 'traffic_trend',
            'severity' => $down ? 'warning' : 'good',
            'title' => $down
                ? "Visitors trending down ~{$perWeek}%/week"
                : "Visitors trending up +{$perWeek}%/week",
            'detail' => $down
                ? 'The number itself may still look fine, but the direction is falling two weeks running.'
                : 'Momentum is building — sustained growth over the last two weeks.',
            'action' => $down
                ? 'Act before it bottoms out: revisit your top acquisition source and recent content.'
                : 'Reinforce whatever changed two weeks ago — it is working.',
            'impact' => abs($pctPerDay) * max(1, $mean) * 0.8,
        ];
    }

    /**
     * Root-cause: overall bounce is only useful decomposed. Slice sessions by
     * device / country / source, find the slice that contributes the most
     * *excess* bounces above the site average — that's where to look.
     */
    private function bounceRootCause(int $domainId): ?array
    {
        $overall = $this->ch->select("
            SELECT countIf(pv = 1) AS bounced, count() AS sessions
            FROM (
                SELECT session_id, countIf(type = 'pageview') AS pv
                FROM events
                WHERE domain_id = {$domainId} AND ts >= now() - INTERVAL 7 DAY
                GROUP BY session_id
            )
        ");
        $sessions = (int) ($overall[0]['sessions'] ?? 0);
        $bounced = (int) ($overall[0]['bounced'] ?? 0);
        if ($sessions < 100) {
            return null;
        }
        $baseRate = $bounced / $sessions;
        if ($baseRate < 0.4) {
            return null; // healthy overall; nothing to root-cause
        }

        $best = null;
        foreach (['device_type', 'country', 'source'] as $dim) {
            $slice = $this->bounceByDimension($domainId, $dim, $baseRate);
            if ($slice && (!$best || $slice['excess'] > $best['excess'])) {
                $best = $slice + ['dim' => $dim];
            }
        }
        if (!$best || $best['excess'] < max(20, $sessions * 0.03)) {
            return null;
        }

        $label = ['device_type' => 'device', 'country' => 'country', 'source' => 'traffic source'][$best['dim']];

        return [
            'kind' => 'bounce_rootcause',
            'severity' => 'warning',
            'title' => sprintf('%d%% bounce — driven by %s "%s"', round($baseRate * 100), $label, $best['value']),
            'detail' => sprintf(
                '%s "%s" bounces at %d%% vs %d%% site-wide, and it is a big share of traffic — it accounts for most of your wasted visits.',
                ucfirst($label),
                $best['value'],
                round($best['rate'] * 100),
                round($baseRate * 100)
            ),
            'action' => $best['dim'] === 'device_type'
                ? "Open the {$best['value']} experience of your top landing page — it is likely slow or hard to use."
                : ($best['dim'] === 'source'
                    ? "The message on \"{$best['value']}\" doesn't match your landing page. Align the ad and the page."
                    : "Localise or speed up the experience for visitors from {$best['value']}."),
            'impact' => $best['excess'] * 2.0,
        ];
    }

    private function bounceByDimension(int $domainId, string $dim, float $baseRate): ?array
    {
        // "source" is derived: utm_source, else classified referrer host, else (direct).
        $expr = $dim === 'source'
            ? "if(any(utm_source) != '', any(utm_source), if(any(referrer) != '', domain(any(referrer)), '(direct)'))"
            : "any({$dim})";

        $rows = $this->ch->select("
            SELECT val, countIf(pv = 1) AS bounced, count() AS sessions
            FROM (
                SELECT session_id, {$expr} AS val, countIf(type = 'pageview') AS pv
                FROM events
                WHERE domain_id = {$domainId} AND ts >= now() - INTERVAL 7 DAY
                GROUP BY session_id
            )
            WHERE val != ''
            GROUP BY val
            HAVING sessions >= 30
            ORDER BY bounced DESC
            LIMIT 20
        ");

        $best = null;
        foreach ($rows as $r) {
            $s = (int) $r['sessions'];
            $b = (int) $r['bounced'];
            $rate = $s > 0 ? $b / $s : 0;
            if ($rate <= $baseRate) {
                continue;
            }
            // Excess bounces = how many more than the site average would predict.
            $excess = $b - ($baseRate * $s);
            if (!$best || $excess > $best['excess']) {
                $best = ['value' => (string) $r['val'], 'rate' => $rate, 'excess' => $excess];
            }
        }

        return $best;
    }

    /** Over-reliance on one traffic source is a risk even when numbers are good. */
    private function concentrationRisk(int $domainId): ?array
    {
        $rows = $this->ch->select("
            SELECT src, uniq(session_id) AS s
            FROM (
                SELECT session_id,
                    if(utm_source != '', utm_source, if(referrer != '', domain(referrer), '(direct)')) AS src
                FROM events
                WHERE domain_id = {$domainId} AND type = 'pageview' AND ts >= now() - INTERVAL 14 DAY
            )
            GROUP BY src ORDER BY s DESC LIMIT 20
        ");
        $total = array_sum(array_map(fn ($r) => (int) $r['s'], $rows));
        if ($total < 200 || empty($rows)) {
            return null;
        }

        $top = $rows[0];
        $share = (int) $top['s'] / $total;
        if ($share < 0.6 || $top['src'] === '(direct)') {
            return null;
        }

        return [
            'kind' => 'concentration',
            'severity' => 'warning',
            'title' => sprintf('%d%% of traffic comes from "%s"', round($share * 100), $top['src']),
            'detail' => 'One source dominating means one algorithm change or ban can wipe out most of your visitors overnight.',
            'action' => 'Start a second channel now while the first is strong — email list, SEO, or a different ad platform.',
            'impact' => $share * $total,
        ];
    }

    /** Engagement (time on page) falling vs the prior week. */
    private function engagementDrop(int $domainId): ?array
    {
        $rows = $this->ch->select("
            SELECT
                avgIf(duration, ts >= now() - INTERVAL 7 DAY) AS cur,
                avgIf(duration, ts >= now() - INTERVAL 14 DAY AND ts < now() - INTERVAL 7 DAY) AS prev
            FROM events
            WHERE domain_id = {$domainId} AND type = 'pageview' AND duration > 0 AND duration < 1800
        ");
        $cur = (float) ($rows[0]['cur'] ?? 0);
        $prev = (float) ($rows[0]['prev'] ?? 0);
        if ($cur < 1 || $prev < 1) {
            return null;
        }

        $pct = (($cur - $prev) / $prev) * 100;
        if ($pct > -15) {
            return null;
        }

        return [
            'kind' => 'engagement_drop',
            'severity' => 'warning',
            'title' => sprintf('Time on page dropped %d%% this week', round(abs($pct))),
            'detail' => sprintf('Average went from %ds to %ds — visitors are leaving faster than last week.', round($prev), round($cur)),
            'action' => 'Check what changed 7 days ago: a new page, a slower load, or a traffic source sending less-interested visitors.',
            'impact' => abs($pct) * 30,
        ];
    }

    /** Biggest single-step session loss in a pipeline, with root-cause decomposition. */
    private function funnelDropFinding(int $domainId, Pipeline $pipeline): ?array
    {
        $steps = $pipeline->steps->values();
        if ($steps->count() < 2) {
            return null;
        }

        $stepIds = $steps->pluck('id')->map(fn ($id) => (int) $id)->all();
        $inList = implode(',', $stepIds);
        $rows = $this->ch->select("
            SELECT step_id, uniq(session_id) AS sessions
            FROM pipeline_events
            WHERE domain_id = {$domainId} AND pipeline_id = {$pipeline->id}
              AND step_id IN ({$inList}) AND event_time >= now() - INTERVAL 30 DAY
            GROUP BY step_id
        ");
        $sessionsByStep = [];
        foreach ($rows as $r) {
            $sessionsByStep[(int) $r['step_id']] = (int) $r['sessions'];
        }

        $worst = null;
        foreach ($steps as $i => $step) {
            if ($i === 0) {
                continue;
            }
            $prev = $steps[$i - 1];
            $prevCount = $sessionsByStep[$prev->id] ?? 0;
            $curCount = $sessionsByStep[$step->id] ?? 0;
            if ($prevCount < 20) {
                continue; // too little volume to trust a percentage
            }
            $lost = $prevCount - $curCount;
            $dropPct = ($lost / $prevCount) * 100;
            if ($dropPct < 30) {
                continue; // not a notable drop
            }
            if (!$worst || $lost > $worst['lost']) {
                $worst = compact('prev', 'step', 'prevCount', 'curCount', 'dropPct', 'lost');
            }
        }
        if (!$worst) {
            return null;
        }

        $segment = $this->funnelDropSegment($domainId, $pipeline->id, (int) $worst['prev']->id, (int) $worst['step']->id);

        $title = sprintf(
            '%s: %d%% drop off before "%s"',
            $pipeline->name,
            round($worst['dropPct']),
            $worst['step']->name
        );

        if ($segment) {
            $detail = sprintf(
                'Of %d sessions that reached "%s", %d never continued to "%s" — and %d%% of those who left were %s "%s".',
                $worst['prevCount'],
                $worst['prev']->name,
                $worst['lost'],
                $worst['step']->name,
                round($segment['share'] * 100),
                $segment['label'],
                $segment['value']
            );
            $action = $segment['dim'] === 'device_type'
                ? "Test the \"{$worst['step']->name}\" step yourself on {$segment['value']} — it is likely broken or confusing there."
                : "Check what \"{$segment['value']}\" traffic expects vs what \"{$worst['step']->name}\" actually shows — likely a mismatch.";
        } else {
            $detail = sprintf(
                '%d sessions reached "%s"; only %d continued to "%s".',
                $worst['prevCount'],
                $worst['prev']->name,
                $worst['curCount'],
                $worst['step']->name
            );
            $action = "Watch a session replay of someone who dropped at \"{$worst['step']->name}\" to see exactly where they get stuck.";
        }

        return [
            'kind' => 'funnel_drop',
            'severity' => $worst['dropPct'] > 60 ? 'critical' : 'warning',
            'title' => $title,
            'detail' => $detail,
            'action' => $action,
            'impact' => $worst['lost'] * 3,
        ];
    }

    /** Which device/source dominates the sessions that reached $fromStep but not $toStep. */
    private function funnelDropSegment(int $domainId, int $pipelineId, int $fromStep, int $toStep): ?array
    {
        $dropoff = $this->ch->select("
            SELECT session_id
            FROM pipeline_events
            WHERE domain_id = {$domainId} AND pipeline_id = {$pipelineId}
              AND step_id IN ({$fromStep}, {$toStep}) AND event_time >= now() - INTERVAL 30 DAY
            GROUP BY session_id
            HAVING maxIf(1, step_id = {$fromStep}) = 1 AND maxIf(1, step_id = {$toStep}) = 0
            LIMIT 5000
        ");
        $ids = array_map(fn ($r) => "'" . str_replace("'", '', (string) $r['session_id']) . "'", $dropoff);
        if (count($ids) < 20) {
            return null;
        }
        $inList = implode(',', $ids);
        $total = count($ids);

        $best = null;
        foreach (['device_type' => 'device', 'source_medium' => 'source'] as $dim => $label) {
            $expr = $dim === 'source_medium'
                ? "if(any(utm_medium) != '', any(utm_medium), if(any(referrer) != '', domain(any(referrer)), '(direct)'))"
                : "any(device_type)";

            $rows = $this->ch->select("
                SELECT val, count() AS c FROM (
                    SELECT session_id, {$expr} AS val
                    FROM events WHERE domain_id = {$domainId} AND session_id IN ({$inList})
                    GROUP BY session_id
                )
                WHERE val != '' GROUP BY val ORDER BY c DESC LIMIT 1
            ");
            if (empty($rows)) {
                continue;
            }
            $share = (int) $rows[0]['c'] / $total;
            if ($share >= 0.5 && (!$best || $share > $best['share'])) {
                $best = ['dim' => $dim, 'label' => $label, 'value' => (string) $rows[0]['val'], 'share' => $share];
            }
        }

        return $best;
    }

    /** Week-1 retention: recent cohorts vs the ones just before them. */
    private function retentionTrend(int $domainId): ?array
    {
        $start = now()->subWeeks(9)->startOfWeek()->format('Y-m-d H:i:s');

        $firstSeen = "
            SELECT visitor_id, toStartOfWeek(min(ts)) AS cohort
            FROM events WHERE domain_id = {$domainId} AND ts >= '{$start}'
            GROUP BY visitor_id
        ";
        $rows = $this->ch->select("
            SELECT toString(fs.cohort) AS cohort,
                uniqIf(fs.visitor_id, dateDiff('week', fs.cohort, toStartOfWeek(e.ts)) = 1) AS returned,
                uniqExact(fs.visitor_id) AS size
            FROM ({$firstSeen}) fs
            LEFT JOIN (
                SELECT visitor_id, ts FROM events WHERE domain_id = {$domainId} AND ts >= '{$start}'
            ) e ON e.visitor_id = fs.visitor_id
            GROUP BY fs.cohort
            ORDER BY fs.cohort ASC
        ");

        // Drop the last cohort — it hasn't had a full week to return yet.
        $rows = array_slice($rows, 0, -1);
        if (count($rows) < 6) {
            return null;
        }

        $pct = array_map(function ($r) {
            $size = (int) $r['size'];

            return $size > 0 ? ((int) $r['returned'] / $size) * 100 : 0.0;
        }, $rows);

        $recent = array_slice($pct, -3);
        $prior = array_slice($pct, -6, 3);
        [$recentMean] = $this->meanStd($recent);
        [$priorMean] = $this->meanStd($prior);
        if ($priorMean < 1e-6) {
            return null;
        }

        $deltaPts = $recentMean - $priorMean;
        if (abs($deltaPts) < 3) {
            return null; // within noise
        }

        $down = $deltaPts < 0;
        $totalRecentVisitors = array_sum(array_map(fn ($r) => (int) $r['size'], array_slice($rows, -3)));

        return [
            'kind' => 'retention_trend',
            'severity' => $down ? 'warning' : 'good',
            'title' => $down
                ? sprintf('Week-1 return rate falling: %.0f%% → %.0f%%', $priorMean, $recentMean)
                : sprintf('Week-1 return rate improving: %.0f%% → %.0f%%', $priorMean, $recentMean),
            'detail' => $down
                ? 'Newer visitors are less likely to come back a second week than visitors from a few weeks ago — something about the recent experience or traffic quality changed.'
                : 'Newer cohorts return at a higher rate than before — recent changes are making the site stickier.',
            'action' => $down
                ? 'Compare what changed recently: new traffic source, a UX regression, or missing follow-up (email/notifications).'
                : 'Identify what changed and protect it — this is measurably improving retention.',
            'impact' => abs($deltaPts) * max(1, $totalRecentVisitors) * 0.5,
        ];
    }

    /**
     * Revenue per channel (tracked conversions, attributed by the converting
     * session's medium) joined to ad spend. Two small queries + a PHP join —
     * conversions are low-volume, so this stays cheap.
     *
     * @return array<int, array{medium: string, revenue: float, orders: int, spend: float}>
     */
    private function revenueVsSpend(int $domainId, int $days): array
    {
        $convs = $this->ch->select("
            SELECT session_id, sum(value) AS v, count() AS orders
            FROM conversions
            WHERE domain_id = {$domainId} AND session_id != '' AND ts >= now() - INTERVAL {$days} DAY
            GROUP BY session_id
        ");
        if (empty($convs)) {
            return [];
        }

        $ids = array_map(fn ($r) => "'" . str_replace("'", '', (string) $r['session_id']) . "'", $convs);
        $inList = implode(',', array_slice($ids, 0, 5000));

        $mediumRows = $this->ch->select("
            SELECT session_id,
                if(any(utm_medium) != '', any(utm_medium),
                   if(any(referrer) != '', domain(any(referrer)), '(direct)')) AS medium
            FROM events
            WHERE domain_id = {$domainId} AND session_id IN ({$inList})
            GROUP BY session_id
        ");
        $mediumOf = [];
        foreach ($mediumRows as $r) {
            $mediumOf[(string) $r['session_id']] = (string) $r['medium'];
        }

        $byMedium = [];
        foreach ($convs as $r) {
            $m = $mediumOf[(string) $r['session_id']] ?? '(direct)';
            $byMedium[$m] ??= ['medium' => $m, 'revenue' => 0.0, 'orders' => 0, 'spend' => 0.0];
            $byMedium[$m]['revenue'] += (float) $r['v'];
            $byMedium[$m]['orders'] += (int) $r['orders'];
        }

        // Spend per medium from PostgreSQL.
        $spend = \Illuminate\Support\Facades\DB::table('ad_spend')
            ->selectRaw("lower(coalesce(nullif(medium, ''), source, '(none)')) AS medium, sum(spend) AS spend")
            ->where('domain_id', $domainId)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->groupBy('medium')
            ->get();
        foreach ($spend as $s) {
            $m = (string) $s->medium;
            $byMedium[$m] ??= ['medium' => $m, 'revenue' => 0.0, 'orders' => 0, 'spend' => 0.0];
            $byMedium[$m]['spend'] += (float) $s->spend;
        }

        return array_values($byMedium);
    }

    private function money(float $n): string
    {
        return number_format($n, $n >= 100 ? 0 : 2);
    }

    // ── Data + math helpers ───────────────────────────────────────────────────

    private function dailyTraffic(int $domainId, int $days): array
    {
        // Exclude today: it is a partial day and would fake a crash in the trend
        // and a low-traffic anomaly every single morning.
        return $this->ch->select("
            SELECT toDate(ts) AS d, uniq(visitor_id) AS visitors, uniq(session_id) AS sessions
            FROM events
            WHERE domain_id = {$domainId} AND type = 'pageview'
              AND ts >= today() - {$days} AND ts < today()
            GROUP BY d ORDER BY d
        ");
    }

    /** @return array{0: float, 1: float} [mean, sampleStd] */
    private function meanStd(array $xs): array
    {
        $n = count($xs);
        if ($n === 0) {
            return [0.0, 0.0];
        }
        $mean = array_sum($xs) / $n;
        if ($n < 2) {
            return [$mean, 0.0];
        }
        $var = 0.0;
        foreach ($xs as $x) {
            $var += ($x - $mean) ** 2;
        }

        return [$mean, sqrt($var / ($n - 1))];
    }

    /** Least-squares slope of y over x = 0..n-1. */
    private function slope(array $y): float
    {
        $n = count($y);
        if ($n < 2) {
            return 0.0;
        }
        $sx = $sy = $sxy = $sxx = 0.0;
        foreach ($y as $i => $v) {
            $sx += $i;
            $sy += $v;
            $sxy += $i * $v;
            $sxx += $i * $i;
        }
        $denom = ($n * $sxx) - ($sx * $sx);

        return abs($denom) < 1e-9 ? 0.0 : (($n * $sxy) - ($sx * $sy)) / $denom;
    }
}
