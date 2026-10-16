<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\Manifests\ZotManifests;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\RegistrySyncConfigStep;
use App\Services\ZotRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * region:reconcile adds the ghcr.io on-demand upstream to the Zot config of existing regions with
 * an internal registry (builds then take their base images from the in-cluster cache), touching
 * only RunR's sync entries, and restarts Zot only when its config changed.
 */
class RegistrySyncConfigReconcileStepTest extends TestCase
{
    use RefreshDatabase;

    private const NS = 'runr-registry';

    /** @var array<string, array> objects the fake cluster holds, keyed by "kind/name" */
    private array $objects = [];

    /** @var list<array{method: string, key: string, body: array}> */
    private array $writes = [];

    /** config.json of a region installed before this release: Docker Hub only, S3 storage */
    private function installedConfig(): array
    {
        $config = ZotManifests::mirrorConfig();
        $config['extensions']['sync']['registries'] = [[
            'urls' => ['https://registry-1.docker.io'],
            'onDemand' => true,
            'tlsVerify' => true,
            'content' => [['prefix' => '**']],
        ]];
        $config['storage']['storageDriver'] = [
            'name' => 's3', 'endpoint' => 'https://s3.test', 'region' => 'r', 'bucket' => 'b',
            'accesskey' => 'AK', 'secretkey' => 'SK',
        ];

        return $config;
    }

    private function seedZot(array $config, string $kind = 'statefulsets', string $image = 'ghcr.io/project-zot/zot:v2.1.21'): void
    {
        $this->objects['configmaps/'.ZotRegistryService::ZOT_CONFIG_MAP] = [
            'metadata' => ['name' => ZotRegistryService::ZOT_CONFIG_MAP],
            'data' => ['config.json' => json_encode($config)],
        ];
        $this->objects["{$kind}/runr-zot"] = [
            'metadata' => ['name' => 'runr-zot'],
            'spec' => ['template' => ['spec' => ['containers' => [['name' => 'zot', 'image' => $image]]]]],
        ];
    }

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://zot.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();

