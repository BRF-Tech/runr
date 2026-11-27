<?php

namespace App\Workflows\Transport;

use App\Workflows\Engine\EndpointGuard;
use App\Workflows\Engine\WorkflowException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpWorkflowTransport implements WorkflowTransport
{
    public function __construct(private readonly EndpointGuard $guard) {}

    public function send(string $url, string $body, array $headers, bool $override = false): TransportResponse
    {
        $options = ['verify' => (bool) config('workflows.verify_tls', true), 'allow_redirects' => false];

        // An endpoint override is checked again before every request, not only when it was saved
        // (DNS changes), and the request goes to the addresses that were checked; so is the
        // project's function URL when its domain is the organization's own
        try {
            $options = ($override ? $this->guard->target($url) : $this->guard->functionTarget($url))->options() + $options;
        } catch (WorkflowException $e) {
            throw new TransportException('Function endpoint refused: '.$e->getMessage());
        }

        $started = hrtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->withOptions($options)
                ->connectTimeout((int) config('workflows.connect_timeout', 10))
                ->timeout((int) config('workflows.request_timeout', 300))
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            throw new TransportException('Function unreachable: '.$e->getMessage());
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1e6);

        if (! $response->successful()) {
            throw new TransportException('Function answered HTTP '.$response->status(), $response->status(), $response->body());
        }

        return new TransportResponse($response->status(), $response->body(), $durationMs);
    }
}
