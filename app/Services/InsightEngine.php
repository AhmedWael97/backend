<?php

namespace App\Services;

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
