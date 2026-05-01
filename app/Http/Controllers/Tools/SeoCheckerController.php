<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * POST /api/v1/tools/seo-check
 *
 * Fetches the given URL server-side and runs a suite of SEO checks.
 * Returns categorised issues and an overall score (0-100).
 */
class SeoCheckerController extends Controller
{
    private const TIMEOUT = 15;
    private const MAX_BYTES = 2 * 1024 * 1024; // 2 MB cap
    private const UA = 'Mozilla/5.0 (compatible; EyeSEOBot/1.0)';

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $url = $request->input('url');

        // Security: only allow http/https
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->error('Only http/https URLs are allowed.', 422);
        }

        // Fetch the page
        try {
            $response = Http::withHeaders(['User-Agent' => self::UA])
                ->timeout(self::TIMEOUT)
                ->get($url);
        } catch (\Throwable $e) {
            return $this->error('Could not reach the URL: ' . $e->getMessage(), 422);
        }

        if (!$response->successful()) {
            return $this->error("URL returned HTTP {$response->status()}.", 422);
        }

        $html = substr($response->body(), 0, self::MAX_BYTES);
        $statusCode = $response->status();
        $headers = $response->headers();

        $checks = $this->runChecks($url, $html, $statusCode, $headers);

        $passed = count(array_filter($checks, fn($c) => $c['status'] === 'pass'));
        $total = count($checks);
        $score = $total > 0 ? (int) round(($passed / $total) * 100) : 0;

        $issues = array_filter($checks, fn($c) => $c['status'] !== 'pass');
        $passing = array_filter($checks, fn($c) => $c['status'] === 'pass');

        return $this->success([
            'url' => $url,
            'score' => $score,
            'passed' => $passed,
            'total' => $total,
            'issues' => array_values($issues),
            'passing' => array_values($passing),
        ]);
    }

    // ── Checks ────────────────────────────────────────────────────────────────

    private function runChecks(string $url, string $html, int $status, array $headers): array
    {
        $dom = $this->loadDom($html);
        $xpath = new \DOMXPath($dom);

        return [
            // ── Title ────────────────────────────────────────────────────────
            ...$this->checkTitle($xpath),

            // ── Meta Description ─────────────────────────────────────────────
            ...$this->checkMetaDescription($xpath),

            // ── Headings ─────────────────────────────────────────────────────
            ...$this->checkHeadings($xpath),

            // ── Images ───────────────────────────────────────────────────────
            ...$this->checkImages($xpath),

            // ── Canonical ────────────────────────────────────────────────────
            ...$this->checkCanonical($xpath, $url),

            // ── Open Graph ───────────────────────────────────────────────────
            ...$this->checkOpenGraph($xpath),

            // ── Twitter Card ─────────────────────────────────────────────────
            ...$this->checkTwitterCard($xpath),

            // ── Schema.org ───────────────────────────────────────────────────
            ...$this->checkSchema($html),

            // ── Robots meta ──────────────────────────────────────────────────
            ...$this->checkRobotsMeta($xpath),

            // ── Viewport ─────────────────────────────────────────────────────
            ...$this->checkViewport($xpath),

            // ── Lang attribute ───────────────────────────────────────────────
            ...$this->checkLang($dom),

            // ── HTTPS ────────────────────────────────────────────────────────
            ...$this->checkHttps($url),

            // ── Content length ───────────────────────────────────────────────
            ...$this->checkContentLength($html),

            // ── Links ────────────────────────────────────────────────────────
            ...$this->checkLinks($xpath),

            // ── Response ─────────────────────────────────────────────────────
            ...$this->checkResponseCode($status),
        ];
    }

    // ── Individual check methods ──────────────────────────────────────────────

    private function checkTitle(\DOMXPath $x): array
    {
        $nodes = $x->query('//title');
        if ($nodes === false || $nodes->length === 0) {
            return [$this->fail('title', 'Title', 'Missing <title> tag.', 'Add a descriptive page title (50–60 characters).', 'critical')];
        }
        $title = trim($nodes->item(0)->textContent ?? '');
        $len = mb_strlen($title);
        if ($len === 0) {
            return [$this->fail('title', 'Title', 'Title tag is empty.', 'Add a descriptive page title (50–60 characters).', 'critical')];
        }
        $checks = [];
        if ($len < 30) {
            $checks[] = $this->warn('title_length', 'Title Length', "Title is too short ({$len} chars).", 'Aim for 50–60 characters for best SERP display.', 'warning');
        } elseif ($len > 60) {
            $checks[] = $this->warn('title_length', 'Title Length', "Title is too long ({$len} chars) and may be truncated.", 'Keep your title under 60 characters.', 'warning');
        } else {
            $checks[] = $this->pass('title_length', 'Title Length', "Title is {$len} chars — ideal length.");
        }
        $checks[] = $this->pass('title', 'Title Tag', "Title present: \"{$title}\"");
        return $checks;
    }

    private function checkMetaDescription(\DOMXPath $x): array
    {
        $nodes = $x->query('//meta[@name="description"]/@content');
        if ($nodes === false || $nodes->length === 0) {
            return [$this->fail('meta_desc', 'Meta Description', 'Missing meta description.', 'Add a unique meta description (120–158 characters) summarising the page.', 'high')];
        }
        $desc = trim($nodes->item(0)->nodeValue ?? '');
        $len = mb_strlen($desc);
        if ($len === 0) {
            return [$this->fail('meta_desc', 'Meta Description', 'Meta description is empty.', 'Add a descriptive meta description.', 'high')];
        }
        if ($len < 70) {
            return [$this->warn('meta_desc', 'Meta Description', "Meta description is short ({$len} chars).", 'Aim for 120–158 characters.', 'warning')];
        }
        if ($len > 160) {
            return [$this->warn('meta_desc', 'Meta Description', "Meta description is too long ({$len} chars) and may be truncated.", 'Keep it under 160 characters.', 'warning')];
        }
        return [$this->pass('meta_desc', 'Meta Description', "Meta description present ({$len} chars).")];
    }

    private function checkHeadings(\DOMXPath $x): array
    {
        $h1s = $x->query('//h1');
        $checks = [];
        if ($h1s === false || $h1s->length === 0) {
            $checks[] = $this->fail('h1', 'H1 Heading', 'No H1 heading found.', 'Add a single H1 heading that describes the main topic.', 'high');
        } elseif ($h1s->length > 1) {
            $checks[] = $this->warn('h1', 'H1 Heading', "Multiple H1 headings found ({$h1s->length}).", 'Use only one H1 per page for clear topic hierarchy.', 'warning');
        } else {
            $h1Text = trim($h1s->item(0)->textContent ?? '');
            $checks[] = $this->pass('h1', 'H1 Heading', "H1 present: \"{$h1Text}\"");
        }
        $h2s = $x->query('//h2');
        if ($h2s !== false && $h2s->length === 0) {
            $checks[] = $this->warn('h2', 'H2 Headings', 'No H2 headings found.', 'Use H2 headings to structure content into sections.', 'info');
        } else {
            $checks[] = $this->pass('h2', 'H2 Headings', "H2 headings present ({$h2s->length}).");
        }
        return $checks;
    }

    private function checkImages(\DOMXPath $x): array
    {
        $imgs = $x->query('//img');
        if ($imgs === false || $imgs->length === 0) {
            return [$this->pass('img_alt', 'Image Alt Tags', 'No images found on page.')];
        }
        $missing = 0;
        foreach ($imgs as $img) {
            $alt = $img->getAttribute('alt');
            if ($alt === '' || $alt === null) {
                $missing++;
            }
        }
        if ($missing === 0) {
            return [$this->pass('img_alt', 'Image Alt Tags', "All {$imgs->length} images have alt attributes.")];
        }
        return [$this->warn('img_alt', 'Image Alt Tags', "{$missing} of {$imgs->length} images missing alt attributes.", 'Add descriptive alt text to all images for accessibility and SEO.', 'warning')];
    }

    private function checkCanonical(\DOMXPath $x, string $url): array
    {
        $nodes = $x->query('//link[@rel="canonical"]/@href');
        if ($nodes === false || $nodes->length === 0) {
            return [$this->fail('canonical', 'Canonical URL', 'No canonical link tag found.', 'Add <link rel="canonical" href="..."> to prevent duplicate content issues.', 'high')];
        }
        $canonical = trim($nodes->item(0)->nodeValue ?? '');
        return [$this->pass('canonical', 'Canonical URL', "Canonical set to: {$canonical}")];
    }

    private function checkOpenGraph(\DOMXPath $x): array
    {
        $required = ['og:title', 'og:description', 'og:image', 'og:url'];
        $missing = [];
        foreach ($required as $prop) {
            $n = $x->query("//meta[@property=\"{$prop}\"]");
            if ($n === false || $n->length === 0) {
                $missing[] = $prop;
            }
        }
        if (count($missing) === 0) {
            return [$this->pass('og', 'Open Graph Tags', 'All required Open Graph tags present.')];
        }
        return [$this->warn('og', 'Open Graph Tags', 'Missing OG tags: ' . implode(', ', $missing) . '.', 'Add Open Graph tags to improve social media sharing previews.', 'warning')];
    }

    private function checkTwitterCard(\DOMXPath $x): array
    {
        $n = $x->query('//meta[@name="twitter:card"]');
        if ($n === false || $n->length === 0) {
            return [$this->warn('twitter_card', 'Twitter Card', 'No twitter:card meta tag found.', 'Add <meta name="twitter:card" content="summary_large_image"> for Twitter sharing previews.', 'info')];
        }
        return [$this->pass('twitter_card', 'Twitter Card', 'Twitter card meta tag present.')];
    }

    private function checkSchema(string $html): array
    {
        if (str_contains($html, 'application/ld+json') || str_contains($html, 'itemtype') || str_contains($html, 'schema.org')) {
            return [$this->pass('schema', 'Structured Data', 'Structured data / Schema.org markup detected.')];
        }
        return [$this->warn('schema', 'Structured Data', 'No structured data (JSON-LD / Microdata) detected.', 'Add Schema.org markup to help search engines understand your content.', 'warning')];
    }

    private function checkRobotsMeta(\DOMXPath $x): array
    {
        $n = $x->query('//meta[@name="robots"]/@content');
        if ($n === false || $n->length === 0) {
            return [$this->pass('robots_meta', 'Robots Meta', 'No robots meta tag — search engines will index by default.')];
        }
        $content = strtolower($n->item(0)->nodeValue ?? '');
        if (str_contains($content, 'noindex')) {
            return [$this->fail('robots_meta', 'Robots Meta', 'Page is marked noindex — search engines will not index it.', 'Remove noindex if you want this page to appear in search results.', 'critical')];
        }
        return [$this->pass('robots_meta', 'Robots Meta', "Robots meta: \"{$content}\"")];
    }

    private function checkViewport(\DOMXPath $x): array
    {
        $n = $x->query('//meta[@name="viewport"]');
        if ($n === false || $n->length === 0) {
            return [$this->fail('viewport', 'Viewport Meta', 'No viewport meta tag found.', 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> for mobile-friendliness.', 'high')];
        }
        return [$this->pass('viewport', 'Viewport Meta', 'Viewport meta tag present — mobile-friendly.')];
    }

    private function checkLang(\DOMDocument $dom): array
    {
        $html = $dom->getElementsByTagName('html')->item(0);
        if (!$html) {
            return [$this->warn('lang', 'Language Attribute', 'No <html> element found.', 'Ensure your page has a valid HTML root element.', 'info')];
        }
        $lang = $html->getAttribute('lang');
        if (!$lang) {
            return [$this->warn('lang', 'Language Attribute', 'No lang attribute on <html>.', 'Add lang="en" (or your language code) to the <html> tag.', 'warning')];
        }
        return [$this->pass('lang', 'Language Attribute', "Language set to \"{$lang}\".")];
    }

    private function checkHttps(string $url): array
    {
        if (str_starts_with($url, 'https://')) {
            return [$this->pass('https', 'HTTPS', 'Page is served over HTTPS.')];
        }
        return [$this->fail('https', 'HTTPS', 'Page is not served over HTTPS.', 'Migrate to HTTPS — it is a Google ranking signal and required for trust.', 'critical')];
    }

    private function checkContentLength(string $html): array
    {
        // Rough word count from visible text
        $stripped = strip_tags($html);
        $words = str_word_count($stripped);
        if ($words < 100) {
            return [$this->warn('content', 'Content Length', "Page has very little text (~{$words} words).", 'Add more meaningful content — at least 300 words for most pages.', 'warning')];
        }
        if ($words < 300) {
            return [$this->warn('content', 'Content Length', "Page text is thin (~{$words} words).", 'Aim for 300+ words for informational pages.', 'info')];
        }
        return [$this->pass('content', 'Content Length', "Content length is good (~{$words} words).")];
    }

    private function checkLinks(\DOMXPath $x): array
    {
        $links = $x->query('//a[@href]');
        if ($links === false || $links->length === 0) {
            return [$this->warn('links', 'Internal Links', 'No links found on the page.', 'Add internal links to help search engines crawl your site.', 'info')];
        }
        $internal = 0;
        $external = 0;
        foreach ($links as $link) {
            $href = $link->getAttribute('href') ?? '';
            if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                $external++;
            } elseif ($href !== '' && !str_starts_with($href, '#') && !str_starts_with($href, 'mailto:')) {
                $internal++;
            }
        }
        return [$this->pass('links', 'Links', "{$internal} internal and {$external} external links found.")];
    }

    private function checkResponseCode(int $status): array
    {
        if ($status === 200) {
            return [$this->pass('status', 'HTTP Status', "Page returns HTTP 200 OK.")];
        }
        if ($status >= 300 && $status < 400) {
            return [$this->warn('status', 'HTTP Status', "Page returns HTTP {$status} (redirect).", 'Redirects add latency. Update links to point directly to the final URL.', 'info')];
        }
        return [$this->fail('status', 'HTTP Status', "Page returns HTTP {$status}.", 'Fix the server error or redirect so the page is accessible.', 'critical')];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function loadDom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        return $dom;
    }

    private function pass(string $id, string $label, string $message): array
    {
        return ['id' => $id, 'label' => $label, 'message' => $message, 'status' => 'pass', 'severity' => 'pass', 'suggestion' => null];
    }

    private function fail(string $id, string $label, string $message, string $suggestion, string $severity = 'high'): array
    {
        return ['id' => $id, 'label' => $label, 'message' => $message, 'status' => 'fail', 'severity' => $severity, 'suggestion' => $suggestion];
    }

    private function warn(string $id, string $label, string $message, string $suggestion, string $severity = 'warning'): array
    {
        return ['id' => $id, 'label' => $label, 'message' => $message, 'status' => 'warn', 'severity' => $severity, 'suggestion' => $suggestion];
    }
}
