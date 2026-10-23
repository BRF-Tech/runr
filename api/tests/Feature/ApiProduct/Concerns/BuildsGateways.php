<?php

namespace Tests\Feature\ApiProduct\Concerns;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * A region with a kubeconfig (every Kubernetes call answered by Http::fake and recorded), a
 * project, the owner's gateway and a route — the API product tests' world.
 */
trait BuildsGateways
{
    /** @var list<array{method: string, path: string, body: array}> */
    protected array $calls = [];

    /** "METHOD path-suffix" => [status, body] */
    protected array $k8sResponses = [];

    protected function region(string $name = 'fn', array $attributes = []): KnativeRegion
    {
        return KnativeRegion::query()->create($attributes + [
            'name' => $name,
            'project_namespace' => $name,
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
        ]);
    }

    protected function project(KnativeRegion $region, string $name = 'orders'): Project
    {
        $project = Project::query()->create(['name' => $name.'-'.Str::lower(Str::random(4)), 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id, 'endpoint_type' => 'gateway'])->saveQuietly();
        // its Knative Service has a public URL: a gateway route rewrites Host to it (gw-host-<route>)
        $namespace = $region->project_namespace ?? $region->name;
        $this->k8sResponses["GET /apis/serving.knative.dev/v1/namespaces/{$namespace}/services/{$project->slug}"] = [200, [
            'metadata' => ['name' => $project->slug, 'namespace' => $namespace],
            'status' => ['url' => "https://{$project->slug}.{$namespace}.apps.example.com"],
        ]];

        return $project;
    }

    protected function gateway(User $owner, KnativeRegion $region): Gateway
    {
        return Gateway::query()->create([
            'user_id' => $owner->id,
            'knative_region_id' => $region->id,
            'subdomain' => $owner->username,
            'status' => 'active',
        ]);
    }

    protected function route(Gateway $gateway, Project $project, string $path = '/orders', array $attributes = []): GatewayRoute
    {
        return GatewayRoute::query()->create($attributes + [
            'gateway_id' => $gateway->id,
            'project_id' => $project->id,
            'path' => $path,
            'strip_prefix' => true,
            'sort_order' => 1,
        ]);
    }

    protected function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = [
                'method' => $request->method(),
                'path' => $path,
                'body' => json_decode($request->body(), true) ?? [],
            ];
            foreach ($this->k8sResponses as $key => [$status, $body]) {
                [$method, $suffix] = explode(' ', $key, 2);
                if ($method === $request->method() && str_ends_with($path, $suffix)) {
                    // a closure answers by the request (e.g. its label selector)
                    return Http::response($body instanceof \Closure ? $body($request) : $body, $status);
                }
            }

            return Http::response(['kind' => 'Status'], 200);
        });
    }

    /**
     * What the region answers for the gateway-auth Deployment: a ready pod (its configuration from
     * RunR), or none yet — created $ageSeconds ago.
     */
    protected function gatewayAuthDeployment(bool $ready, int $ageSeconds = 30, string $namespace = 'fn'): void
    {
        $this->k8sResponses["GET /namespaces/{$namespace}/deployments/runr-gateway-auth"] = [200, [
            'metadata' => ['name' => 'runr-gateway-auth', 'generation' => 2, 'creationTimestamp' => now()->subSeconds($ageSeconds)->toIso8601ZuluString()],
            'status' => $ready
                ? ['observedGeneration' => 2, 'replicas' => 1, 'updatedReplicas' => 1, 'readyReplicas' => 1]
                : ['observedGeneration' => 2, 'replicas' => 1, 'updatedReplicas' => 1],
        ]];
    }

    /**
     * What the region answers for the endpoints of gateway-auth's two Services: the metering
     * proxy's pod ($proxyReady), and the pods that answer the checks — with $oldPod one more of an
     * earlier image, which has no proxy port (a rollout under way).
     */
    protected function gatewayAuthProxy(bool $proxyReady, bool $oldPod = false, string $namespace = 'fn'): void
    {
        $this->k8sResponses["GET /namespaces/{$namespace}/endpointslices"] = [200, function (Request $request) use ($proxyReady, $oldPod) {
            $proxy = str_contains(urldecode((string) parse_url($request->url(), PHP_URL_QUERY)), 'runr-gateway-auth-proxy');
            $endpoints = [['addresses' => ['10.42.0.5'], 'conditions' => ['ready' => $proxy ? $proxyReady : true]]];
            if ($oldPod && ! $proxy) {
                $endpoints[] = ['addresses' => ['10.42.0.9'], 'conditions' => ['ready' => true]];
            }

            return ['items' => [['endpoints' => $endpoints]]];
        }];
    }

    /** The last call of $method whose path ends with $suffix. */
    protected function k8sCall(string $method, string $suffix): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && str_ends_with($call['path'], $suffix)) {
                return $call;
            }
        }

        return null;
    }
}
