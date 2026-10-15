<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\Version;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Secret env vars reach the workload through the Kubernetes Secret <slug>-secret-env and
 * secretKeyRef entries — never as values in the Knative Service / CronJob (docs/security.md).
 */
class SecretEnvManifestTest extends TestCase
{
    use RefreshDatabase;

    private const KUBECONFIG = "apiVersion: v1\nkind: Config\ncurrent-context: c\nclusters:\n- name: k\n  cluster:\n    server: https://k8s.test\n    insecure-skip-tls-verify: true\ncontexts:\n- name: c\n  context: {cluster: k, user: u}\nusers:\n- name: u\n  user: {token: abc}\n";

    private function project(string $trigger = 'http', array $secrets = ['DB_PASSWORD' => 'hunter2-secret']): array
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

    public function test_secrets_go_into_a_kubernetes_secret_and_secret_key_refs(): void
    {
        [$project, $version] = $this->project();
        $manifests = app(KnativeDeployService::class)->buildManifests($project, $version);

        $secret = $this->manifest($manifests, 'Secret');
        $this->assertNotNull($secret);
        $this->assertSame("{$project->slug}-secret-env", $secret['metadata']['name']);
        $this->assertSame('op', $secret['metadata']['namespace']);
        $this->assertSame('Opaque', $secret['type']);
        $this->assertSame(['DB_PASSWORD' => base64_encode('hunter2-secret')], $secret['data']);
        $this->assertSame($project->id, $secret['metadata']['labels']['runr/project-id']);

        // Applied before the workload that reads it
        $kinds = array_column($manifests, 'kind');
        $this->assertLessThan(array_search('Service', $kinds, true), array_search('Secret', $kinds, true));

        $service = $this->manifest($manifests, 'Service');
        $env = collect($service['spec']['template']['spec']['containers'][0]['env']);

        $this->assertSame('visible-value', $env->firstWhere('name', 'PLAIN')['value'], 'plain env vars keep their value entries');
        $this->assertSame(
            ['secretKeyRef' => ['name' => "{$project->slug}-secret-env", 'key' => 'DB_PASSWORD']],
            $env->firstWhere('name', 'DB_PASSWORD')['valueFrom'],
        );
        $this->assertArrayNotHasKey('value', $env->firstWhere('name', 'DB_PASSWORD'));

        $json = json_encode($service);
        $this->assertStringNotContainsString('hunter2-secret', $json);
        $this->assertStringNotContainsString(base64_encode('hunter2-secret'), $json);
    }

    public function test_a_scheduled_project_reads_its_secrets_from_the_secret_too(): void
    {
        [$project, $version] = $this->project('schedule');

        $cron = $this->manifest(app(KnativeDeployService::class)->buildManifests($project, $version), 'CronJob');
        $env = collect($cron['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['env']);

        $this->assertSame('DB_PASSWORD', $env->firstWhere('name', 'DB_PASSWORD')['valueFrom']['secretKeyRef']['key']);
        $this->assertStringNotContainsString('hunter2-secret', json_encode($cron));
    }

    public function test_without_secrets_there_is_no_secret_and_the_env_is_unchanged(): void
    {
        [$project, $version] = $this->project('http', []);
        $manifests = app(KnativeDeployService::class)->buildManifests($project, $version);

        $this->assertNull($this->manifest($manifests, 'Secret'));
        $env = $this->manifest($manifests, 'Service')['spec']['template']['spec']['containers'][0]['env'];
        $this->assertSame([['name' => 'PLAIN', 'value' => 'visible-value']], $env);
    }

    public function test_a_deploy_applies_the_secret_and_a_deploy_without_secrets_deletes_it(): void
    {
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

            return $request->method() === 'DELETE'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response(['kind' => 'Status'], 200);
        });

        // Scheduled: active right after the apply (no readiness polling)
        [$project, $version] = $this->project('schedule');
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($project, $version, $deployment);

        $this->assertSame('active', $deployment->fresh()->status);
        $this->assertContains("PATCH /api/v1/namespaces/op/secrets/{$project->slug}-secret-env", $calls);
        $this->assertNotContains("DELETE /api/v1/namespaces/op/secrets/{$project->slug}-secret-env", $calls);

        // Last secret removed → the next deploy deletes the Secret
        ProjectSecret::where('project_id', $project->id)->delete();
        $calls = [];
        $project = $project->fresh();
        $deployment2 = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($project, $version, $deployment2);

        $this->assertNotContains("PATCH /api/v1/namespaces/op/secrets/{$project->slug}-secret-env", $calls);
        $this->assertContains("DELETE /api/v1/namespaces/op/secrets/{$project->slug}-secret-env", $calls);
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

    public function test_deleting_a_deployment_removes_the_secret(): void
    {
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

            return Http::response(['kind' => 'Status'], 200);
        });

        [$project, $version] = $this->project();
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);

        $this->assertTrue(app(KnativeDeployService::class)->teardown($project, $deployment));
        $this->assertContains("DELETE /api/v1/namespaces/op/secrets/{$project->slug}-secret-env", $calls);
    }
}
