<?php

namespace Tests\Concerns;

use App\Models\KnativeRegion;
use App\Models\Observability\RegionTracingConfig;
use App\Models\Project;
use App\Models\XaasConfig;
use App\Services\Dns\DnsResolver;
use App\Services\HelmResult;
use App\Services\HelmService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * Observability fixtures: a region whose Kubernetes API is faked (Http::fake routing by
 * "METHOD /path" prefix, every call recorded), a recording HelmService, a fake DNS resolver and
 * projects with a ready tracing backend.
 */
trait FakesObservabilityCluster
{
    protected const K8S = 'https://obs-region.test:6443';

    /** @var list<array{method: string, url: string, path: string, query: array, body: mixed, headers: array}> */
    protected array $k8sCalls = [];

    /** "METHOD /path-prefix" => callable(Request): \GuzzleHttp\Promise\PromiseInterface|array{0:int,1:mixed} */
    protected array $k8sRoutes = [];

    /** Responses of other hosts (Slack, Teams, project endpoints …) by URL prefix */
    protected array $externalRoutes = [];

    protected object $helm;

    protected object $dns;

    protected function fakeObservabilityCluster(): void
    {
        $this->helm = new class extends HelmService
        {
            public array $installs = [];

            public array $uninstalls = [];

            public array $repos = [];

            public bool $fail = false;

            public function install(string $release, string $chart, string $namespace, array $setValues, string $kubeconfigYaml, ?string $version = null, array $valuesArray = [], array $setJsonValues = [], bool $wait = true): HelmResult
            {
                $this->installs[$release] = compact('release', 'chart', 'namespace', 'version', 'valuesArray', 'wait');

                return new HelmResult(! $this->fail, '', $this->fail ? 'boom' : '', $this->fail ? 1 : 0);
            }

            public function uninstall(string $release, string $namespace, string $kubeconfigYaml): HelmResult
            {
                $this->uninstalls[] = $release;

                return new HelmResult(true, '', '', 0);
            }

            public function repoAdd(string $name, string $url): HelmResult
            {
                $this->repos[$name] = $url;

                return new HelmResult(true, '', '', 0);
            }

            public function repoUpdate(): HelmResult
            {
                return new HelmResult(true, '', '', 0);
            }
        };
        $this->app->instance(HelmService::class, $this->helm);

        $this->dns = new class extends DnsResolver
        {
            public array $addressRecords = [];

            public function addresses(string $name): array
            {
                return $this->addressRecords[$name] ?? [];
            }
        };
        $this->app->instance(DnsResolver::class, $this->dns);

        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_starts_with($url, self::K8S)) {
                $this->k8sCalls[] = [
                    'method' => $request->method(),
                    'url' => $url,
                    'path' => $path,
                    'query' => $query,
                    'body' => json_decode($request->body(), true) ?? $request->body(),
                    'headers' => $request->headers(),
                ];

                $best = null;
                foreach ($this->k8sRoutes as $key => $handler) {
                    [$method, $prefix] = explode(' ', $key, 2);
                    if ($method === $request->method() && str_starts_with($path, $prefix) && ($best === null || strlen($prefix) > strlen($best[0]))) {
                        $best = [$prefix, $handler];
                    }
                }
                if ($best) {
                    $answer = ($best[1])($request);
                    if (is_array($answer) && isset($answer[0]) && is_int($answer[0])) {
                        return Http::response($answer[1] ?? [], $answer[0]);
                    }

                    return $answer;
                }

                // Kubernetes defaults: GETs of unknown objects 404, writes succeed
                return $request->method() === 'GET' ? Http::response(['kind' => 'Status', 'code' => 404], 404) : Http::response(['kind' => 'Status'], 200);
            }

            foreach ($this->externalRoutes as $prefix => $handler) {
                if (str_starts_with($url, $prefix)) {
                    return is_callable($handler) ? $handler($request) : $handler;
                }
            }

            return Http::response(['error' => 'unexpected request '.$request->method().' '.$url], 599);
        });
    }

    protected function kubeconfigYaml(string $server = self::K8S): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => $server, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    protected function obsRegion(array $overrides = []): KnativeRegion
    {
        static $n = 0;
        $n++;

        return KnativeRegion::create(array_merge([
            'name' => "obs-{$n}",
            'project_namespace' => 'fn',
            'kubeconfig' => $this->kubeconfigYaml(),
            'knative_serving_namespace' => 'knative-serving',
            'knative_settings' => ['knative_domain' => "obs-{$n}.runr.test"],
            'is_active' => true,
        ], $overrides));
    }

    protected function tracingReady(KnativeRegion $region, array $overrides = []): RegionTracingConfig
    {
        $config = RegionTracingConfig::forRegion($region);
        $config->fill(array_merge(['mode' => 'managed', 'status' => RegionTracingConfig::STATUS_READY, 'tempo_url' => 'http://tempo.observability.svc.cluster.local:3200'], $overrides))->save();

        return $config;
    }

    /** An http-triggered Python XaaS project in the region (no runtime registry needed for API tests). */
    protected function obsProject(KnativeRegion $region, string $name = 'orders', string $trigger = 'http'): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        XaasConfig::create([
            'project_id' => $project->id,
            'filename' => 'main.py',
            'handler' => 'handler',
            'trigger_type' => $trigger,
            'queue_stream' => $trigger === 'queue' ? 'orders' : null,
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
        ]);

        return $project->fresh(['xaasConfig', 'knativeRegion']);
    }

    /** Calls of a method whose path starts with $prefix. */
    protected function k8sCallsTo(string $method, string $prefix): array
    {
        return array_values(array_filter($this->k8sCalls, fn ($c) => $c['method'] === $method && str_starts_with($c['path'], $prefix)));
    }
}
