<?php

namespace App\Workflows\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpWorkflowTransport implements WorkflowTransport
{
    public function send(string $url, string $body, array $headers): TransportResponse
    {
        $started = hrtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->withOptions(['verify' => (bool) config('workflows.verify_tls', true), 'allow_redirects' => false])
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
