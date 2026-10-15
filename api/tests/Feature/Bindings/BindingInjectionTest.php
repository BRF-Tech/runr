<?php

namespace Tests\Feature\Bindings;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\Bindings\BindingDrivers;
use App\Services\Bindings\BindingEnv;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesBindingOps;
use Tests\TestCase;

/**
 * How bindings reach functions: the per-project Secret <project>-bindings, envFrom + the
 * bindings-hash annotation on the running Knative Service (rolling restart), envFrom on the
 * CronJob, the binding NetworkPolicy of the attached projects, the deploy manifests, cleanup.
 */
class BindingInjectionTest extends TestCase
{
    use ActsWithRoles, FakesBindingOps, RefreshDatabase;

    private const KSVC = '/apis/serving.knative.dev/v1/namespaces/fn/services/orders-api';

    private const CRON = '/apis/batch/v1/namespaces/fn/cronjobs/orders-api-trigger';

    private const SECRET = '/api/v1/namespaces/fn/secrets/orders-api-bindings';

    private User $owner;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBindingFakes();

        $this->owner = $this->userWithRole('owner');
        $this->region = $this->bindingRegion();
        $this->readyService($this->region, 'kv');
        $this->readyService($this->region, 'postgres');
    }

    private function runningService(array $container = []): void
    {
        $this->k8sResources[self::KSVC] = ['spec' => ['template' => [
            'metadata' => ['annotations' => ['runr.dev/deploy-timestamp' => '2026-10-10T08:00:00Z']],
            'spec' => ['containers' => [array_merge(['name' => 'user-container', 'env' => [['name' => 'FOO', 'value' => 'bar']]], $container)]],
        ]]];
    }

    private function attach(Project $project, Binding $binding, string $prefix = '')
    {
        return $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/bindings", ['binding_id' => $binding->id, 'env_prefix' => $prefix]);
    }

    private function policyPath(Binding $binding): string
    {
        return '/apis/networking.k8s.io/v1/namespaces/runr-data/networkpolicies/runr-binding-'.$binding->resource_name;
    }

    private function patchOps(string $path): array
    {
        $calls = array_values(array_filter($this->k8sSentAll('PATCH', $path), fn ($c) => str_starts_with(trim($c['body']), '[')));

        return $calls ? json_decode(end($calls)['body'], true) : [];
    }

    public function test_attach_writes_the_secret_rolls_the_service_and_opens_the_policy(): void
    {
        $this->runningService();
        $project = $this->bindingProject($this->region);
        $binding = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);

        $this->attach($project, $binding, 'ORDERS_')->assertCreated()
            ->assertJsonPath('data.env_names.0', 'ORDERS_DATABASE_URL');

        $env = app(BindingDrivers::class)->for($binding)->env($binding);
        $secret = $this->assertApplied(self::SECRET);
        $this->assertSame('fn', $secret['metadata']['namespace']);
        $this->assertSame(
            ['ORDERS_DATABASE_URL', 'ORDERS_PGDATABASE', 'ORDERS_PGHOST', 'ORDERS_PGPASSWORD', 'ORDERS_PGPORT', 'ORDERS_PGSSLMODE', 'ORDERS_PGUSER'],
            array_keys($secret['data']),
        );
        $this->assertSame($env['DATABASE_URL'], base64_decode($secret['data']['ORDERS_DATABASE_URL']));
        $this->assertSame('pg-pass-1', base64_decode($secret['data']['ORDERS_PGPASSWORD']));

        $prefixed = [];
        foreach ($env as $key => $value) {
            $prefixed['ORDERS_'.$key] = $value;
        }
        $hash = BindingEnv::hash($prefixed);
        $this->assertSame($hash, $secret['metadata']['annotations']['runr.dev/bindings-hash']);

        $this->assertSame([
            ['op' => 'add', 'path' => '/spec/template/spec/containers/0/envFrom', 'value' => [['secretRef' => ['name' => 'orders-api-bindings', 'optional' => true]]]],
            ['op' => 'add', 'path' => '/spec/template/metadata/annotations/runr.dev~1bindings-hash', 'value' => $hash],
        ], $this->patchOps(self::KSVC));

        $policy = $this->assertApplied($this->policyPath($binding));
        $this->assertSame(['cnpg.io/cluster' => 'runr-pg-shared'], $policy['spec']['podSelector']['matchLabels']);
        $this->assertSame([$project->id], $policy['spec']['ingress'][0]['from'][0]['podSelector']['matchExpressions'][0]['values']);
        $this->assertSame(['kubernetes.io/metadata.name' => 'fn'], $policy['spec']['ingress'][0]['from'][0]['namespaceSelector']['matchLabels']);
        $this->assertSame([['protocol' => 'TCP', 'port' => 5432]], $policy['spec']['ingress'][0]['ports']);
        $this->assertNotNull($this->applied('/apis/networking.k8s.io/v1/namespaces/runr-data/networkpolicies/runr-data-default-deny'));
    }

    public function test_a_project_that_is_not_deployed_only_gets_the_secret(): void
    {
        $project = $this->bindingProject($this->region);
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);

        $this->attach($project, $binding)->assertCreated();

        $secret = $this->assertApplied(self::SECRET);
        $this->assertSame(['KV_PREFIX', 'REDIS_URL'], array_keys($secret['data']));
        $this->assertSame([], $this->patchOps(self::KSVC));

        // KV policy: the managed KV Redis pods, Redis port
        $policy = $this->assertApplied($this->policyPath($binding));
        $this->assertSame(['app.kubernetes.io/name' => 'runr-kv'], $policy['spec']['podSelector']['matchLabels']);
        $this->assertSame(6379, $policy['spec']['ingress'][0]['ports'][0]['port']);
    }

    public function test_the_cronjob_gets_env_from(): void
    {
        $project = $this->bindingProject($this->region, 'orders-api', 'schedule');
        $this->k8sResources[self::CRON] = ['spec' => ['jobTemplate' => ['spec' => ['template' => ['spec' => [
            'containers' => [['name' => 'user-container', 'envFrom' => [['configMapRef' => ['name' => 'keep']]]]],
        ]]]]]];
        $binding = $this->makeBinding('external', null, ['name' => 'stripe']);

        $this->attach($project, $binding, 'STRIPE_')->assertCreated();

        $this->assertSame([[
            'op' => 'add',
            'path' => '/spec/jobTemplate/spec/template/spec/containers/0/envFrom',
            'value' => [['configMapRef' => ['name' => 'keep']], ['secretRef' => ['name' => 'orders-api-bindings', 'optional' => true]]],
        ]], $this->patchOps(self::CRON));

        $secret = $this->assertApplied(self::SECRET);
        $this->assertSame('tok-1', base64_decode($secret['data']['STRIPE_API_TOKEN']));
        $this->assertNull($this->applied($this->policyPath($binding)), 'external bindings need no policy');
    }

    public function test_env_names_must_not_collide_and_regions_must_match(): void
    {
        $project = $this->bindingProject($this->region);
        $first = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);
        $second = $this->makeBinding('postgres', $this->region, ['name' => 'billing']);

        $this->attach($project, $first)->assertCreated();
        $this->attach($project, $second)
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'DATABASE_URL') && str_contains($m, 'PGPASSWORD'));
        $this->attach($project, $second, 'BILLING_')->assertCreated();
        $this->attach($project, $first, 'AGAIN_')->assertUnprocessable()->assertJsonPath('message', __('api.bindings.already_attached', ['name' => 'orders']));

        $elsewhere = $this->makeBinding('kv', $this->bindingRegion('other'), ['name' => 'remote']);
        $this->attach($project, $elsewhere)
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.bindings.region_mismatch', ['binding' => 'remote', 'project' => 'orders-api']));

        // A prefix change that collides is refused too
        $attachment = BindingAttachment::where('binding_id', $second->id)->firstOrFail();
        $this->signedIn($this->owner)->patchJson("/api/projects/{$project->id}/bindings/{$attachment->id}", ['env_prefix' => null])
            ->assertUnprocessable();

        $this->assertSame(2, BindingAttachment::count());
    }

    public function test_detaching_the_last_binding_removes_the_secret_env_from_and_policy(): void
    {
        $project = $this->bindingProject($this->region);
        $binding = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);
        $this->attach($project, $binding)->assertCreated();
        $attachment = BindingAttachment::firstOrFail();

        $this->runningService([
            'envFrom' => [['secretRef' => ['name' => 'orders-api-bindings', 'optional' => true]]],
        ]);
        $this->k8sResources[self::KSVC]['spec']['template']['metadata']['annotations']['runr.dev/bindings-hash'] = 'abc';
        $this->k8sCalls = [];

        $this->signedIn($this->owner)->deleteJson("/api/projects/{$project->id}/bindings/{$attachment->id}")
            ->assertOk()
            ->assertJsonPath('message', __('api.bindings.detached'));

        $this->assertNotNull($this->k8sSent('DELETE', self::SECRET));
        $this->assertNull($this->applied(self::SECRET));
        $this->assertSame([
            ['op' => 'remove', 'path' => '/spec/template/spec/containers/0/envFrom'],
            ['op' => 'add', 'path' => '/spec/template/metadata/annotations/runr.dev~1bindings-hash', 'value' => 'none'],
        ], $this->patchOps(self::KSVC));
        $this->assertNotNull($this->k8sSent('DELETE', $this->policyPath($binding)));
    }

    public function test_the_policy_follows_the_attached_projects(): void
    {
        $one = $this->bindingProject($this->region, 'one');
        $two = $this->bindingProject($this->region, 'two');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);

        $this->attach($one, $binding)->assertCreated();
        $this->attach($two, $binding)->assertCreated();

        $ids = [$one->id, $two->id];
        sort($ids);
        $this->assertSame($ids, $this->applied($this->policyPath($binding))['spec']['ingress'][0]['from'][0]['podSelector']['matchExpressions'][0]['values']);

        $attachment = BindingAttachment::where('project_id', $one->id)->firstOrFail();
        $this->signedIn($this->owner)->deleteJson("/api/projects/{$one->id}/bindings/{$attachment->id}")->assertOk();

        $this->assertSame([$two->id], $this->applied($this->policyPath($binding))['spec']['ingress'][0]['from'][0]['podSelector']['matchExpressions'][0]['values']);
        $this->assertNotNull($this->k8sSent('DELETE', '/api/v1/namespaces/fn/secrets/one-bindings'));
    }

    public function test_deploy_manifests_carry_env_from_only_with_attachments(): void
    {
        $project = $this->bindingProject($this->region);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        $ksvc = collect(app(KnativeDeployService::class)->buildManifests($project->fresh(), $version))->firstWhere('kind', 'Service');
        $this->assertArrayNotHasKey('envFrom', $ksvc['spec']['template']['spec']['containers'][0]);

        $binding = $this->makeBinding('external', null, ['name' => 'stripe']);
        BindingAttachment::create(['binding_id' => $binding->id, 'project_id' => $project->id]);

        $ksvc = collect(app(KnativeDeployService::class)->buildManifests($project->fresh(), $version))->firstWhere('kind', 'Service');
        $this->assertSame(
            [['secretRef' => ['name' => 'orders-api-bindings', 'optional' => true]]],
            $ksvc['spec']['template']['spec']['containers'][0]['envFrom'],
        );

        ContainerConfig::where('project_id', $project->id)->update(['trigger_type' => 'schedule', 'schedule' => '*/5 * * * *']);
        $cron = collect(app(KnativeDeployService::class)->buildManifests($project->fresh(), $version))->firstWhere('kind', 'CronJob');
        $this->assertSame(
            [['secretRef' => ['name' => 'orders-api-bindings', 'optional' => true]]],
            $cron['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['envFrom'],
        );
    }

    public function test_project_delete_removes_its_secret_and_policy_membership(): void
    {
        $project = $this->bindingProject($this->region);
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $this->attach($project, $binding)->assertCreated();
        $this->k8sCalls = [];

        $project->delete();

        $this->assertNotNull($this->k8sSent('DELETE', self::SECRET));
        $this->assertNotNull($this->k8sSent('DELETE', $this->policyPath($binding)));
        $this->assertSame(0, BindingAttachment::count());
        $this->assertNotNull(Binding::find($binding->id), 'the binding outlives the project');
    }

    public function test_a_binding_without_credentials_adds_nothing_to_the_secret(): void
    {
        $project = $this->bindingProject($this->region);
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $binding->forceFill(['status' => Binding::STATUS_PROVISIONING, 'credentials' => null])->save();

        $this->assertSame([], BindingEnv::projectEnv($project));
        $this->attach($project, $binding)->assertCreated();

        $this->assertNull($this->applied(self::SECRET), 'nothing to write before provisioning');
        $this->assertNotNull($this->k8sSent('DELETE', self::SECRET));
    }
}
