<?php

namespace App\Services\Runtimes\Sources;

use App\Models\RuntimeSource;
use App\Support\Secrets\SecretRedactor;

/**
 * The credentials of a private source (docs/runtimes.md#private-sources): what the API accepts,
 * how they are stored (RuntimeSource::credentials, encrypted at rest) and how they are kept out of
 * every error, log line and problem RunR writes (mask()).
 *
 * API fields (write-only):
 *   git_token, git_username            HTTPS token of a Git source (username default x-access-token)
 *   ssh_private_key, ssh_known_hosts   SSH deploy key of a Git source (known_hosts optional: without
 *                                      it the host key is accepted on first use)
 *   registry_username, registry_password  a private catalog / patch feed and the runtime images
 *                                      (registry_host: the registry they are for)
 *
 * An omitted field keeps the stored value, null or "" removes it.
 */
final class SourceCredentials
{
    /** API field => [group, key] */
    public const FIELDS = [
        'git_token' => ['git', 'token'],
        'git_username' => ['git', 'username'],
        'ssh_private_key' => ['git', 'ssh_key'],
        'ssh_known_hosts' => ['git', 'known_hosts'],
        'registry_username' => ['registry', 'username'],
        'registry_password' => ['registry', 'password'],
    ];

    /**
     * The credentials after applying the request's fields to $current.
     *
     * @param  array<string, mixed>  $input
     */
    public static function merge(?array $current, array $input): ?array
    {
        $credentials = (array) ($current ?? []);

        foreach (self::FIELDS as $field => [$group, $key]) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if ($value === null || $value === '') {
                unset($credentials[$group][$key]);
            } else {
                $credentials[$group][$key] = str_replace("\r\n", "\n", (string) $value);
            }
        }

        // A Git source authenticates one way: a token replaces a deploy key and the other way round
        if (array_key_exists('git_token', $input) && ($input['git_token'] ?? '') !== '') {
            unset($credentials['git']['ssh_key'], $credentials['git']['known_hosts']);
        }
        if (array_key_exists('ssh_private_key', $input) && ($input['ssh_private_key'] ?? '') !== '') {
            unset($credentials['git']['token'], $credentials['git']['username']);
        }

        $credentials = array_filter($credentials, fn ($group) => is_array($group) && $group !== []);

        return $credentials === [] ? null : $credentials;
    }

    /**
     * $text with every secret of the source (and its Basic-auth / URL-encoded forms) replaced by
     * the mask.
     *
     * @param  list<string>  $secrets
     */
    public static function mask(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (strlen($secret) < 4) {
                continue;
            }
            $forms = [$secret, rawurlencode($secret), urlencode($secret), base64_encode($secret)];
            foreach (preg_split('/\R/', $secret) ?: [] as $line) {
                if (strlen(trim($line)) >= 16) {
                    $forms[] = trim($line);     // a line of a private key
                }
            }
            $text = str_replace(array_values(array_unique($forms)), SecretRedactor::MASK, $text);
        }
        // user:secret@ in a URL, Basic / Bearer headers
        $text = (string) preg_replace('~(https?://)[^/\s:@]+:[^/\s@]+@~i', '$1'.SecretRedactor::MASK.'@', $text);

        return (string) preg_replace('/\b(Basic|Bearer)\s+[A-Za-z0-9+\/=._-]{8,}/', '$1 '.SecretRedactor::MASK, $text);
    }

    public static function maskFor(string $text, RuntimeSource $source): string
    {
        $secrets = $source->secretValues();
        $user = $source->credential('registry', 'username');
        $password = $source->credential('registry', 'password');
        if ($user !== null && $password !== null) {
            $secrets[] = $user.':'.$password;
        }
        $gitUser = $source->credential('git', 'username') ?? 'x-access-token';
        if (($token = $source->credential('git', 'token')) !== null) {
            $secrets[] = $gitUser.':'.$token;
        }

        // longest first: "user:token" before "token"
        usort($secrets, fn ($a, $b) => strlen($b) <=> strlen($a));

        return self::mask($text, $secrets);
    }
}
