<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Reads a company's own PUBLISHED contact address off its own site.
 *
 * Fetches the homepage plus a short list of conventional contact paths and
 * pulls out mailto: links and plain-text addresses. It does not crawl — at
 * most five known URLs per company, one polite request each — so it behaves
 * like a person opening the contact page, not a spider.
 *
 * Role addresses on the company's own domain (info@, hello@, contact@) are
 * preferred: they are the ones a business publishes to be written to, and they
 * are the lower-risk side of every anti-spam regime. Personal-looking
 * addresses at other domains are ignored.
 */
class ContactFinderService
{
    private const UA = 'EYE-Analytics-LeadResearch/1.0 (+https://eye-analysis.online)';
    private const PATHS = ['', '/contact', '/contact-us', '/about', '/about-us'];
    private const TIMEOUT = 12;

    /** Role prefixes ranked best-first — a business address beats a person's. */
    private const PREFERRED = ['info', 'hello', 'contact', 'hi', 'sales', 'team', 'office', 'enquiries', 'inquiries'];

    /** Addresses that are never a real inbox. */
    private const JUNK = [
        'example.com', 'yourdomain', 'domain.com', 'email.com', 'sentry.io', 'wixpress.com',
        'sentry-next', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', 'godaddy', 'squarespace',
        'wordpress.org', 'jquery', 'schema.org', 'w3.org', 'core.noreply',
    ];

    /** @return array{email: ?string, source_url: ?string} */
    public function find(string $website): array
    {
        $base = $this->normalizeBase($website);
        if ($base === null) {
            return ['email' => null, 'source_url' => null];
        }

        $host = (string) parse_url($base, PHP_URL_HOST);
        $registrable = $this->registrableDomain($host);
        $candidates = [];

        foreach (self::PATHS as $path) {
            $url = $base . $path;
            $html = $this->fetch($url);
            if ($html === null) {
                continue;
            }

            foreach ($this->extractEmails($html) as $email) {
                if (!isset($candidates[$email])) {
                    $candidates[$email] = $url;
                }
            }

            // A same-domain role address is as good as this gets; stop paying
            // for requests once one is in hand.
            if ($this->pickBest(array_keys($candidates), $registrable, true) !== null) {
                break;
            }
        }

        $best = $this->pickBest(array_keys($candidates), $registrable, false);

        return ['email' => $best, 'source_url' => $best !== null ? ($candidates[$best] ?? null) : null];
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::UA, 'Accept' => 'text/html'])
                ->timeout(self::TIMEOUT)
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($url);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        // Contact details live near the top and in the footer; capping the read
        // keeps one bloated page from eating memory.
        return substr((string) $response->body(), 0, 400_000);
    }

    /** @return array<int, string> */
    private function extractEmails(string $html): array
    {
        $found = [];

        // mailto: links first — an explicitly published address.
        if (preg_match_all('/mailto:([^"\'\s>?]+)/i', $html, $m)) {
            $found = array_merge($found, $m[1]);
        }
        if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $html, $m)) {
            $found = array_merge($found, $m[0]);
        }

        $clean = [];
        foreach ($found as $email) {
            $email = strtolower(trim(rawurldecode($email)));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                continue;
            }
            foreach (self::JUNK as $junk) {
                if (str_contains($email, $junk)) {
                    continue 2;
                }
            }
            $clean[$email] = true;
        }

        return array_keys($clean);
    }

    /**
     * @param  array<int, string>  $emails
     * @param  bool  $rolesOnly  stop early only for a same-domain role address
     */
    private function pickBest(array $emails, ?string $registrable, bool $rolesOnly): ?string
    {
        $sameDomain = array_values(array_filter(
            $emails,
            fn (string $e) => $registrable !== null && str_ends_with(explode('@', $e)[1] ?? '', $registrable)
        ));

        foreach (self::PREFERRED as $prefix) {
            foreach ($sameDomain as $email) {
                if (str_starts_with($email, $prefix . '@')) {
                    return $email;
                }
            }
        }

        if ($rolesOnly) {
            return null;
        }

        // Any address on the company's own domain beats one on a third party's.
        return $sameDomain[0] ?? null;
    }

    private function normalizeBase(string $website): ?string
    {
        $website = trim($website);
        if ($website === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }

        $host = parse_url($website, PHP_URL_HOST);
        if (!$host || !filter_var($website, FILTER_VALIDATE_URL)) {
            return null;
        }

        return rtrim((string) parse_url($website, PHP_URL_SCHEME), '/') . '://' . $host;
    }

    /** "www.acme.co.uk" → "acme.co.uk" (good enough to match an address's domain). */
    private function registrableDomain(string $host): ?string
    {
        $host = strtolower(preg_replace('/^www\./i', '', $host));

        return $host !== '' ? $host : null;
    }
}
