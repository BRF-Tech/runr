<?php

namespace Tests\Unit;

use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * KubernetesClient::lastApplyError(): why the last apply or create failed — the API server's
 * Status message (an admission webhook's refusal), null after a success.
 */
class KubernetesClientApplyErrorTest extends TestCase
{
    private function client(): KubernetesClient
    {
        return new KubernetesClient(Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://k8s.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 't']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2));
    }

    private function manifest(): array
    {
        return ['apiVersion' => 'postgresql.cnpg.io/v1', 'kind' => 'Cluster', 'metadata' => ['name' => 'pg-orders'], 'spec' => ['instances' => 1]];
    }

    public function test_a_refused_apply_keeps_the_api_servers_message(): void
    {
        Http::fake(['*' => Http::response(['kind' => 'Status', 'code' => 422, 'message' => 'admission webhook "vcluster.cnpg.io" denied the request: External cluster r01 not found'], 422)]);
        $k8s = $this->client();

        $this->assertFalse($k8s->applyResource($this->manifest(), 'runr-data'));
        $this->assertSame('HTTP 422: admission webhook "vcluster.cnpg.io" denied the request: External cluster r01 not found', $k8s->lastApplyError());
    }

    public function test_a_failed_create_keeps_the_body_when_there_is_no_status(): void
    {
        Http::fake(function ($request) {
            return $request->method() === 'PATCH'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response('upstream connect error', 503);
        });
        $k8s = $this->client();

        $this->assertFalse($k8s->applyResource($this->manifest(), 'runr-data'));
        $this->assertSame('HTTP 503: upstream connect error', $k8s->lastApplyError());
    }

    public function test_a_success_clears_the_last_error(): void
    {
        $answers = [Http::response(['message' => 'refused'], 422), Http::response([], 200)];
        Http::fake(function () use (&$answers) {
            return array_shift($answers);
        });
        $k8s = $this->client();

        $this->assertFalse($k8s->applyResource($this->manifest(), 'runr-data'));
        $this->assertSame('HTTP 422: refused', $k8s->lastApplyError());
        $this->assertTrue($k8s->applyResource($this->manifest(), 'runr-data'));
        $this->assertNull($k8s->lastApplyError());
    }
}
