<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AdSpend;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/analytics/{domainId}/campaigns
 *
 * Returns campaign performance grouped by source / medium / campaign.
 *
 * Source attribution priority (per session):
 *   1. utm_source (explicit campaign tagging)
 *   2. Classified referrer (Google, Facebook, Instagram, X, LinkedIn, …)
 *   3. Bare registered domain of the referrer (catch-all)
 *   4. "(direct)" when no referrer is present
 *
 * Medium attribution priority:
 *   1. utm_medium
 *   2. "organic"  — search engines (Google, Bing, DuckDuckGo, Yahoo, Yandex, Baidu, …)
 *   3. "social"   — Facebook, Instagram, X/Twitter, LinkedIn, YouTube, TikTok, Reddit, …
 *   4. "email"    — Gmail, Outlook, Yahoo Mail
 *   5. "referral" — any other referring site
 *   6. "(none)"   — direct
 *
 * The classification runs against the `events` table (source of truth) so
 * sessions written before the referrer fix to ProcessTrackingEvent still
 * benefit from accurate attribution.
 */
class CampaignsController extends Controller
{
    public function __construct(private ClickHouseService $ch)
    {
    }

    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();

        $start = $request->query('start', now()->subDays(30)->toDateString());
        $end = $request->query('end', now()->toDateString());
        $goal = $request->query('goal');

        $startDt = $start . ' 00:00:00';
        $endDt = $end . ' 23:59:59';
        $domainId = (int) $domain->id;

        // Bind the goal as a parameter rather than escaping into the SQL. The
        // events-CTE references :goal_pattern only when a goal is provided, so
        // we conditionally include the param in each query's $params array.
        $goalSubClause = $goal
            ? "maxIf(1, type = 'pageview' AND url LIKE :goal_pattern) AS has_goal"
            : '0 AS has_goal';
        $goalParams = $goal ? ['goal_pattern' => '%' . $goal . '%'] : [];

        $sourceSql = $this->sourceClassificationSql();
        $mediumSql = $this->mediumClassificationSql();

        // Per-session aggregation (events table is the source of truth)
        $sessionsCte = "
            SELECT
                session_id,
                visitor_id,
                anyIf(utm_source,   utm_source   != '') AS utm_source,
                anyIf(utm_medium,   utm_medium   != '') AS utm_medium,
                anyIf(utm_campaign, utm_campaign != '') AS utm_campaign,
                -- Entry referrer: first non-empty referrer captured on a pageview
                anyIf(referrer, referrer != '' AND type = 'pageview') AS referrer,
                countIf(type = 'pageview')              AS pv_count,
                sumIf(duration, type = 'time_on_page')  AS tot_duration,
                min(ts)                                 AS started_at,
                max(ts)                                 AS last_ts,
                {$goalSubClause}
            FROM events
            WHERE domain_id = {$domainId}
              AND ts BETWEEN '{$startDt}' AND '{$endDt}'
            GROUP BY session_id, visitor_id
        ";

