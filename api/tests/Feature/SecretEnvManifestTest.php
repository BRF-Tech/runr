<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\Version;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Secret env vars reach the workload through secretKeyRef entries of the Kubernetes Secret
 * <slug>-secret-env — never as values in the Knative Service / CronJob, and with pass-through never
 * through a manifest at all (docs/security.md): the deploy works on the Secret key by key (merge
 * patch) against the real region client, here a faked API server.
 */
class SecretEnvManifestTest extends TestCase
{
    use RefreshDatabase;

    private const KUBECONFIG = "apiVersion: v1\nkind: Config\ncurrent-context: c\nclusters:\n- name: k\n  cluster:\n    server: https://k8s.test\n    insecure-skip-tls-verify: true\ncontexts:\n- name: c\n  context: {cluster: k, user: u}\nusers:\n- name: u\n  user: {token: abc}\n";

    /**
     * @param  array<string, string|null>  $secrets  name => value kept in the database (written before
     *                                               pass-through), null = a value in the region
     */
    private function project(string $trigger = 'http', array $secrets = ['DB_PASSWORD' => null]): array
    {
        $region = KnativeRegion::create(['name' => 'op', 'project_namespace' => 'op', 'kubeconfig' => self::KUBECONFIG, 'is_active' => true]);
        $project = Project::create(['name' => "svc-{$trigger}", 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => $trigger,
            'schedule' => $trigger === 'schedule' ? '*/5 * * * *' : null,
            'env_vars' => ['PLAIN' => 'visible-value'],
        ]);

        foreach ($secrets as $name => $value) {
            ProjectSecret::create(['project_id' => $project->id, 'name' => $name, 'value' => $value]);
        }

        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        return [$project->fresh(), $version];
    }

    private function manifest(array $manifests, string $kind): ?array
    {
        return collect($manifests)->firstWhere('kind', $kind);
    }

