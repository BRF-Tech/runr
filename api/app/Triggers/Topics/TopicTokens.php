<?php

namespace App\Triggers\Topics;

use App\Models\KnativeRegion;

/**
 * Publish tokens of a region's topic publisher (runtime/queue-consumer/publisher):
 *
 *   v1.<base64url(claims JSON)>.<base64url(HMAC-SHA256(key, "v1." + <base64url claims>))>
 *
 * claims: sfx (the organization's stream suffix — it scopes the token to that organization's
 * topics), prj (the publishing project id, "api" for the RunR API), top (topic names, ["*"] for
 * all), exp (unix seconds, 0 = none), adm (may delete topic streams). The key is derived from the
 * application key and the region id: the publisher gets it in its Secret (TOPICS_SIGNING_KEY,
 * used as raw bytes), nothing stores it.
 */
final class TopicTokens
{
    public static function key(KnativeRegion $region): string
    {
        return hash_hmac('sha256', 'topics:'.$region->getKey(), (string) config('app.key'));
    }

    /** A function's token: every topic of its organization, no expiry (rotates with the app key). */
    public static function forProject(KnativeRegion $region, string $projectId): string
    {
        return self::mint($region, ['sfx' => TopicStreams::suffix(), 'prj' => $projectId, 'top' => ['*'], 'exp' => 0]);
    }

    /** A short-lived token of the RunR API for one topic. */
    public static function forApi(KnativeRegion $region, string $topic, bool $admin = false): string
    {
        return self::mint($region, [
            'sfx' => TopicStreams::suffix(),
            'prj' => 'api',
            'top' => [$topic],
            'exp' => time() + 120,
            'adm' => $admin,
        ]);
    }

    /** @param  array<string, mixed>  $claims */
    public static function mint(KnativeRegion $region, array $claims): string
    {
        $payload = self::b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signature = self::b64(hash_hmac('sha256', 'v1.'.$payload, self::key($region), true));

        return 'v1.'.$payload.'.'.$signature;
    }

    /**
     * The claims of a token the key signed (null: malformed, bad signature or expired).
     *
     * @return array<string, mixed>|null
     */
    public static function verify(KnativeRegion $region, string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            return null;
        }

        $expected = self::b64(hash_hmac('sha256', 'v1.'.$parts[1], self::key($region), true));
        if (! hash_equals($expected, $parts[2])) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (! is_array($claims)) {
            return null;
        }
        $exp = (int) ($claims['exp'] ?? 0);

        return $exp > 0 && $exp < time() ? null : $claims;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
