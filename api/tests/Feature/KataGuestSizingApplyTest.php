<?php

namespace Tests\Feature;

use App\Services\Helpers\KataRuntime;
use App\Services\KubernetesClient;
use App\Services\Sandboxes\SandboxManifests;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Every workload the API writes to a region carries the guest sizing annotations a Dragonball
 * (Kata runtime-rs) guest needs for its vCPUs — whichever service built the manifest.
 */
class KataGuestSizingApplyTest extends TestCase
{
    private const SERVER = 'https://k8s.test';

    private function client(): KubernetesClient
    {
        return new KubernetesClient(Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2));
    }

    /** @return list<array> decoded bodies sent to the API server */
    private function sentBodies(): array
    {
        return Http::recorded()->map(fn (array $pair) => json_decode($pair[0]->body(), true))->filter()->values()->all();
    }

    public function test_created_pods_and_applied_knative_services_are_sized(): void
    {
        Http::fake([self::SERVER.'/*' => Http::response(['kind' => 'Status'], 200)]);
        $k8s = $this->client();

        $pod = [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => ['name' => 'sbx', 'namespace' => 'runr-sbx-1'],
            'spec' => ['runtimeClassName' => 'kata-dragonball', 'containers' => [[
                'name' => SandboxManifests::CONTAINER,
                'image' => 'x',
                'resources' => ['limits' => ['cpu' => '2000m', 'memory' => '512Mi']],
            ]]],
        ];
        $this->assertTrue($k8s->createResource($pod, 'runr-sbx-1'));

        $ksvc = [
            'apiVersion' => 'serving.knative.dev/v1',
            'kind' => 'Service',
            'metadata' => ['name' => 'fn', 'namespace' => 'projects'],
            'spec' => ['template' => [
                'metadata' => ['annotations' => ['queue.sidecar.serving.knative.dev/cpu-resource-limit' => '1000m']],
                'spec' => ['runtimeClassName' => 'kata-dragonball', 'containers' => [[
                    'name' => 'user-container',
                    'image' => 'x',
                    'resources' => ['limits' => ['cpu' => '1', 'memory' => '256Mi']],
                ]]],
            ]],
        ];
        $this->assertTrue($k8s->applyResource($ksvc, 'projects'));

        [$sentPod, $sentKsvc] = $this->sentBodies();
        $this->assertSame('3', $sentPod['metadata']['annotations'][KataRuntime::GUEST_VCPUS_ANNOTATION]);
        $this->assertSame('3', $sentPod['metadata']['annotations'][KataRuntime::GUEST_MAX_VCPUS_ANNOTATION]);
        // 1 CPU function + 1 CPU queue-proxy → 1 + 2
        $this->assertSame('3', $sentKsvc['spec']['template']['metadata']['annotations'][KataRuntime::GUEST_VCPUS_ANNOTATION]);
        $this->assertSame('1000m', $sentKsvc['spec']['template']['metadata']['annotations']['queue.sidecar.serving.knative.dev/cpu-resource-limit']);
    }

    public function test_other_runtime_classes_are_sent_unchanged(): void
    {
        Http::fake([self::SERVER.'/*' => Http::response(['kind' => 'Status'], 200)]);

        $pod = [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => ['name' => 'sbx', 'namespace' => 'n'],
            'spec' => ['runtimeClassName' => 'kata-clh', 'containers' => [['name' => 'c', 'image' => 'x', 'resources' => ['limits' => ['cpu' => '2']]]]],
        ];
        $this->client()->createResource($pod, 'n');

        Http::assertSent(fn (Request $request) => json_decode($request->body(), true) === $pod);
    }
}