            if (! preg_match('~/namespaces/'.self::NS.'/(configmaps|statefulsets|deployments)/([^/]+)$~', $path, $m)) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }
            $key = "{$m[1]}/{$m[2]}";

            if ($method === 'GET') {
                return isset($this->objects[$key])
                    ? Http::response($this->objects[$key])
                    : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            $body = json_decode($request->body(), true) ?? [];
            $this->writes[] = ['method' => $method, 'key' => $key, 'body' => $body];
            if ($method === 'PATCH' && isset($this->objects[$key])) {
                $this->objects[$key] = array_replace_recursive($this->objects[$key], $body);
            }

            return Http::response($this->objects[$key] ?? ['kind' => 'Status']);
        });
    }

    private function region(array $attributes = []): KnativeRegion
    {
        static $n = 0;
        $n++;

        return KnativeRegion::create(array_merge([
            'name' => "fn-{$n}",
            'project_namespace' => "fn-{$n}",
            'kubeconfig' => $this->kubeconfig(),
            'is_active' => true,
            'knative_serving_status' => 'ready',
            'registry_mode' => 'internal',
            'registry_status' => 'ready',
            'registry_address' => '10.43.0.5:5000',
            'registry_namespace' => self::NS,
        ], $attributes));
    }

    private function cluster(KnativeRegion ...$regions): ClusterGroup
    {
        $targets = array_map(fn (KnativeRegion $r) => new ReconcileTarget($r), $regions);

        return new ClusterGroup('uid-z', $targets, new KubernetesClient($regions[0]->kubeconfig));
    }

    private function liveConfig(): array
    {
        return json_decode($this->objects['configmaps/'.ZotRegistryService::ZOT_CONFIG_MAP]['data']['config.json'], true);
    }

    public function test_adds_ghcr_upstream_keeps_the_rest_and_restarts_zot_once(): void
    {
        $this->fakeCluster();
        $this->seedZot($this->installedConfig());
        $step = new RegistrySyncConfigStep;
        $cluster = $this->cluster($this->region());

        $lines = $step->run($cluster, false);

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('updated ConfigMap runr-registry/runr-zot-config', $lines[0]);
        $this->assertStringContainsString('restarted StatefulSet runr-registry/runr-zot', $lines[0]);

        $config = $this->liveConfig();
        $this->assertSame(ZotManifests::syncRegistries(true), $config['extensions']['sync']['registries']);
        // Everything outside RunR's sync entries is untouched (S3 credentials included)
        $this->assertSame($this->installedConfig()['storage'], $config['storage']);
        $this->assertTrue(ZotManifests::hasGhcrUpstream(json_encode($config)));

        // Merge patches only: the ConfigMap key and the pod template annotation
        $this->assertSame(['PATCH configmaps/runr-zot-config', 'PATCH statefulsets/runr-zot'], array_map(fn ($w) => "{$w['method']} {$w['key']}", $this->writes));
        $this->assertSame(['config.json'], array_keys($this->writes[0]['body']['data']));
        $this->assertSame(
            hash('sha256', $this->writes[0]['body']['data']['config.json']),
            $this->writes[1]['body']['spec']['template']['metadata']['annotations'][ZotRegistryService::CONFIG_HASH_ANNOTATION],
        );

        // Second run: up to date, no write, no restart
        $this->writes = [];
        $this->assertSame([], $step->run($cluster, false));
        $this->assertSame([], $this->writes);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->fakeCluster();
        $this->seedZot($this->installedConfig());

        $lines = (new RegistrySyncConfigStep)->run($this->cluster($this->region()), true);

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('would update ConfigMap runr-registry/runr-zot-config', $lines[0]);
        $this->assertStringContainsString('then restart StatefulSet runr-registry/runr-zot', $lines[0]);
        $this->assertSame([], $this->writes);
    }

    public function test_older_zot_gets_no_manifest_check_interval(): void
    {
        $this->fakeCluster();
        // An older Zot refuses to start on the unknown key — even one RunR wrote before is removed
        $config = $this->installedConfig();
        array_unshift($config['extensions']['sync']['registries'], ZotManifests::syncRegistries(true)[0]);
        $this->seedZot($config, 'deployments', 'ghcr.io/project-zot/zot-linux-amd64:v2.1.14');

        $lines = (new RegistrySyncConfigStep)->run($this->cluster($this->region()), false);

        $this->assertStringContainsString('no manifestCheckInterval', $lines[0]);
        $this->assertStringContainsString('restarted Deployment runr-registry/runr-zot', $lines[0]);
        $this->assertSame(ZotManifests::syncRegistries(false), $this->liveConfig()['extensions']['sync']['registries']);
        $this->assertSame('PATCH deployments/runr-zot', $this->writes[1]['method'].' '.$this->writes[1]['key']);
    }

    public function test_regions_without_a_ready_internal_registry_are_left_alone(): void
    {
        $this->fakeCluster();
        $this->seedZot($this->installedConfig());

        $cluster = $this->cluster(
            $this->region(['registry_mode' => 'external', 'registry_address' => 'registry.example.test']),
            $this->region(['registry_status' => 'installing']),
            $this->region(['registry_mode' => null, 'registry_status' => null]),
        );

        $this->assertSame([], (new RegistrySyncConfigStep)->run($cluster, false));
        $this->assertSame([], $this->writes);
    }

    public function test_sibling_regions_sharing_one_registry_are_reconciled_once(): void
    {
        $this->fakeCluster();
        $this->seedZot($this->installedConfig());

        $lines = (new RegistrySyncConfigStep)->run($this->cluster($this->region(), $this->region()), false);

        $this->assertCount(1, $lines);
        $this->assertCount(2, $this->writes);
    }

    public function test_missing_zot_config_is_reported_as_skipped(): void
    {
        $this->fakeCluster();

        $lines = (new RegistrySyncConfigStep)->run($this->cluster($this->region()), false);

        $this->assertSame(['skipped: ConfigMap runr-registry/runr-zot-config with config.json not found'], $lines);
        $this->assertSame([], $this->writes);
    }

    /**
     * The restart gives a Zot installed without it the toleration of the dedicated-worker taint
     * (as an install of this release has it): its volume may be bound to a node selected as a
     * dedicated worker, where the restarted pod has to start again.
     */
    public function test_the_restart_carries_the_dedicated_worker_toleration(): void
    {
        $toleration = ['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule'];
        $other = ['key' => 'example.com/maintenance', 'operator' => 'Exists', 'effect' => 'NoSchedule'];
        $this->fakeCluster();
        $this->seedZot($this->installedConfig());
        $this->objects['statefulsets/runr-zot']['spec']['template']['spec']['tolerations'] = [$other];
        $cluster = $this->cluster($this->region());

        $this->assertStringEndsWith(
            'then restart StatefulSet runr-registry/runr-zot with the toleration of runr.dev/dedicated=projects:NoSchedule',
            (new RegistrySyncConfigStep)->run($cluster, true)[0],
        );

        $lines = (new RegistrySyncConfigStep)->run($cluster, false);

        $this->assertStringEndsWith('restarted StatefulSet runr-registry/runr-zot with the toleration of runr.dev/dedicated=projects:NoSchedule', $lines[0]);
        $restart = $this->writes[1]['body']['spec']['template'];
        $this->assertSame([$other, $toleration], $restart['spec']['tolerations'], 'the ones there kept');
        $this->assertArrayHasKey(ZotRegistryService::CONFIG_HASH_ANNOTATION, $restart['metadata']['annotations']);
    }

    public function test_a_zot_that_tolerates_the_taint_restarts_with_its_tolerations_as_they_are(): void
    {
        $this->fakeCluster();
        $this->seedZot($this->installedConfig());
        // installed on the dedicated workers (nodeSelector + toleration)
        $this->objects['statefulsets/runr-zot']['spec']['template']['spec']['tolerations'] = [
            ['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule'],
        ];

        $lines = (new RegistrySyncConfigStep)->run($this->cluster($this->region()), false);

        $this->assertStringEndsWith('restarted StatefulSet runr-registry/runr-zot', $lines[0]);
        $this->assertSame(['metadata'], array_keys($this->writes[1]['body']['spec']['template']), 'the annotation only');
    }

    public function test_step_is_part_of_region_reconcile(): void
    {
        $steps = array_map(fn ($s) => $s->name(), app(RegionReconciler::class)->steps());

        $this->assertContains('registry-sync-config', $steps);
    }
}