    /**
     * Fake API server: the values Secret holds $data (null: no Secret); every request is recorded.
     *
     * @param  array<string, string>|null  $data
     * @return \ArrayObject<int, Request>
     */
    private function fakeCluster(?array $data): \ArrayObject
    {
        $requests = new \ArrayObject;
        Http::fake(function (Request $request) use ($requests, $data) {
            $requests[] = $request;
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '-secret-env') && $request->method() === 'GET') {
                return $data === null
                    ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                    : Http::response(['kind' => 'Secret', 'data' => array_map('base64_encode', $data)], 200);
            }
            if (str_ends_with($path, '-secret-env') && $request->method() === 'PATCH' && $data === null) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }
            if ($request->method() === 'DELETE') {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return Http::response(['kind' => 'Status'], 200);
        });

        return $requests;
    }

    /** @return list<string> "METHOD /path" of the recorded requests */
    private static function calls(\ArrayObject $requests): array
    {
        return array_map(fn (Request $r) => $r->method().' '.parse_url($r->url(), PHP_URL_PATH), $requests->getArrayCopy());
    }

    public function test_values_never_go_through_a_manifest_only_secret_key_refs_do(): void
    {
        [$project, $version] = $this->project('http', ['DB_PASSWORD' => null, 'LEGACY' => 'hunter2-secret']);
        $manifests = app(KnativeDeployService::class)->buildManifests($project, $version);

        $this->assertNull($this->manifest($manifests, 'Secret'), 'no Secret manifest: values are written key by key');

        $service = $this->manifest($manifests, 'Service');
        $env = collect($service['spec']['template']['spec']['containers'][0]['env']);

        $this->assertSame('visible-value', $env->firstWhere('name', 'PLAIN')['value'], 'plain env vars keep their value entries');
        foreach (['DB_PASSWORD', 'LEGACY'] as $name) {
            $this->assertSame(
                ['secretKeyRef' => ['name' => "{$project->slug}-secret-env", 'key' => $name]],
                $env->firstWhere('name', $name)['valueFrom'],
            );
            $this->assertArrayNotHasKey('value', $env->firstWhere('name', $name));
        }

        $json = json_encode($manifests);
        $this->assertStringNotContainsString('hunter2-secret', $json);
        $this->assertStringNotContainsString(base64_encode('hunter2-secret'), $json);
    }

    public function test_a_scheduled_project_reads_its_secrets_from_the_secret_too(): void
    {
        [$project, $version] = $this->project('schedule');

        $cron = $this->manifest(app(KnativeDeployService::class)->buildManifests($project, $version), 'CronJob');
        $env = collect($cron['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['env']);

        $this->assertSame('DB_PASSWORD', $env->firstWhere('name', 'DB_PASSWORD')['valueFrom']['secretKeyRef']['key']);
    }

    public function test_without_secrets_the_env_has_only_the_internal_network_entries(): void
    {
        [$project, $version] = $this->project('http', []);
        $manifests = app(KnativeDeployService::class)->buildManifests($project, $version);

        $this->assertNull($this->manifest($manifests, 'Secret'));
        $env = $this->manifest($manifests, 'Service')['spec']['template']['spec']['containers'][0]['env'];
        // RUNR_INTERNAL_TOKEN is one of RunR's own keys of the values Secret (docs/internal-network.md)
        $this->assertSame([
            ['name' => 'PLAIN', 'value' => 'visible-value'],
            ['name' => InternalNetwork::ENV_NAME, 'value' => 'svc-http.platform'],
            ['name' => InternalNetwork::ENV_TOKEN, 'valueFrom' => ['secretKeyRef' => ['name' => 'svc-http-secret-env', 'key' => InternalNetwork::ENV_TOKEN]]],
        ], $env);
    }

    public function test_a_region_runr_does_not_deploy_to_gets_no_token(): void
    {
        [$project, $version] = $this->project('http', []);
        $project->knativeRegion->update(['kubeconfig' => null]);
        $manifests = app(KnativeDeployService::class)->buildManifests($project->fresh(), $version);

        $this->assertNull($this->manifest($manifests, 'Secret'));
        $this->assertSame([['name' => 'PLAIN', 'value' => 'visible-value']], $this->manifest($manifests, 'Service')['spec']['template']['spec']['containers'][0]['env']);
    }

    public function test_a_deploy_writes_database_values_and_drops_keys_of_removed_variables(): void
    {
        // In the region: the value of DB_PASSWORD and a key of a variable removed since the last deploy
        $requests = $this->fakeCluster(['DB_PASSWORD' => 'in-region', 'REMOVED' => 'stale']);

        // Scheduled: active right after the apply (no readiness polling)
        [$project, $version] = $this->project('schedule', ['DB_PASSWORD' => null, 'LEGACY' => 'hunter2-secret']);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($project, $version, $deployment);

        $this->assertSame('active', $deployment->fresh()->status);

        $patch = collect($requests->getArrayCopy())->first(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), "/secrets/{$project->slug}-secret-env"));
        $this->assertNotNull($patch, 'the values Secret is patched');
        $this->assertSame('application/merge-patch+json', $patch->header('Content-Type')[0]);
        $body = json_decode($patch->body(), true);
        // The database value goes in, the stale key goes, the value in the region is left alone
        $this->assertSame(base64_encode('hunter2-secret'), $body['data']['LEGACY']);
        $this->assertArrayHasKey('REMOVED', $body['data']);
        $this->assertNull($body['data']['REMOVED']);
        $this->assertArrayNotHasKey('DB_PASSWORD', $body['data']);
        // RunR's own key: the project's token on the internal network, signed for its region
        $claims = InternalNetwork::verify((string) $project->knative_region_id, base64_decode($body['data'][InternalNetwork::ENV_TOKEN]));
        $this->assertSame('svc-schedule.platform', $claims['sub']);

        // Never applied as a whole (server-side apply would drop keys it did not write)
        foreach ($requests as $r) {
            if (str_contains($r->url(), '-secret-env') && $r->method() === 'PATCH') {
                $this->assertStringNotContainsString('apply-patch', (string) ($r->header('Content-Type')[0] ?? ''));
            }
        }
    }

    public function test_a_deploy_fails_naming_the_values_missing_in_the_region(): void
    {
        $requests = $this->fakeCluster(['OTHER' => 'x']);

        [$project, $version] = $this->project('http', ['DB_PASSWORD' => null]);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($project, $version, $deployment);

        $deployment->refresh();
        $this->assertSame('failed', $deployment->status);
        $this->assertStringContainsString('DB_PASSWORD', (string) $deployment->knative_message);
        // Nothing was applied: the pods could not start without the value
        $this->assertNotContains("PATCH /apis/serving.knative.dev/v1/namespaces/op/services/{$project->slug}", self::calls($requests));
    }

    public function test_a_deploy_without_secrets_keeps_only_runr_s_own_keys(): void
    {
        $requests = $this->fakeCluster(['GONE' => 'stale']);

        [$project, $version] = $this->project('schedule', []);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($project, $version, $deployment);

        $calls = self::calls($requests);
        $this->assertNotContains("DELETE /api/v1/namespaces/op/secrets/{$project->slug}-secret-env", $calls, 'the Secret holds the internal-network token');
        $patch = collect($requests->getArrayCopy())->first(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), "/secrets/{$project->slug}-secret-env"));
        $body = json_decode($patch->body(), true);
        $this->assertEqualsCanonicalizing(['GONE', InternalNetwork::ENV_TOKEN], array_keys($body['data']));
        $this->assertNull($body['data']['GONE']);
    }

    public function test_a_deploy_never_drops_the_internal_token_the_region_holds(): void
    {
        [$project, $version] = $this->project('schedule', ['DB_PASSWORD' => null]);
        $token = InternalNetwork::tokenFor((string) $project->knative_region_id, InternalNetwork::nameOf($project));
        $requests = $this->fakeCluster(['DB_PASSWORD' => 'in-region', InternalNetwork::ENV_TOKEN => $token]);

        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($project, $version, $deployment);

        $this->assertSame('active', $deployment->fresh()->status);
        foreach ($requests as $r) {
            if ($r->method() === 'PATCH' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), "/secrets/{$project->slug}-secret-env")) {
                $data = (array) (json_decode($r->body(), true)['data'] ?? []);
                // left as it is, or a new token of the project — never removed with the stale keys
                if (array_key_exists(InternalNetwork::ENV_TOKEN, $data)) {
                    $this->assertSame('svc-schedule.platform', InternalNetwork::verify((string) $project->knative_region_id, base64_decode((string) $data[InternalNetwork::ENV_TOKEN]))['sub']);
                }
            }
        }
    }

    public function test_the_queue_consumer_reads_the_redis_credentials_from_a_secret(): void
    {
        $region = KnativeRegion::create([
            'name' => 'qr', 'project_namespace' => 'qr', 'kubeconfig' => self::KUBECONFIG, 'is_active' => true,
            'redis_mode' => 'external', 'redis_address' => 'redis.qr.svc:6379', 'redis_password' => 'qc-redis-secret',
            'knative_redis_status' => 'ready',
        ]);
        $project = Project::create(['name' => 'svc-queue', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'timeout_seconds' => 30, 'trigger_type' => 'queue', 'queue_stream' => 'orders',
        ]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $project = $project->fresh();

        $manifests = app(KnativeDeployService::class)->buildManifests($project, $version);
        $secret = collect($manifests)->first(fn ($m) => $m['kind'] === 'Secret' && $m['metadata']['name'] === "{$project->slug}-qc-redis");
        $consumer = collect($manifests)->firstWhere('kind', 'Deployment');

        $this->assertNotNull($secret);
        $this->assertSame('redis://:qc-redis-secret@redis.qr.svc:6379', base64_decode($secret['data']['REDIS_ADDRESS']));
        $this->assertSame('qc-redis-secret', base64_decode($secret['data']['REDIS_PASSWORD']));
        $this->assertLessThan(array_search($consumer, $manifests, true), array_search($secret, $manifests, true), 'Secret applied first');

        $env = collect($consumer['spec']['template']['spec']['containers'][0]['env']);
        foreach (['REDIS_ADDRESS', 'REDIS_PASSWORD'] as $name) {
            $this->assertSame(['secretKeyRef' => ['name' => "{$project->slug}-qc-redis", 'key' => $name]], $env->firstWhere('name', $name)['valueFrom']);
        }
        $this->assertSame('orders', $env->firstWhere('name', 'STREAM')['value']);
        $this->assertStringNotContainsString('qc-redis-secret', json_encode($consumer));
    }

    public function test_deleting_a_deployment_keeps_the_values_the_region_holds(): void
    {
        $requests = $this->fakeCluster([]);

        [$project, $version] = $this->project();
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);

        $this->assertTrue(app(KnativeDeployService::class)->teardown($project, $deployment));
        $calls = self::calls($requests);
        // The only copy of the values: the next deploy reads them
        $this->assertNotContains("DELETE /api/v1/namespaces/op/secrets/{$project->slug}-secret-env", $calls);
        // The ExternalSecret of its references goes (its Secret with it)
        $this->assertContains("DELETE /apis/external-secrets.io/v1/namespaces/op/externalsecrets/{$project->slug}-ext-env", $calls);
    }

    public function test_deleting_the_project_removes_its_values(): void
    {
        $requests = $this->fakeCluster([]);

        [$project] = $this->project();

        $this->assertTrue(app(KnativeDeployService::class)->teardownProject($project, withSecretValues: true));
        $this->assertContains("DELETE /api/v1/namespaces/op/secrets/{$project->slug}-secret-env", self::calls($requests));

        // A migration rollback that does not deploy it again keeps them
        $requests->exchangeArray([]);
        app(KnativeDeployService::class)->teardownProject($project);
        $this->assertNotContains("DELETE /api/v1/namespaces/op/secrets/{$project->slug}-secret-env", self::calls($requests));
    }
}
