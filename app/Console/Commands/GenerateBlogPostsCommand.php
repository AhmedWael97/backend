<?php

namespace App\Console\Commands;

use App\Models\BlogPost;
use App\Services\AnthropicService;
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
        ['key' => 'ab-testing', 'angle' => 'running a first A/B test on a landing page without writing code', 'link' => '/pricing', 'label_en' => 'See EYE plans & pricing', 'label_ar' => 'شاهد خطط EYE وأسعارها'],
    ];

    public function handle(AnthropicService $ai): int
    {
        $count = max(1, (int) $this->option('count'));

        // Skip topics already published in the last 30 days so the rotation
        // doesn't repeat the same angle back-to-back.
        $recentSlugPrefixes = BlogPost::where('created_at', '>=', now()->subDays(30))
            ->pluck('slug')
            ->map(fn ($s) => explode('-', $s)[0] ?? $s)
            ->all();

        $pool = array_values(array_filter(self::TOPICS, fn ($t) => !in_array($t['key'], $recentSlugPrefixes, true)));
        if (count($pool) < $count) {
            $pool = self::TOPICS; // exhausted the rotation — allow repeats rather than publishing nothing
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

    private function generateOne(AnthropicService $ai, array $topic): void
    {
        $system = <<<'SYS'
You are a content writer for EYE Analytics, a privacy-first, cookieless website
analytics SaaS (heatmaps, session replay, funnels, AI daily reports).

Write ONE original, useful, well-structured blog post in both English and
Arabic on the given topic. Rules:
- 500-800 words per language. Plain text only — no markdown, no HTML tags.
- Structure as short paragraphs (2-4 sentences), separated by blank lines.
  You may use a line like "Why this matters:" or a short question as a
  natural paragraph lead-in, but do NOT use markdown headers (no #, no **).
- Educational and practical — explain the concept, give concrete advice a
  site owner can act on. Do NOT invent statistics, customer names, case
  studies, or quotes that aren't true. General industry knowledge is fine
  (e.g. "most bounce happens on mobile") but never fabricate a specific
  number as if it were EYE's own data.
- You may mention EYE Analytics naturally once or twice as an example of a
  tool that does this, but this is not an ad — the majority of the post
  must be genuinely useful independent of EYE.
- Arabic must be a real translation/adaptation, not transliteration.
- Return ONLY a JSON object, no other text, shaped exactly as:
{"title_en": "...", "title_ar": "...", "excerpt_en": "one sentence, max 160 chars", "excerpt_ar": "...", "body_en": "...", "body_ar": "..."}
SYS;

        $user = "Topic: {$topic['angle']}";

        $data = $ai->complete($system, $user, 4096);

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
