<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An IPv4 / IPv6 address or CIDR block ("192.0.2.7", "10.0.0.0/24", "2001:db8::/32") — the form
 * Traefik's trustedIPs take (region setting ingress_trusted_ips).
 */
class IpOrCidr implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::valid($value)) {
            $fail("The {$attribute} field must be an IP address or a CIDR block (e.g. 10.0.0.0/24).");
        }
    }

    public static function valid(string $value): bool
    {
        $value = trim($value);
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        $v4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $v6 = ! $v4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (! $v4 && ! $v6) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        return ctype_digit($prefix) && (int) $prefix <= ($v4 ? 32 : 128);
    }
}
