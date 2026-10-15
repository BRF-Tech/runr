<?php

namespace App\Services\Observability\Alerts\Channels;

use App\Models\Observability\AlertChannel;
use App\Services\Observability\Alerts\AlertMessage;
use Illuminate\Http\Client\ConnectionException;

/**
 * Generic webhook: the alert as JSON, signed like RunR's billing webhook —
 * X-RunR-Signature: sha256=<hex HMAC-SHA256(secret, "<X-RunR-Timestamp>.<raw body>")>.
 */
class WebhookChannel extends HttpChannel
{
    public function rules(): array
    {
        return [
            'config.url' => ['required', 'string', 'url:http,https', 'max:1000'],
            'config.secret' => ['nullable', 'string', 'min:16', 'max:200'],
        ];
    }

    public function send(AlertChannel $channel, AlertMessage $message): DeliveryResult
    {
        $url = (string) ($channel->config['url'] ?? '');
        $reason = $this->guard->check($url, (bool) config('observability.alerts.allow_private_targets', false));
        if ($reason !== null) {
            return DeliveryResult::failed($reason, false);
        }

        $body = (string) json_encode($message->toWebhookPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $headers = self::headers($body, $timestamp, (string) ($channel->config['secret'] ?? ''), $message);

        try {
            return DeliveryResult::fromResponse($this->client()->withHeaders($headers)->withBody($body, 'application/json')->post($url));
        } catch (ConnectionException $e) {
            return DeliveryResult::failed($e->getMessage());
        }
    }

    /** @return array<string, string> */
    public static function headers(string $body, string $timestamp, string $secret, AlertMessage $m): array
    {
        $headers = [
            'X-RunR-Event' => $m->toWebhookPayload()['event'],
            'X-RunR-Timestamp' => $timestamp,
        ];
        if ($m->eventId) {
            $headers['X-RunR-Alert-Id'] = $m->eventId;
        }
        if ($secret !== '') {
            $headers['X-RunR-Signature'] = 'sha256='.self::signature($secret, $timestamp, $body);
        }

        return $headers;
    }

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
