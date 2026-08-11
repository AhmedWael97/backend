<?php

namespace App\Services;

/**
 * Single source of truth for "is this a real, addable domain" — used by
 * every place a domain gets created (StoreDomainRequest, the onboarding
 * quiz) so the check can't be silently bypassed by adding a new entry
 * point later.
 */
class DomainGuard
{
    /**
     * A hostname: one or more dot-separated labels ending in an alphabetic TLD.
     * Labels are 1–63 chars, alphanumeric, may contain inner hyphens.
     * Matches example.com / www.example.com / sub.example.co.uk — not IPs,
     * not localhost, not a bare word.
     */
    private const HOSTNAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /** People paste "https://example.com/pricing?x=1" — reduce to the bare hostname. */
    public static function normalize(string $raw): string
    {
        $domain = strtolower(trim($raw));
        $domain = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain); // scheme
        $domain = preg_replace('#^[^/@]*@#', '', $domain);              // userinfo
        $domain = explode('/', $domain, 2)[0];                          // path
        $domain = explode('?', $domain, 2)[0];                          // query
        $domain = explode('#', $domain, 2)[0];                          // fragment
        $domain = explode(':', $domain, 2)[0];                          // port
        return rtrim($domain, '.');                                     // trailing dot
    }

    public static function isValidFormat(string $domain): bool
    {
        return (bool) preg_match(self::HOSTNAME, $domain);
    }

    /**
     * Real signal that this is a live, existing website — not a typo or
     * made-up string. Doesn't prove the user OWNS it (install-verification
     * already does that, later); this just stops obviously-fake entries at
     * the door.
     */
    public static function isResolvable(string $domain): bool
    {
        return checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA') || checkdnsrr($domain, 'CNAME');
    }

    /** Format + DNS in one call, for call sites that don't go through StoreDomainRequest. */
    public static function isAddable(string $domain): bool
    {
        return self::isValidFormat($domain) && self::isResolvable($domain);
    }
}