        // ── Campaign table ────────────────────────────────────────────────────
        $rows = $this->ch->select("
            SELECT
                {$sourceSql} AS source,
                {$mediumSql} AS medium,
                {$this->cleanCampaignSql()} AS campaign,
                uniq(session_id) AS sessions,
                uniq(visitor_id) AS visitors,
                round(avg(tot_duration)) AS avg_duration,
                round(avg(pv_count), 1)  AS avg_pages,
                round(toFloat64(countIf(pv_count = 1)) / nullIf(uniq(session_id), 0) * 100, 1) AS bounce_rate,
                countIf(has_goal = 1) AS conversions,
                max(last_ts) AS last_seen
            FROM ({$sessionsCte})
            GROUP BY source, medium, campaign
            ORDER BY sessions DESC
            LIMIT 200
        ", $goalParams);

        // ── Revenue attribution (cross-session, selectable model) ─────────────
        // Purchases are stored as `conversions` rows (see ProcessTrackingEvent).
        // Each order is credited to the campaign touch(es) the visitor had at or
        // before the purchase. Supported models (?attribution=):
        //   last_touch  (default) — 100% to the most recent touch
        //   first_touch           — 100% to the earliest touch
        //   linear                — split equally across all prior touches
        //   time_decay            — weight touches by recency (7-day half-life)
        // Computed in PHP: conversions are low-volume and touches are fetched only
        // for converting visitors, so this stays bounded. Wrapped in try/catch so
        // the endpoint works before the conversions table has been migrated.
        $model = (string) $request->query('attribution', 'last_touch');
        if (!in_array($model, ['last_touch', 'first_touch', 'linear', 'time_decay'], true)) {
            $model = 'last_touch';
        }

        $revenueByKey = [];
        $totalRevenue = 0.0;
        $currency = '';
        try {
            // De-duplicate orders — a reloaded confirmation page can re-fire.
            $convs = $this->ch->select("
                SELECT
                    order_id,
                    any(visitor_id)    AS visitor_id,
                    argMax(value, ts)  AS value,
                    max(ts)            AS ts
                FROM conversions
                WHERE domain_id = {$domainId}
                  AND ts BETWEEN '{$startDt}' AND '{$endDt}'
                GROUP BY order_id
            ");

            // Total revenue is model-independent.
            foreach ($convs as $c) {
                $totalRevenue += (float) $c['value'];
            }

            // Fetch each converting visitor's campaign touches (one per session).
            $visitorIds = array_values(array_unique(array_filter(
                array_map(fn($c) => (string) $c['visitor_id'], $convs)
            )));

            $touchesByVisitor = [];
            if ($visitorIds) {
                $inList = implode(',', array_map(fn($v) => "'" . addslashes($v) . "'", $visitorIds));
                $touchRows = $this->ch->select("
                    SELECT
                        visitor_id,
                        touch_ts,
                        {$sourceSql} AS source,
                        {$mediumSql} AS medium,
                        {$this->cleanCampaignSql()} AS campaign
                    FROM (
                        SELECT
                            session_id,
                            visitor_id,
                            min(ts) AS touch_ts,
                            anyIf(utm_source,   utm_source   != '') AS utm_source,
                            anyIf(utm_medium,   utm_medium   != '') AS utm_medium,
                            anyIf(utm_campaign, utm_campaign != '') AS utm_campaign,
                            anyIf(referrer, referrer != '' AND type = 'pageview') AS referrer
                        FROM events
                        WHERE domain_id = {$domainId}
                          AND ts BETWEEN '{$startDt}' AND '{$endDt}'
                          AND visitor_id IN ({$inList})
                        GROUP BY session_id, visitor_id
                    )
                ");
                foreach ($touchRows as $t) {
                    $touchesByVisitor[(string) $t['visitor_id']][] = [
                        'ts' => strtotime((string) $t['touch_ts']),
                        'source' => ($t['source'] ?? '') !== '' ? $t['source'] : '(direct)',
                        'medium' => ($t['medium'] ?? '') !== '' ? $t['medium'] : '(none)',
                        'campaign' => ($t['campaign'] ?? '') !== '' ? $t['campaign'] : '(none)',
                    ];
                }
            }

            $halfLife = 7 * 86400; // 7-day half-life for time_decay

            foreach ($convs as $c) {
                $vid = (string) $c['visitor_id'];
                $value = (float) $c['value'];
                $convTs = strtotime((string) $c['ts']);

                // Touches at or before the purchase, oldest first.
                $touches = array_values(array_filter(
                    $touchesByVisitor[$vid] ?? [],
                    fn($t) => $t['ts'] !== false && $t['ts'] <= $convTs
                ));
                usort($touches, fn($a, $b) => $a['ts'] <=> $b['ts']);

                if (empty($touches)) {
                    // No tracked touch in window — credit to (direct).
                    $this->credit($revenueByKey, '(direct)|(none)|(none)', $value, 1.0);
                    continue;
                }

                $n = count($touches);
                $weights = array_fill(0, $n, 0.0);
                if ($model === 'first_touch') {
                    $weights[0] = 1.0;
                } elseif ($model === 'linear') {
                    foreach ($weights as $i => $_) {
                        $weights[$i] = 1.0 / $n;
                    }
                } elseif ($model === 'time_decay') {
                    $sum = 0.0;
                    foreach ($touches as $i => $t) {
                        $weights[$i] = pow(2, -(($convTs - $t['ts']) / $halfLife));
                        $sum += $weights[$i];
                    }
                    foreach ($weights as $i => $w) {
                        $weights[$i] = $sum > 0 ? $w / $sum : 1.0 / $n;
                    }
                } else { // last_touch
                    $weights[$n - 1] = 1.0;
                }

                foreach ($weights as $i => $w) {
                    if ($w <= 0) {
                        continue;
                    }
                    $t = $touches[$i];
                    $this->credit($revenueByKey, $t['source'] . '|' . $t['medium'] . '|' . $t['campaign'], $value, $w);
                }
            }

            // Dominant currency for display (assumes one currency per domain).
            $curRow = $this->ch->select("
                SELECT currency, sum(value) AS v
                FROM conversions
                WHERE domain_id = {$domainId}
                  AND ts BETWEEN '{$startDt}' AND '{$endDt}'
                  AND currency != ''
                GROUP BY currency
                ORDER BY v DESC
                LIMIT 1
            ");
            $currency = $curRow[0]['currency'] ?? '';
        } catch (\Throwable $e) {
            // conversions table not migrated yet, or query failed — revenue stays 0.
            report($e);
        }

        // Merge revenue into the campaign rows by (source|medium|campaign).
        foreach ($rows as &$r) {
            $key = $r['source'] . '|' . $r['medium'] . '|' . $r['campaign'];
            $r['orders'] = isset($revenueByKey[$key]) ? round($revenueByKey[$key]['orders'], 2) : 0;
            $r['revenue'] = isset($revenueByKey[$key]) ? round($revenueByKey[$key]['revenue'], 2) : 0;
            unset($revenueByKey[$key]);
        }
        unset($r);

        // Append revenue whose campaign tuple had no sessions in this window
        // (e.g. a visitor who bought after their campaign session aged out).
        foreach ($revenueByKey as $key => $rev) {
            [$src, $med, $camp] = array_pad(explode('|', $key, 3), 3, '');
            $rows[] = [
                'source' => $src,
                'medium' => $med,
                'campaign' => $camp,
                'sessions' => 0,
                'visitors' => 0,
                'avg_duration' => 0,
                'avg_pages' => 0,
                'bounce_rate' => 0,
                'conversions' => 0,
                'orders' => round($rev['orders'], 2),
                'revenue' => round($rev['revenue'], 2),
                'last_seen' => null,
            ];
        }

        // ── Ad spend → ROAS / CPA ─────────────────────────────────────────────
        // Spend is stored in PostgreSQL (ad_spend table) and matched to campaign
        // rows by (source, campaign). Wrapped in try/catch so the endpoint works
        // before the ad_spend migration has run.
        $spendByKey = [];
        $totalSpend = 0.0;
        try {
            $spendRows = AdSpend::where('domain_id', $domainId)
                ->whereBetween('date', [$start, $end])
                ->selectRaw('source, campaign, SUM(spend) AS spend')
                ->groupBy('source', 'campaign')
                ->get();
            foreach ($spendRows as $sp) {
                $key = $sp->source . '|' . $sp->campaign;
                $spendByKey[$key] = (float) $sp->spend;
                $totalSpend += (float) $sp->spend;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        foreach ($rows as &$r) {
            $key = $r['source'] . '|' . $r['campaign'];
            $spend = $spendByKey[$key] ?? 0.0;
            $revenue = (float) ($r['revenue'] ?? 0);
            $orders = (int) ($r['orders'] ?? 0);
            $r['spend'] = round($spend, 2);
            // ROAS = revenue / spend; CPA = spend / orders. Null when undefined
            // so the UI can show "—" rather than a misleading 0 or ∞.
            $r['roas'] = $spend > 0 ? round($revenue / $spend, 2) : null;
            $r['cpa'] = ($spend > 0 && $orders > 0) ? round($spend / $orders, 2) : null;
        }
        unset($r);

        // ── Top sources (for chart) ───────────────────────────────────────────
        $sources = $this->ch->select("
            SELECT
                {$sourceSql} AS source,
                {$mediumSql} AS medium,
                uniq(session_id) AS sessions,
                uniq(visitor_id) AS visitors
            FROM ({$sessionsCte})
            GROUP BY source, medium
            ORDER BY sessions DESC
            LIMIT 10
        ", $goalParams);

        // ── Daily trend per source (top 5 sources) ────────────────────────────
        $topSources = array_slice(array_map(fn($r) => $r['source'], $sources), 0, 5);
        $sourceList = implode(',', array_map(fn($s) => "'" . addslashes($s) . "'", $topSources));

        $trend = [];
        if ($topSources) {
            $trend = $this->ch->select("
                SELECT
                    toDate(started_at) AS date,
                    source,
                    uniq(session_id) AS sessions
                FROM (
                    SELECT
                        {$sourceSql} AS source,
                        session_id,
                        started_at
                    FROM ({$sessionsCte})
                )
                WHERE source IN ({$sourceList})
                GROUP BY date, source
                ORDER BY date ASC
            ", $goalParams);
        }

        return response()->json([
            'statusCode' => 200,
            'statusText' => 'success',
            'data' => [
                'campaigns' => $rows,
                'top_sources' => $sources,
                'trend' => $trend,
                'total_revenue' => round($totalRevenue, 2),
                'total_spend' => round($totalSpend, 2),
                'currency' => $currency,
                'attribution' => $model,
            ],
        ]);
    }

    /**
     * Add weighted credit (orders + revenue) for one attribution touch.
     *
     * @param array<string, array{orders: float, revenue: float}> $map
     */
    private function credit(array &$map, string $key, float $value, float $weight): void
    {
        if (!isset($map[$key])) {
            $map[$key] = ['orders' => 0.0, 'revenue' => 0.0];
        }
        $map[$key]['orders'] += $weight;
        $map[$key]['revenue'] += $value * $weight;
    }

    /**
     * True when a UTM value is real — not blank, not corrupted (contains the
     * UTF-8 replacement char, U+FFFD — happens when an ad network's redirect
     * chain mangles the query string), and not an ad platform's dynamic-tag
     * placeholder that failed to interpolate (TikTok's "__CAMPAIGN_NAME__",
     * Meta's "{{campaign.name}}"). Garbage/placeholder values fall through to
     * referrer-based classification instead of polluting the campaigns list.
     */
    private function isCleanUtmSql(string $col): string
    {
        return "{$col} != '' AND position({$col}, unhex('EFBFBD')) = 0"
            . " AND NOT startsWith({$col}, '__') AND NOT startsWith({$col}, '{')";
    }

    /** utm_campaign for display — '(not set)' for blank/corrupted/unexploded-macro values. */
    private function cleanCampaignSql(): string
    {
        $clean = $this->isCleanUtmSql('utm_campaign');
        return "if({$clean}, utm_campaign, '(not set)')";
    }

    /**
     * SQL fragment that turns (utm_source, referrer) into a friendly source name.
     * A clean utm_source is aliased to a canonical platform name (ad platforms
     * are inconsistent — "ig"/"instagram"/"Instagram" should be one row, not
     * three) instead of used verbatim.
     *
     * Uses ClickHouse helpers:
     *   - domain(url):                       full host, e.g. "mail.google.com"
     *   - cutToFirstSignificantSubdomain(url): registered domain, e.g. "google.com"
     */
    private function sourceClassificationSql(): string
    {
        $cleanSource = $this->isCleanUtmSql('utm_source');
        return <<<SQL
            multiIf(
                {$cleanSource},
                    multiIf(
                        lower(utm_source) IN ('ig', 'instagram'),                                          'Instagram',
                        lower(utm_source) IN ('fb', 'facebook'),                                            'Facebook',
                        lower(utm_source) IN ('tiktok', 'tt'),                                              'TikTok',
                        lower(utm_source) IN ('google', 'adwords', 'gads', 'googleads', 'google_ads'),      'Google',
                        lower(utm_source) IN ('snap', 'snapchat'),                                          'Snapchat',
                        lower(utm_source) IN ('twitter', 'x'),                                              'X (Twitter)',
                        lower(utm_source) IN ('yt', 'youtube'),                                             'YouTube',
                        lower(utm_source) IN ('wa', 'whatsapp'),                                            'WhatsApp',
                        lower(utm_source) IN ('tg', 'telegram'),                                            'Telegram',
                        lower(utm_source) IN ('li', 'linkedin'),                                            'LinkedIn',
                        utm_source
                    ),
                referrer    = '',                                                                                       '(direct)',

                -- ── Email clients (check full host before search engines, since mail.google.com → google.com) ──
                domain(referrer) IN ('mail.google.com', 'gmail.com', 'inbox.google.com'),                              'Gmail',
                domain(referrer) IN ('outlook.live.com', 'outlook.office.com', 'outlook.com', 'mail.live.com'),        'Outlook',
                domain(referrer) IN ('mail.yahoo.com'),                                                                'Yahoo Mail',
                domain(referrer) IN ('mail.proton.me', 'mail.protonmail.com'),                                         'ProtonMail',

                -- ── Search engines ─────────────────────────────────────────────────────────────────────────────
                startsWith(cutToFirstSignificantSubdomain(referrer), 'google.'),                                       'Google',
                cutToFirstSignificantSubdomain(referrer) IN ('bing.com'),                                              'Bing',
                cutToFirstSignificantSubdomain(referrer) IN ('duckduckgo.com'),                                        'DuckDuckGo',
                startsWith(cutToFirstSignificantSubdomain(referrer), 'yahoo.'),                                        'Yahoo',
                startsWith(cutToFirstSignificantSubdomain(referrer), 'yandex.'),                                       'Yandex',
                cutToFirstSignificantSubdomain(referrer) IN ('baidu.com'),                                             'Baidu',
                cutToFirstSignificantSubdomain(referrer) IN ('ecosia.org', 'qwant.com', 'startpage.com', 'brave.com'), 'Other search',

                -- ── Social ─────────────────────────────────────────────────────────────────────────────────────
                cutToFirstSignificantSubdomain(referrer) IN ('facebook.com', 'fb.com', 'fb.me'),                       'Facebook',
                cutToFirstSignificantSubdomain(referrer) IN ('messenger.com'),                                         'Messenger',
                cutToFirstSignificantSubdomain(referrer) IN ('instagram.com', 'instagr.am'),                           'Instagram',
                cutToFirstSignificantSubdomain(referrer) IN ('twitter.com', 'x.com', 't.co'),                          'X (Twitter)',
                cutToFirstSignificantSubdomain(referrer) IN ('threads.net'),                                           'Threads',
                cutToFirstSignificantSubdomain(referrer) IN ('linkedin.com', 'lnkd.in'),                               'LinkedIn',
                cutToFirstSignificantSubdomain(referrer) IN ('youtube.com', 'youtu.be'),                               'YouTube',
                cutToFirstSignificantSubdomain(referrer) IN ('tiktok.com'),                                            'TikTok',
                cutToFirstSignificantSubdomain(referrer) IN ('reddit.com'),                                            'Reddit',
                cutToFirstSignificantSubdomain(referrer) IN ('pinterest.com', 'pin.it'),                               'Pinterest',
                cutToFirstSignificantSubdomain(referrer) IN ('snapchat.com'),                                          'Snapchat',
                cutToFirstSignificantSubdomain(referrer) IN ('whatsapp.com', 'wa.me'),                                 'WhatsApp',
                cutToFirstSignificantSubdomain(referrer) IN ('t.me', 'telegram.org'),                                  'Telegram',
                cutToFirstSignificantSubdomain(referrer) IN ('discord.com', 'discord.gg'),                             'Discord',
                cutToFirstSignificantSubdomain(referrer) IN ('vk.com'),                                                'VKontakte',

                -- ── Communities / pro ──────────────────────────────────────────────────────────────────────────
                cutToFirstSignificantSubdomain(referrer) IN ('github.com'),                                            'GitHub',
                cutToFirstSignificantSubdomain(referrer) IN ('medium.com'),                                            'Medium',
                cutToFirstSignificantSubdomain(referrer) IN ('quora.com'),                                             'Quora',
                cutToFirstSignificantSubdomain(referrer) IN ('stackoverflow.com'),                                     'Stack Overflow',
                cutToFirstSignificantSubdomain(referrer) IN ('producthunt.com'),                                       'Product Hunt',
                domain(referrer) = 'news.ycombinator.com',                                                             'Hacker News',
                cutToFirstSignificantSubdomain(referrer) IN ('substack.com'),                                          'Substack',

                -- ── AI / chat referrers (worth their own bucket) ───────────────────────────────────────────────
                cutToFirstSignificantSubdomain(referrer) IN ('chatgpt.com', 'openai.com'),                             'ChatGPT',
                cutToFirstSignificantSubdomain(referrer) IN ('claude.ai', 'anthropic.com'),                            'Claude',
                cutToFirstSignificantSubdomain(referrer) IN ('perplexity.ai'),                                         'Perplexity',
                cutToFirstSignificantSubdomain(referrer) IN ('gemini.google.com'),                                     'Gemini',

                -- ── Fallback: bare registered domain (or empty marker if extraction fails) ─────────────────────
                if(cutToFirstSignificantSubdomain(referrer) = '', '(direct)', cutToFirstSignificantSubdomain(referrer))
            )
        SQL;
    }

    /**
     * SQL fragment that turns (utm_medium, utm_source, referrer) into a medium bucket.
     */
    private function mediumClassificationSql(): string
    {
        $cleanMedium = $this->isCleanUtmSql('utm_medium');
        $cleanSource = $this->isCleanUtmSql('utm_source');
        return <<<SQL
            multiIf(
                {$cleanMedium},                                                                                          utm_medium,
                {$cleanSource},                                                                                          'campaign',
                referrer    = '',                                                                                       '(none)',

                -- Email
                domain(referrer) IN (
                    'mail.google.com', 'gmail.com', 'inbox.google.com',
                    'outlook.live.com', 'outlook.office.com', 'outlook.com', 'mail.live.com',
                    'mail.yahoo.com', 'mail.proton.me', 'mail.protonmail.com'
                ),                                                                                                      'email',

                -- Search engines
                startsWith(cutToFirstSignificantSubdomain(referrer), 'google.')
                    OR cutToFirstSignificantSubdomain(referrer) IN ('bing.com', 'duckduckgo.com', 'baidu.com', 'ecosia.org', 'qwant.com', 'startpage.com', 'brave.com')
                    OR startsWith(cutToFirstSignificantSubdomain(referrer), 'yahoo.')
                    OR startsWith(cutToFirstSignificantSubdomain(referrer), 'yandex.'),                                'organic',

                -- Social platforms
                cutToFirstSignificantSubdomain(referrer) IN (
                    'facebook.com','fb.com','fb.me','messenger.com',
                    'instagram.com','instagr.am','threads.net',
                    'twitter.com','x.com','t.co',
                    'linkedin.com','lnkd.in',
                    'youtube.com','youtu.be',
                    'tiktok.com','reddit.com',
                    'pinterest.com','pin.it',
                    'snapchat.com','whatsapp.com','wa.me',
                    't.me','telegram.org',
                    'discord.com','discord.gg','vk.com'
                ),                                                                                                      'social',

                -- AI assistants
                cutToFirstSignificantSubdomain(referrer) IN (
                    'chatgpt.com','openai.com','claude.ai','anthropic.com','perplexity.ai','gemini.google.com'
                ),                                                                                                      'ai',

                -- Everything else with a referrer
                'referral'
            )
        SQL;
    }
}
