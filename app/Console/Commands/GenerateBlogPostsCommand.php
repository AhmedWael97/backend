<?php

namespace App\Console\Commands;

use App\Models\BlogPost;
use App\Services\OpenAiService;
use App\Services\SerperService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateBlogPostsCommand extends Command
{
    protected $signature = 'eye:generate-blog-posts {--count=2}';

    protected $description = 'Generate and publish N SEO blog posts (EN+AR), each linking to one real EYE feature page.';

    /**
     * Topic rotation. `link`/`label_en`/`label_ar` are ours, not the model's —
     * the AI never invents the URL it links to, so every internal link this
     * command produces is guaranteed to resolve.
     */
    private const TOPICS = [
        ['key' => 'heatmaps', 'angle' => 'click heatmaps and scroll-depth tracking for conversion optimization', 'link' => '/heatmaps', 'label_en' => 'See click heatmaps in EYE', 'label_ar' => 'شاهد الخرائط الحرارية في EYE'],
        ['key' => 'session-replay', 'angle' => 'session replay — watching real visitor recordings to find UX friction', 'link' => '/session-replay', 'label_en' => 'Watch session replays with EYE', 'label_ar' => 'شاهد إعادة تشغيل الجلسات في EYE'],
        ['key' => 'cookieless-analytics', 'angle' => 'cookieless, GDPR-compliant website analytics without a consent banner', 'link' => '/cookieless-analytics', 'label_en' => 'Try cookieless analytics free', 'label_ar' => 'جرّب تحليلات بدون كوكيز مجانًا'],
        ['key' => 'identify-visitors', 'angle' => 'identifying which B2B companies are browsing your website anonymously', 'link' => '/identify-visitors', 'label_en' => 'Identify visiting companies with EYE', 'label_ar' => 'حدّد الشركات الزائرة مع EYE'],
        ['key' => 'bounce-rate', 'angle' => 'why visitors bounce after one page and practical ways to reduce bounce rate', 'link' => '/heatmaps', 'label_en' => 'Find what visitors ignore with heatmaps', 'label_ar' => 'اكتشف ما يتجاهله زوّارك بالخرائط الحرارية'],
        ['key' => 'conversion-funnels', 'angle' => 'building conversion funnels to find the exact step visitors quit at', 'link' => '/see-why', 'label_en' => 'See where visitors drop off', 'label_ar' => 'اعرف أين يغادر زوّارك'],
        ['key' => 'web-vitals', 'angle' => 'Core Web Vitals, page speed, and why slow pages lose customers', 'link' => '/free-tools/speed-checker', 'label_en' => 'Check your site speed free', 'label_ar' => 'افحص سرعة موقعك مجانًا'],
        ['key' => 'seo-basics', 'angle' => 'on-page SEO fundamentals every small business site should get right', 'link' => '/free-tools/seo-checker', 'label_en' => 'Run a free SEO check', 'label_ar' => 'افحص السيو مجانًا'],
        ['key' => 'ga-alternative', 'angle' => 'what to look for in a privacy-first Google Analytics alternative', 'link' => '/alternatives/google-analytics-alternative', 'label_en' => 'Compare EYE vs. Google Analytics', 'label_ar' => 'قارن EYE مقابل Google Analytics'],
        ['key' => 'hotjar-alternative', 'angle' => 'comparing heatmap and session-replay tools before picking one', 'link' => '/alternatives/hotjar-alternative', 'label_en' => 'Compare EYE vs. Hotjar', 'label_ar' => 'قارن EYE مقابل Hotjar'],
        ['key' => 'mixpanel-alternative', 'angle' => 'product analytics vs. website analytics — which one you actually need', 'link' => '/alternatives/mixpanel-alternative', 'label_en' => 'Compare EYE vs. Mixpanel', 'label_ar' => 'قارن EYE مقابل Mixpanel'],
        ['key' => 'ab-testing', 'angle' => 'running a first A/B test on a landing page without writing code', 'link' => '/all-in-one', 'label_en' => 'See EYE plans & pricing', 'label_ar' => 'شاهد خطط EYE وأسعارها'],
    ];

    /**
     * Seed queries for real keyword discovery — broad enough to surface
     * genuine adjacent search intent via Serper's relatedSearches/PAA, one
     * picked per run so a single Serper call is enough.
     */
    private const DISCOVERY_SEEDS = [
        'website analytics tool', 'heatmap tool', 'session replay software',
        'cookieless analytics', 'conversion rate optimization', 'A/B testing tool',
        'visitor tracking software', 'google analytics alternative',
    ];

    /** Candidate phrase substring → real internal link + bilingual CTA label. First match wins. */
    private const LINK_CLASSIFIER = [
        'heatmap' => ['link' => '/heatmaps', 'label_en' => 'See click heatmaps in EYE', 'label_ar' => 'شاهد الخرائط الحرارية في EYE'],
        'replay' => ['link' => '/session-replay', 'label_en' => 'Watch session replays with EYE', 'label_ar' => 'شاهد إعادة تشغيل الجلسات في EYE'],
        'recording' => ['link' => '/session-replay', 'label_en' => 'Watch session replays with EYE', 'label_ar' => 'شاهد إعادة تشغيل الجلسات في EYE'],
        'hotjar' => ['link' => '/alternatives/hotjar-alternative', 'label_en' => 'Compare EYE vs. Hotjar', 'label_ar' => 'قارن EYE مقابل Hotjar'],
        'mixpanel' => ['link' => '/alternatives/mixpanel-alternative', 'label_en' => 'Compare EYE vs. Mixpanel', 'label_ar' => 'قارن EYE مقابل Mixpanel'],
        'google analytics' => ['link' => '/alternatives/google-analytics-alternative', 'label_en' => 'Compare EYE vs. Google Analytics', 'label_ar' => 'قارن EYE مقابل Google Analytics'],
        'ga4' => ['link' => '/alternatives/google-analytics-alternative', 'label_en' => 'Compare EYE vs. Google Analytics', 'label_ar' => 'قارن EYE مقابل Google Analytics'],
        'cookie' => ['link' => '/cookieless-analytics', 'label_en' => 'Try cookieless analytics free', 'label_ar' => 'جرّب تحليلات بدون كوكيز مجانًا'],
        'gdpr' => ['link' => '/cookieless-analytics', 'label_en' => 'Try cookieless analytics free', 'label_ar' => 'جرّب تحليلات بدون كوكيز مجانًا'],
        'privacy' => ['link' => '/cookieless-analytics', 'label_en' => 'Try cookieless analytics free', 'label_ar' => 'جرّب تحليلات بدون كوكيز مجانًا'],
        'identify' => ['link' => '/identify-visitors', 'label_en' => 'Identify visiting companies with EYE', 'label_ar' => 'حدّد الشركات الزائرة مع EYE'],
        'b2b' => ['link' => '/identify-visitors', 'label_en' => 'Identify visiting companies with EYE', 'label_ar' => 'حدّد الشركات الزائرة مع EYE'],
        'speed' => ['link' => '/free-tools/speed-checker', 'label_en' => 'Check your site speed free', 'label_ar' => 'افحص سرعة موقعك مجانًا'],
        'vitals' => ['link' => '/free-tools/speed-checker', 'label_en' => 'Check your site speed free', 'label_ar' => 'افحص سرعة موقعك مجانًا'],
        'seo' => ['link' => '/free-tools/seo-checker', 'label_en' => 'Run a free SEO check', 'label_ar' => 'افحص السيو مجانًا'],
        'funnel' => ['link' => '/see-why', 'label_en' => 'See where visitors drop off', 'label_ar' => 'اعرف أين يغادر زوّارك'],
        'drop off' => ['link' => '/see-why', 'label_en' => 'See where visitors drop off', 'label_ar' => 'اعرف أين يغادر زوّارك'],
        'a/b test' => ['link' => '/all-in-one', 'label_en' => 'See A/B testing in EYE', 'label_ar' => 'شاهد اختبارات A/B في EYE'],
        'ab test' => ['link' => '/all-in-one', 'label_en' => 'See A/B testing in EYE', 'label_ar' => 'شاهد اختبارات A/B في EYE'],
        'split test' => ['link' => '/all-in-one', 'label_en' => 'See A/B testing in EYE', 'label_ar' => 'شاهد اختبارات A/B في EYE'],
    ];

    public function handle(OpenAiService $ai, SerperService $serper): int
    {
        $count = max(1, (int) $this->option('count'));

        // Skip topics already published in the last 30 days so the rotation
        // doesn't repeat the same angle back-to-back.
        $recentPosts = BlogPost::where('created_at', '>=', now()->subDays(30))->pluck('title_en');
        $recentSlugPrefixes = BlogPost::where('created_at', '>=', now()->subDays(30))
            ->pluck('slug')
            ->map(fn ($s) => explode('-', $s)[0] ?? $s)
            ->all();

        $pool = array_values(array_filter(self::TOPICS, fn ($t) => !in_array($t['key'], $recentSlugPrefixes, true)));

        // Real keyword research — genuine adjacent search intent from Serper's
        // relatedSearches/PAA, not guessed. Discovered topics are always
        // "fresh" so they aren't recency-filtered like the curated list.
        if ($serper->configured()) {
            $discovered = $this->discoverTopics($serper, $recentPosts);
            $pool = array_merge($pool, $discovered);
            $this->info('Discovered ' . count($discovered) . ' keyword-research topic(s).');
        }

        if (count($pool) < $count) {
            $pool = array_merge($pool, self::TOPICS); // exhausted the rotation — allow repeats rather than publishing nothing
        }
        shuffle($pool);
        $topics = array_slice($pool, 0, $count);

        $ok = 0;
        foreach ($topics as $topic) {
            try {
                $this->generateOne($ai, $topic);
                $ok++;
            } catch (\Throwable $e) {
                report($e);
                $this->error("Failed to generate post for '{$topic['key']}': {$e->getMessage()}");
            }
        }

        $this->info("Published {$ok}/{$count} blog post(s).");
        return self::SUCCESS;
    }

    /**
     * Real keyword research: one seed query (rotated by day-of-year so
     * repeated runs the same day reuse the same discovery, avoiding wasted
     * Serper calls), related searches + People Also Ask off it, filtered
     * against recent post titles, each mapped to a real internal link via
     * LINK_CLASSIFIER — never an invented URL.
     *
     * @return array<int, array{key: string, angle: string, link: string, label_en: string, label_ar: string}>
     */
    private function discoverTopics(SerperService $serper, \Illuminate\Support\Collection $recentTitles): array
    {
        $seed = self::DISCOVERY_SEEDS[now()->dayOfYear % count(self::DISCOVERY_SEEDS)];

        try {
            $candidates = array_merge($serper->relatedSearches($seed), $serper->peopleAlsoAsk($seed));
        } catch (\Throwable $e) {
            report($e);
            return [];
        }

        $recentLower = $recentTitles->map(fn ($t) => strtolower((string) $t));
        $topics = [];

        foreach (array_unique($candidates) as $phrase) {
            $phrase = trim($phrase);
            if ($phrase === '' || strlen($phrase) > 120) {
                continue;
            }
            // Skip anything too close to a title we already published recently.
            $lower = strtolower($phrase);
            $tooSimilar = $recentLower->contains(fn ($t) => str_contains($t, $lower) || str_contains($lower, $t));
            if ($tooSimilar) {
                continue;
            }

            $classified = null;
            foreach (self::LINK_CLASSIFIER as $needle => $target) {
                if (str_contains($lower, $needle)) {
                    $classified = $target;
                    break;
                }
            }
            $classified ??= ['link' => '/all-in-one', 'label_en' => 'See what EYE tracks', 'label_ar' => 'شاهد ما يتتبّعه EYE'];

            $topics[] = [
                'key' => 'discovered-' . Str::slug($phrase),
                'angle' => $phrase,
                'link' => $classified['link'],
                'label_en' => $classified['label_en'],
                'label_ar' => $classified['label_ar'],
            ];
            if (count($topics) >= 4) {
                break; // plenty for one run's --count
            }
        }

        return $topics;
    }

    private function generateOne(OpenAiService $ai, array $topic): void
    {
        $system = <<<'SYS'
You are a content writer for EYE Analytics, a privacy-first, cookieless website
analytics SaaS (heatmaps, session replay, funnels, AI daily reports).

HARD REQUIREMENT — read this first: body_en and body_ar must each be AT LEAST
1500 words, target 1800-2200. This is the single most important rule in this
prompt. A short answer is a failed answer, even if well-written — length
matters as much as quality here. To hit this reliably: write 11-14 sections,
each 120-180 words, covering (in this rough order) — the core concept and why
it matters now, the real cost of getting it wrong (concrete scenarios, not
fake stats), a step-by-step walkthrough a reader can follow today, 3-4 common
mistakes and how to avoid each one, how to actually measure/verify the
result, and a short wrap-up. Do not stop early — if you reach a natural
ending before ~1500 words, go back and add another concrete example, edge
case, or common mistake instead of concluding.

Write ONE original, useful, well-structured, in-depth blog post in both English
and Arabic on the given topic. Rules:
- Plain text only — no markdown, no HTML tags.
- Structure as short paragraphs (2-4 sentences), separated by blank lines.
  You may use a line like "Why this matters:" or a short question as a
  natural paragraph lead-in, but do NOT use markdown headers (no #, no **).
- Cover the topic in real depth: what it means, why it matters, concrete
  step-by-step advice, common mistakes, and how to actually measure/verify
  the result. Anticipate and answer the obvious follow-up questions a reader
  would have, in-line, as part of the natural flow.
- Educational and practical — explain the concept, give concrete advice a
  site owner can act on. Do NOT invent statistics, customer names, case
  studies, or quotes that aren't true. General industry knowledge is fine
  (e.g. "most bounce happens on mobile") but never fabricate a specific
  number as if it were EYE's own data.
- You may mention EYE Analytics naturally two or three times across the piece
  as an example of a tool that does this, but this is not an ad — the
  majority of the post must be genuinely useful independent of EYE.
- Arabic must be a real translation/adaptation, not transliteration, and
  MUST be equally long and thorough as the English version — not a shortened
  summary of it. Apply the same 1500-word-minimum, 11-14 section structure.
- Return ONLY a JSON object, no other text, shaped exactly as:
{"title_en": "...", "title_ar": "...", "excerpt_en": "one sentence, max 160 chars", "excerpt_ar": "...", "body_en": "...", "body_ar": "..."}
Before returning, check body_en and body_ar are each 1500+ words — if either
is shorter, keep writing until it isn't.
SYS;

        $user = "Topic: {$topic['angle']}";

        $data = $ai->complete($system, $user, 8192);

        foreach (['title_en', 'title_ar', 'excerpt_en', 'excerpt_ar', 'body_en', 'body_ar'] as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("Anthropic response missing '{$field}'");
            }
        }

        BlogPost::create([
            'slug' => $this->uniqueSlug($topic['key'] . '-' . $data['title_en']),
            'title_en' => $data['title_en'],
            'title_ar' => $data['title_ar'],
            'excerpt_en' => Str::limit($data['excerpt_en'], 500, ''),
            'excerpt_ar' => Str::limit($data['excerpt_ar'], 500, ''),
            'body_en' => $data['body_en'],
            'body_ar' => $data['body_ar'],
            'status' => 'published',
            'published_at' => now(),
            'related_url' => $topic['link'],
            'related_label' => $topic['label_en'],
            'related_label_ar' => $topic['label_ar'],
        ]);
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'post';
        $slug = Str::limit($slug, 80, '');
        $orig = $slug;
        $i = 1;
        while (BlogPost::where('slug', $slug)->exists()) {
            $slug = $orig . '-' . (++$i);
        }
        return $slug;
    }
}
