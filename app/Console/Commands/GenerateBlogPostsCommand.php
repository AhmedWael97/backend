<?php

namespace App\Console\Commands;

use App\Models\BlogPost;
use App\Services\GeminiService;
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

    public function handle(GeminiService $ai, SerperService $serper): int
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
        // Every topic failing (e.g. a dead/revoked API key) must not look like
        // a clean run to the scheduler — this exact silent-success bug is why
        // a real key outage went unnoticed at today's 06:00 run.
        return $ok > 0 ? self::SUCCESS : self::FAILURE;
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
     * Outline + one call per section, concatenated — instead of asking for
     * the whole 1500+ word body in one completion. Tested repeatedly and a
     * single call will NOT reliably hit that length no matter how the prompt
     * is written: JSON mode landed 396-625 words, plain-text single-call
     * landed 583, plain-text single-language-only landed 745 — and that last
     * one used only 1340 of its 4096 token budget and ended on a clean,
     * natural concluding sentence. It wasn't truncating, it was choosing to
     * stop. Only genuine multi-call chaining reliably produces long-form
     * content from this model: each section call only has to write ~150-200
     * words, which it will actually do.
     */
    private function generateOne(GeminiService $ai, array $topic): void
    {
        $outline = $this->writeOutline($ai, $topic['angle']);
        if (count($outline) < 6) {
            throw new \RuntimeException('Outline came back too short (' . count($outline) . ' sections) — skipping.');
        }

        $sectionsEn = [];
        $sectionsAr = [];
        foreach ($outline as $i => $brief) {
            $sectionEn = $this->writeSection($ai, $topic['angle'], $outline, $i, $brief);
            $sectionsEn[] = $sectionEn;
            // Translate per-section, not the whole finished body in one call —
            // Gemini's free-tier output cap (800 tokens) can't fit a full
            // ~2000-word translation in one shot, but easily fits one section.
            $sectionsAr[] = $this->translateSection($ai, $sectionEn, 'Arabic');
        }
        $bodyEn = trim(implode("\n\n", array_filter($sectionsEn)));
        $bodyAr = trim(implode("\n\n", array_filter($sectionsAr)));

        $meta = $this->writeMeta($ai, $topic['angle'], $bodyEn);

        foreach (['title_en', 'title_ar', 'excerpt_en', 'excerpt_ar'] as $field) {
            if (empty($meta[$field])) {
                throw new \RuntimeException("AI response missing '{$field}'");
            }
        }
        if (str_word_count($bodyEn) < 1200 || count(preg_split('/\s+/u', trim($bodyAr))) < 1200) {
            throw new \RuntimeException('Generated body came in too short even after section-by-section chaining (' . str_word_count($bodyEn) . ' EN words) — skipping rather than publishing thin content.');
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

    /** @return array<int, string> 8-10 short section briefs, one line each. */
    private function writeOutline(GeminiService $ai, string $angle): array
    {
        $prompt = <<<SYS
You are planning an in-depth blog post for EYE Analytics, a privacy-first,
cookieless website analytics SaaS. Given a topic, output an outline of 8-10
sections that together would make a genuinely thorough ~1800-2200 word
article — not a shallow overview. Good coverage usually includes: the core
concept and why it matters now, the real cost of getting it wrong (concrete
scenarios), a step-by-step walkthrough, 3-4 distinct common mistakes as
SEPARATE sections (not lumped into one), how to measure/verify the result,
and a wrap-up — but adapt this to whatever actually fits the topic.

Return ONLY a numbered list, one section per line, each line a one-sentence
brief of what that section covers (not just a title). No other text.

Topic: {$angle}
SYS;

        $text = $this->generateOrFail($ai, $prompt, 512);
        $lines = preg_split('/\r?\n/', trim($text));
        $sections = [];
        foreach ($lines as $line) {
            $clean = trim(preg_replace('/^\s*\d+[.\)]\s*/', '', $line));
            if ($clean !== '') {
                $sections[] = $clean;
            }
        }
        return $sections;
    }

    /** @param array<int, string> $outline */
    private function writeSection(GeminiService $ai, string $angle, array $outline, int $index, string $brief): string
    {
        $outlineText = implode("\n", array_map(fn ($s, $i) => ($i + 1) . '. ' . $s, $outline, array_keys($outline)));
        $sectionNum = $index + 1;
        $prompt = <<<SYS
You are writing ONE section of a longer EYE Analytics blog post (the other
sections are written separately — do not introduce the article, do not
summarize/conclude the whole piece, and do not repeat what other sections
already cover per the outline below). Write 150-220 words for JUST this
section, plain text, no markdown, no headers, 1-3 short paragraphs (2-4
sentences each). Educational and practical, concrete advice a site owner can
act on. Do NOT invent statistics, customer names, or quotes — general
industry knowledge is fine but never fabricate a specific number as EYE's own
data. You may mention EYE Analytics naturally if directly relevant to this
specific section, but don't force it into every section.

Return ONLY this section's body text — no heading, no section number, no
preamble.

Article topic: {$angle}

Full outline (for context — write ONLY section {$sectionNum}):
{$outlineText}

Write section {$sectionNum}: {$brief}
SYS;

        return $this->generateOrFail($ai, $prompt, 512);
    }

    /** Translate a single already-written section — not the whole body in one call, see class doc comment for why. */
    private function translateSection(GeminiService $ai, string $sectionEn, string $language): string
    {
        $prompt = <<<SYS
Translate/adapt this one section of an EYE Analytics blog post into
{$language}. Real adaptation for {$language}-speaking readers, not a
transliteration and not a shortened summary — genuine, fluent, complete,
same depth and roughly the same length as the source. Plain text, no
markdown, no headers.

Return ONLY the translated text — no preamble, no markers, nothing else.

Section text:
{$sectionEn}
SYS;

        return $this->generateOrFail($ai, $prompt, 512);
    }

    /** Title/excerpt/keywords generated FROM the finished English body, so they actually describe the real content. */
    private function writeMeta(GeminiService $ai, string $angle, string $bodyEn): array
    {
        $prompt = <<<'SYS'
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

        $prompt .= "\n\nTopic: {$angle}\n\nArticle body:\n{$bodyEn}";
        $text = $this->generateOrFail($ai, $prompt, 512);
        return $this->parseDelimited($text);
    }

    /**
     * Calls GeminiService::generate() and throws instead of silently
     * returning empty on failure. A full post is ~22 Gemini calls (outline +
     * per-section write + per-section translate + meta) — comfortably over
     * the free tier's per-minute rate limit if fired back to back (confirmed
     * live: 429 on the very next post after a burst of manual test calls).
     * Paces every call and retries once, longer, specifically on 429.
     */
    private function generateOrFail(GeminiService $ai, string $prompt, int $maxTokens): string
    {
        usleep(4_500_000); // ~13 req/min, under the free-tier ceiling

        $text = $ai->generate($prompt, $maxTokens);
        if ($text === null && $ai->lastStatus === 429) {
            sleep(20);
            $text = $ai->generate($prompt, $maxTokens);
        }
        if ($text === null || trim($text) === '') {
            throw new \RuntimeException('Gemini returned no content (status: ' . ($ai->lastStatus ?? 'n/a') . ')');
        }
        return trim($text);
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
