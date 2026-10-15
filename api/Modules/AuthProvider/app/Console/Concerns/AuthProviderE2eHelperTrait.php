<?php

namespace Modules\AuthProvider\Console\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

trait AuthProviderE2eHelperTrait
{
    protected function api(?string $token = null): PendingRequest
    {
        return Http::withToken($token ?? $this->adminToken)
            ->accept('application/json')
            ->baseUrl($this->baseUrl);
    }

    protected function publicApi(): PendingRequest
    {
        return Http::accept('application/json')->baseUrl($this->baseUrl);
    }

    protected function runTest(string $label, callable $fn): array
    {
        $start = microtime(true);
        try {
            $fn();

            return [
                'label' => $label, 'passed' => true,
                'message' => '', 'time' => microtime(true) - $start,
            ];
        } catch (\Throwable $e) {
            return [
                'label' => $label, 'passed' => false,
                'message' => $e->getMessage(), 'time' => microtime(true) - $start,
            ];
        }
    }

    protected function e2eAssert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }
}
