<?php

namespace App\Services\Observability\Alerts\Channels;

use App\Services\Observability\OutboundUrlGuard;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

    protected function post(string $url, array $json, array $headers = []): DeliveryResult
    {
        return $this->request($url, true, fn (PendingRequest $http) => $http->withHeaders($headers)->post($url, $json));
    }

    /**
     * A request to a URL of the channel's configuration: checked right before it is sent
     * (OutboundUrlGuard::target(), not only when the channel was saved: DNS changes) and sent
     * pinned to the addresses that were checked. A URL that may not be called is a failed, not
     * retryable result.
     *
     * @param  Closure(PendingRequest): Response  $send
     */
    protected function request(string $url, bool $requireHttps, Closure $send): DeliveryResult
    {
        $target = $this->guard->target($url, (bool) config('observability.alerts.allow_private_targets', false), $requireHttps);
        if (! $target->allowed()) {
            return DeliveryResult::failed((string) $target->reason, false);
        }

        try {
            return DeliveryResult::fromResponse($send($this->client()->withOptions($target->options())));
        } catch (ConnectionException $e) {
            return DeliveryResult::failed($e->getMessage());
        }
    }
}
