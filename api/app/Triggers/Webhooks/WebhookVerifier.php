<?php

namespace App\Triggers\Webhooks;

use App\Models\ProjectTrigger;
use Illuminate\Http\Request;

/**
 * Signature checks and delivery ids of a webhook inbox (docs/triggers.md#webhook-inbox).
 *
 * Presets (config.verify):
 *  - stripe  Stripe-Signature "t=<unix>,v1=<hex>[,v1=…]": HMAC-SHA256 of "<t>.<body>", within
 *            tolerance_seconds of now; delivery id = the event's JSON "id", subject its "type"
 *  - github  X-Hub-Signature-256 "sha256=<hex>": HMAC-SHA256 of the body; delivery id =
 *            X-GitHub-Delivery, subject = X-GitHub-Event
 *  - slack   X-Slack-Signature "v0=<hex>" over "v0:<X-Slack-Request-Timestamp>:<body>", within
 *            tolerance_seconds; delivery id = the Events API "event_id", subject the event type
 *  - hmac    a header of your choice (config.hmac.header, default X-Signature): HMAC of the body
 *            with config.hmac.algorithm (sha256 | sha1 | sha512), hex or base64
 *            (config.hmac.encoding), after an optional prefix (config.hmac.prefix, e.g. "sha256=")
 *  - none    no check (keep the URL secret)
 * config.delivery_id_header names the header that carries the sender's delivery id for any
 * preset (it wins over the preset's own source). Every comparison is constant-time.
 */
class WebhookVerifier
{
    public const PRESETS = ['none', 'stripe', 'github', 'slack', 'hmac'];

    public const ALGORITHMS = ['sha256', 'sha1', 'sha512'];

    public const DEFAULT_TOLERANCE = 300;

    /** Error key (lang triggers.webhook.*) when the request does not verify, null when it does. */
    public function verify(ProjectTrigger $trigger, Request $request, string $body): ?string
    {
        $preset = (string) $trigger->setting('verify', 'none');
        if ($preset === 'none') {
            return null;
        }

        $secret = $trigger->secret('signing_secret');
        if ($secret === null) {
            return 'secret_missing';
        }

        $tolerance = (int) $trigger->setting('tolerance_seconds', self::DEFAULT_TOLERANCE);

        return match ($preset) {
            'stripe' => $this->stripe($request, $body, $secret, $tolerance),
            'github' => $this->github($request, $body, $secret),
            'slack' => $this->slack($request, $body, $secret, $tolerance),
            'hmac' => $this->hmac($trigger, $request, $body, $secret),
            default => 'unknown_preset',
        };
    }

    /** The sender's delivery id (dedupe key), null when the request carries none. */
    public function deliveryId(ProjectTrigger $trigger, Request $request, string $body): ?string
    {
        $header = $trigger->setting('delivery_id_header');
        if (is_string($header) && $header !== '') {
            return self::clip($request->header($header));
        }

        return match ((string) $trigger->setting('verify', 'none')) {
            'stripe' => self::clip(self::json($body)['id'] ?? null),
            'github' => self::clip($request->header('X-GitHub-Delivery')),
            'slack' => self::clip(self::json($body)['event_id'] ?? null),
            default => null,
        };
    }

    /** What the event is about at the sender (GitHub event, Stripe / Slack event type), if known. */
    public function subject(ProjectTrigger $trigger, Request $request, string $body): ?string
    {
        return match ((string) $trigger->setting('verify', 'none')) {
            'stripe' => self::clip(self::json($body)['type'] ?? null),
            'github' => self::clip($request->header('X-GitHub-Event')),
            'slack' => self::clip(self::json($body)['event']['type'] ?? (self::json($body)['type'] ?? null)),
            default => null,
        };
    }

    /** Slack's URL verification handshake: the challenge to echo, null for any other request. */
    public function slackChallenge(ProjectTrigger $trigger, string $body): ?string
    {
        if ($trigger->setting('verify') !== 'slack') {
            return null;
        }
        $json = self::json($body);

        return ($json['type'] ?? null) === 'url_verification' && is_string($json['challenge'] ?? null) ? $json['challenge'] : null;
    }

    private function stripe(Request $request, string $body, string $secret, int $tolerance): ?string
    {
        $header = (string) $request->header('Stripe-Signature', '');
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return 'signature_missing';
        }
        if (! self::fresh((int) $timestamp, $tolerance)) {
            return 'timestamp_outside_tolerance';
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower($signature))) {
                return null;
            }
        }

        return 'signature_mismatch';
    }

    private function github(Request $request, string $body, string $secret): ?string
    {
        $header = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($header, 'sha256=')) {
            return 'signature_missing';
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), strtolower(substr($header, 7))) ? null : 'signature_mismatch';
    }

    private function slack(Request $request, string $body, string $secret, int $tolerance): ?string
    {
        $signature = (string) $request->header('X-Slack-Signature', '');
        $timestamp = (string) $request->header('X-Slack-Request-Timestamp', '');
        if (! str_starts_with($signature, 'v0=') || ! ctype_digit($timestamp)) {
            return 'signature_missing';
        }
        if (! self::fresh((int) $timestamp, $tolerance)) {
            return 'timestamp_outside_tolerance';
        }

        return hash_equals(hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret), strtolower(substr($signature, 3))) ? null : 'signature_mismatch';
    }

    private function hmac(ProjectTrigger $trigger, Request $request, string $body, string $secret): ?string
    {
        $headerName = (string) ($trigger->setting('hmac.header') ?: 'X-Signature');
        $algorithm = (string) ($trigger->setting('hmac.algorithm') ?: 'sha256');
        $encoding = (string) ($trigger->setting('hmac.encoding') ?: 'hex');
        $prefix = (string) ($trigger->setting('hmac.prefix') ?? '');

        if (! in_array($algorithm, self::ALGORITHMS, true)) {
            return 'unknown_preset';
        }

        $value = trim((string) $request->header($headerName, ''));
        if ($value === '' || ($prefix !== '' && ! str_starts_with($value, $prefix))) {
            return 'signature_missing';
        }
        $value = substr($value, strlen($prefix));

        $raw = hash_hmac($algorithm, $body, $secret, true);
        $expected = $encoding === 'base64' ? base64_encode($raw) : bin2hex($raw);
        $given = $encoding === 'base64' ? $value : strtolower($value);

        return hash_equals($expected, $given) ? null : 'signature_mismatch';
    }

    private static function fresh(int $timestamp, int $tolerance): bool
    {
        return $tolerance <= 0 || abs(time() - $timestamp) <= $tolerance;
    }

    private static function json(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function clip(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
