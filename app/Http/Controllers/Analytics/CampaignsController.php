<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
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
            ->when(!$user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        $start = $request->query('start', now()->subDays(30)->toDateString());
        $end = $request->query('end', now()->toDateString());
        $goal = $request->query('goal');

        $startDt = $start . ' 00:00:00';
        $endDt = $end . ' 23:59:59';
        $domainId = (int) $domain->id;

        $safeGoal = $goal ? str_replace(["\\", "'"], ["\\\\", "\\'"], $goal) : '';
        $goalSubClause = $goal
            ? "maxIf(1, type = 'pageview' AND url LIKE '%{$safeGoal}%') AS has_goal"
            : '0 AS has_goal';

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
                if(utm_campaign = '', '(none)', utm_campaign) AS campaign,
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
        ");

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
        ");

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
            ");
        }

        return response()->json([
            'statusCode' => 200,
            'statusText' => 'success',
            'data' => [
                'campaigns' => $rows,
                'top_sources' => $sources,
                'trend' => $trend,
            ],
        ]);
    }

    /**
     * SQL fragment that turns (utm_source, referrer) into a friendly source name.
     *
     * Uses ClickHouse helpers:
     *   - domain(url):                       full host, e.g. "mail.google.com"
     *   - cutToFirstSignificantSubdomain(url): registered domain, e.g. "google.com"
     */
    private function sourceClassificationSql(): string
    {
        return <<<'SQL'
            multiIf(
                utm_source != '',                                                                                       utm_source,
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
        return <<<'SQL'
            multiIf(
                utm_medium != '',                                                                                       utm_medium,
                utm_source != '',                                                                                       'campaign',
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
