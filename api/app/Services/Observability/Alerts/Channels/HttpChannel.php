<?php

namespace App\Services\Observability\Alerts\Channels;

use App\Services\Observability\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Shared HTTP plumbing of the webhook-style channels: SSRF guard, timeout, no redirects. */
abstract class HttpChannel implements ChannelSender
{
    public function __construct(protected OutboundUrlGuard $guard) {}

    protected function client(): PendingRequest
    {
        return Http::timeout(max(1, (int) config('observability.alerts.delivery_timeout', 10)))
            ->withOptions(['allow_redirects' => false])
            ->withUserAgent('RunR-Alerts/1')
            ->acceptJson();
    }

    /** Null when the URL may be called, else a failed (not retryable) result. */
    protected function guard(string $url): ?DeliveryResult
    {
        $reason = $this->guard->check($url, (bool) config('observability.alerts.allow_private_targets', false), true);

        return $reason === null ? null : DeliveryResult::failed($reason, false);
    }

    protected function post(string $url, array $json, array $headers = []): DeliveryResult
    {
        if ($blocked = $this->guard($url)) {
            return $blocked;
        }

        try {
            return DeliveryResult::fromResponse($this->client()->withHeaders($headers)->post($url, $json));
        } catch (ConnectionException $e) {
            return DeliveryResult::failed($e->getMessage());
        }
    }
}
