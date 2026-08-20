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

    /**
     * Three chained calls instead of one. Tested repeatedly: asking for both
     * languages (or even one language plus title/excerpt/keywords) in a
     * single response reliably undershoots — 396-625 words against a
     * 1500-word floor, regardless of JSON vs plain-text output or how hard
     * the prompt insists on length. A single call asked to write ONE body in
     * ONE language hits the target far more reliably; chat models seem to
     * self-limit total response length per turn more than per-language.
     */
    private function generateOne(OpenAiService $ai, array $topic): void
    {
        $bodyEn = $this->writeBody($ai, $topic['angle'], 'English');
        $bodyAr = $this->translateBody($ai, $bodyEn, 'Arabic');
        $meta = $this->writeMeta($ai, $topic['angle'], $bodyEn);

        foreach (['title_en', 'title_ar', 'excerpt_en', 'excerpt_ar'] as $field) {
            if (empty($meta[$field])) {
                throw new \RuntimeException("AI response missing '{$field}'");
            }
        }
        if (str_word_count($bodyEn) < 800 || count(preg_split('/\s+/u', trim($bodyAr))) < 800) {
            throw new \RuntimeException('Generated body came in too short even after the chained rewrite — skipping rather than publishing thin content.');
        }

        BlogPost::create([
            'slug' => $this->uniqueSlug($topic['key'] . '-' . $meta['title_en']),
            'title_en' => $meta['title_en'],
            'title_ar' => $meta['title_ar'],
            'excerpt_en' => Str::limit($meta['excerpt_en'], 500, ''),
            'excerpt_ar' => Str::limit($meta['excerpt_ar'], 500, ''),
            'keywords_en' => Str::limit($meta['keywords_en'] ?? '', 500, ''),
            'keywords_ar' => Str::limit($meta['keywords_ar'] ?? '', 500, ''),
            'body_en' => $bodyEn,
            'body_ar' => $bodyAr,
            'status' => 'published',
            'published_at' => now(),
            'related_url' => $topic['link'],
            'related_label' => $topic['label_en'],
            'related_label_ar' => $topic['label_ar'],
        ]);
    }

    private function writeBody(OpenAiService $ai, string $angle, string $language): string
    {
        $system = <<<SYS
You are a content writer for EYE Analytics, a privacy-first, cookieless website
analytics SaaS (heatmaps, session replay, funnels, AI daily reports).

Write ONE original, useful, in-depth blog post body in {$language} on the given
topic. Write AT LEAST 1500 words, target 1800-2200 — this is a hard
requirement, not a suggestion; a short answer is a failed answer no matter how
well written. Structure it as 11-14 sections of roughly 120-180 words each,
covering (in this rough order): the core concept and why it matters now, the
real cost of getting it wrong (concrete scenarios, not fake stats), a
step-by-step walkthrough a reader can follow today, 3-4 common mistakes and
how to avoid each, how to actually measure/verify the result, and a short
wrap-up. If you reach a natural ending before ~1500 words, do not stop — add
another concrete example, edge case, or common mistake instead of concluding.

Plain text only — no markdown, no HTML, no headers (no #, no **). Short
paragraphs (2-4 sentences) separated by blank lines; a line like "Why this
matters:" or a short question is fine as a paragraph lead-in. Educational and
practical, with concrete advice a site owner can act on today. Do NOT invent
statistics, customer names, case studies, or quotes — general industry
knowledge is fine (e.g. "most bounce happens on mobile") but never fabricate
a specific number as if it were EYE's own data. You may mention EYE Analytics
naturally two or three times across the piece as an example of a tool that
does this, but this is not an ad — the majority must be genuinely useful
independent of EYE.

Return ONLY the article body text — no title, no preamble, no markers, no
word count, nothing else.
SYS;

        $result = $ai->chat($system, [['role' => 'user', 'content' => "Topic: {$angle}"]], 4096);
        return trim($result['text']);
    }

    private function translateBody(OpenAiService $ai, string $bodyEn, string $language): string
    {
        $system = <<<SYS
You translate/adapt EYE Analytics blog posts into {$language}. This is a real
adaptation for {$language}-speaking readers, not a transliteration and not a
shortened summary — it must be a genuine, fluent, complete {$language} article
covering everything the English version covers, at the same depth, roughly
the same word count. Same plain-text formatting rules as the source: no
markdown, no headers, short paragraphs separated by blank lines.

Return ONLY the translated article body text — no title, no preamble, no
markers, nothing else.
SYS;

        $result = $ai->chat($system, [['role' => 'user', 'content' => $bodyEn]], 4096);
        return trim($result['text']);
    }

    /** Title/excerpt/keywords generated FROM the finished English body, so they actually describe the real content. */
    private function writeMeta(OpenAiService $ai, string $angle, string $bodyEn): array
    {
        $system = <<<'SYS'
Given a finished blog post body, write its title, excerpt, and SEO keywords.
Return PLAIN TEXT, exactly these six lines as markers, each on its own line,
content following the marker line, nothing else, no markdown fences:

TITLE_EN:
<English title>
TITLE_AR:
<Arabic title>
EXCERPT_EN:
<one English sentence, max 160 chars, summarizing this specific article>
EXCERPT_AR:
<one Arabic sentence>
KEYWORDS_EN:
<5-8 English SEO keywords/phrases SPECIFIC to this article's actual topic,
comma-separated. Must be different for every article, drawn from what THIS
piece actually covers — not a generic list that could caption any article>
KEYWORDS_AR:
<same 5-8 keywords, translated/localized to Arabic, comma-separated>
SYS;

        $user = "Topic: {$angle}\n\nArticle body:\n{$bodyEn}";
        $result = $ai->chat($system, [['role' => 'user', 'content' => $user]], 512);
        return $this->parseDelimited($result['text']);
    }

    /**
     * Parse the MARKER:\n<content>\n MARKER:... plain-text format back into
     * a field => value array. Deliberately not JSON — see the comment above
     * the system prompt for why.
     */
    private function parseDelimited(string $text): array
    {
        $markers = ['title_en' => 'TITLE_EN:', 'title_ar' => 'TITLE_AR:', 'excerpt_en' => 'EXCERPT_EN:', 'excerpt_ar' => 'EXCERPT_AR:', 'keywords_en' => 'KEYWORDS_EN:', 'keywords_ar' => 'KEYWORDS_AR:', 'body_en' => 'BODY_EN:', 'body_ar' => 'BODY_AR:'];

        $positions = [];
        foreach ($markers as $field => $marker) {
            $pos = strpos($text, $marker);
            if ($pos !== false) {
                $positions[$field] = $pos;
            }
        }
        asort($positions);
        $fields = array_keys($positions);

        $data = [];
        foreach ($fields as $i => $field) {
            $start = $positions[$field] + strlen($markers[$field]);
            $end = isset($fields[$i + 1]) ? $positions[$fields[$i + 1]] : strlen($text);
            $data[$field] = trim(substr($text, $start, $end - $start));
        }

        return $data;
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
