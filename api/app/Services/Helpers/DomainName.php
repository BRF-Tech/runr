<?php

namespace App\Services\Helpers;

/**
 * Host-name helpers for project custom domains.
 *
 * Accepted shape: a lowercase ASCII fully-qualified domain name — at least two labels,
 * each 1–63 characters of [a-z0-9-] not starting or ending with "-", 253 characters at
 * most, an alphabetic (or IDNA "xn--") top-level label. No wildcard, no IP address, no
 * trailing dot (normalize() strips one). Internationalized names are entered in their
 * punycode (xn--) form.
 */
class DomainName
{
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * Trimmed, lowercased, without a trailing dot. Does not validate.
     */
    public static function normalize(string $value): string
    {
        return rtrim(strtolower(trim($value)), '.');
    }

    public static function isValid(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }

        if (filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (! preg_match('/^'.self::LABEL.'$/', $label)) {
                return false;
            }
        }

        $tld = end($labels);

        return (bool) preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld);
    }

    /**
     * $domain equals $parent or is a sub-domain of it.
     */
    public static function isWithin(string $domain, string $parent): bool
    {
        $domain = self::normalize($domain);
        $parent = self::normalize($parent);

        if ($parent === '') {
            return false;
        }

        return $domain === $parent || str_ends_with($domain, '.'.$parent);
    }

    /**
     * Host of a URL (e.g. APP_URL), only when it is a real DNS name (not localhost / an IP).
     */
    public static function hostOf(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $host = parse_url(str_contains($url, '://') ? $url : "http://{$url}", PHP_URL_HOST);

        return is_string($host) ? self::publicHost($host) : null;
    }

    /**
     * $host normalized when it has at least one dot and is not an IP address, else null.
     */
    public static function publicHost(?string $host): ?string
    {
        if (! $host) {
            return null;
        }

        $host = self::normalize(trim($host, '[]'));

        if (! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $host;
    }
}
