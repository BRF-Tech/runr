<?php

namespace App\Support\Secrets;

/**
 * Keeps secret values out of everything that leaves the encrypted columns: broadcasts (public
 * Reverb channels), audit metadata, the version snapshot RunR commits to the project's git
 * repository (docs/security.md, "Where secrets never appear").
 */
final class SecretRedactor
{
    public const MASK = '••••••';

    /** Payload keys whose value is an env var map (NAME => value). */
    private const ENV_KEYS = ['env_vars', 'secret_env_vars'];

    /**
     * Key names that hold a secret. Anchored at the end of the key, so tls_secret_name,
     * secret_env_keys or token_ids stay readable.
     */
    private const SECRET_KEY_PATTERN = '/(password|passwd|secret|token|kubeconfig|private_?key|access_?key|secret_?key|api_?key|credentials?|authorization|cookie|recovery_codes|two_factor_code|link_token|value_b64)$/i';

    public static function isSecretKey(string $key): bool
    {
        return (bool) preg_match(self::SECRET_KEY_PATTERN, $key);
    }

    /**
     * Env var maps keep their names only (a list) — the version snapshot RunR commits to git,
     * which is also mounted into the workload as the code ConfigMap.
     */
    public static function envNamesOnly(array $snapshot): array
    {
        foreach (self::ENV_KEYS as $key) {
            if (isset($snapshot[$key]) && is_array($snapshot[$key])) {
                $snapshot[$key] = array_map('strval', array_keys($snapshot[$key]));
            }
        }

        return $snapshot;
    }

    /**
     * Recursively: env var maps keep their names with masked values (and a sibling
     * "<key>_redacted": true), values under secret-looking keys are masked. Booleans, nulls and
     * empty strings stay as they are (has_client_secret, an unset password).
     */
    public static function redact(mixed $data, int $depth = 0): mixed
    {
        if (! is_array($data) || $depth > 10) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                $data[$key] = self::redact($value, $depth + 1);

                continue;
            }

            // An audit change entry lists env var names only ({added|removed|changed: [names]},
            // AuditChanges::envDiff): nothing to mask, and masking would lose the names
            if (in_array($key, self::ENV_KEYS, true) && is_array($value) && self::isEnvNameDiff($value)) {
                continue;
            }

            if (in_array($key, self::ENV_KEYS, true) && is_array($value)) {
                $data[$key] = array_fill_keys(array_map('strval', array_keys($value)), self::MASK);
                $data[$key.'_redacted'] = true;

                continue;
            }

            if (self::isSecretKey($key)) {
                $data[$key] = ($value === null || $value === '' || is_bool($value)) ? $value : self::MASK;

                continue;
            }

            $data[$key] = self::redact($value, $depth + 1);
        }

        return $data;
    }

    /**
     * {added?, removed?, changed?} of lists of names. An env var map holds strings (values), never
     * lists, so a map of name lists is never a set of values.
     */
    private static function isEnvNameDiff(array $value): bool
    {
        if ($value === [] || array_diff(array_keys($value), ['added', 'removed', 'changed']) !== []) {
            return false;
        }

        foreach ($value as $names) {
            if (! is_array($names) || ! array_is_list($names)) {
                return false;
            }
            foreach ($names as $name) {
                if (! is_string($name)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Last 4 characters behind a mask ("••••••1a2b"), for showing which credential is stored. */
    public static function hint(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::MASK.(mb_strlen($value) > 8 ? mb_substr($value, -4) : '');
    }
}
