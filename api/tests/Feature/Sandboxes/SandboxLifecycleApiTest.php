<?php

namespace Tests\Feature\Sandboxes;

use App\Events\SandboxActivity;
use App\Jobs\Sandboxes\StartSandboxJob;
use App\Models\AuditEvent;
use App\Models\Sandbox;
use App\Models\SandboxEvent;
use App\Models\SandboxRegionSetting;
use App\Services\Sandboxes\SandboxService;
use App\Services\Sandboxes\Tar\TarReader;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * /api/sandboxes lifecycle against the fake cluster: create (namespace objects, pod, env + files
 * injection, response shape), list / get / delete, TTL, keepalive, pause / resume, network,
 * activity log, wait: false, start failures, validation and organization scoping.
 */
class SandboxLifecycleApiTest extends SandboxTestCase
{
    public function test_create_starts_a_pod_under_the_strongest_class_and_injects_env_and_files(): void
    {
        Event::fake([SandboxActivity::class]);

        $data = $this->createSandbox([
            'runtime' => 'python',
            'cpu' => '1',
            'memory' => '1Gi',
            'disk' => 2048,
            'env' => ['OPENAI_API_KEY' => 'sk-secret', 'MODE' => 'test'],
            'files' => [['path' => '/workspace/main.py', 'content_base64' => base64_encode("print(42)\n")]],
            'metadata' => ['agent' => 'planner'],
        ]);

        $this->assertSame('running', $data['status']);
        $this->assertSame('kata-fc', $data['sandbox_class']);
        $this->assertSame('kata', $data['isolation']);
        $this->assertSame('python', $data['runtime']);
        $this->assertSame('3.14', $data['runtime_version']);
        $this->assertSame(['cpu' => '1000m', 'cpu_m' => 1000, 'memory_mb' => 1024, 'disk_mb' => 2048, 'pids_limit' => 256], $data['resources']);
        $this->assertSame(['OPENAI_API_KEY', 'MODE'], $data['env_keys']);
        $this->assertSame(['mode' => 'none', 'egress_allow' => []], $data['network']);
        $this->assertSame(['agent' => 'planner'], $data['metadata']);
        $this->assertFalse($data['from_pool']);
        $this->assertNotNull($data['start_ms']);
        $this->assertSame('eu-1', $data['region']['name']);
        $this->assertStringNotContainsString('sk-secret', json_encode($data));

        // Namespace with Pod Security + default deny
        $this->assertSame('restricted', $this->k8sNamespaces['runr-sbx-default']['metadata']['labels']['pod-security.kubernetes.io/enforce']);
        $this->assertArrayHasKey('runr-sbx-default/runr-sandbox-default-deny', $this->k8sPolicies);
        $this->assertSame(['count/pods' => '30'], $this->k8sQuotas['runr-sbx-default/runr-sandbox-quota']['spec']['hard']);

        // The pod
        $pod = $this->podOf($data['id']);
        $this->assertSame('kata-fc', $pod['spec']['runtimeClassName']);
        $this->assertSame('ghcr.io/runrsh/exec-python:3.14', $pod['spec']['containers'][0]['image']);
        $this->assertFalse($pod['spec']['automountServiceAccountToken']);
        $this->assertSame('1000m', $pod['spec']['containers'][0]['resources']['limits']['cpu']);
        $this->assertStringNotContainsString('sk-secret', json_encode($pod));
        $uid = $pod['spec']['securityContext']['runAsUser'];
        $this->assertGreaterThanOrEqual(100000, $uid);
        $this->assertSame(86400 + 300, $pod['spec']['activeDeadlineSeconds']);

        // Env: the Secret sbx-<id>-env, read through secretKeyRef — never a value in the pod spec
        $secret = $this->k8sSecrets['runr-sbx-default/sbx-'.strtolower($data['id']).'-env'];
        $this->assertSame(['OPENAI_API_KEY' => base64_encode('sk-secret'), 'MODE' => base64_encode('test')], $secret['data']);
        $this->assertSame(strtolower($data['id']), $secret['metadata']['labels']['runr.dev/sandbox-id']);
        $env = collect($pod['spec']['containers'][0]['env'])->keyBy('name');
        $this->assertSame(['secretKeyRef' => ['name' => 'sbx-'.strtolower($data['id']).'-env', 'key' => 'OPENAI_API_KEY']], $env['OPENAI_API_KEY']['valueFrom']);
        $this->assertArrayNotHasKey('value', $env['OPENAI_API_KEY']);

        // Files arrive as one tar on stdin (a cold pod needs no env file)
        $inject = $this->exec->callsContaining('exec tar -x -m -o -f - -C');
        $this->assertCount(1, $inject);
        $entries = collect(TarReader::entries($inject[0]['stdin']))->keyBy('name');
        $this->assertSame("print(42)\n", $entries['workspace/main.py']['content']);
        $this->assertArrayNotHasKey('home/sandbox/.runr/env.sh', $entries->all());

        // Stored encrypted, activity recorded, audit hook fired
        $row = Sandbox::find($data['id']);
        $this->assertSame(['OPENAI_API_KEY' => 'sk-secret', 'MODE' => 'test'], $row->env);
        $this->assertStringNotContainsString('sk-secret', (string) \DB::table('sandboxes')->where('id', $row->id)->value('env'));
        $this->assertTrue(SandboxEvent::where('sandbox_id', $row->id)->where('type', 'sandbox.create')->exists());
        Event::assertDispatched(SandboxActivity::class, fn ($e) => $e->action === 'sandbox.create' && $e->sandboxId === $row->id);
    }

    public function test_without_env_or_files_nothing_is_injected(): void
    {
        $data = $this->createSandbox();

        $this->assertSame([], $this->k8sSecrets);
        $this->assertSame([], $this->exec->calls);
        $this->assertSame('running', $data['status']);
    }

    public function test_delete_removes_the_env_secret(): void
    {
        $data = $this->createSandbox(['env' => ['TOKEN' => 'x']]);
        $this->assertCount(1, $this->k8sSecrets);

        $this->deleteJson("/api/sandboxes/{$data['id']}")->assertOk();

        $this->assertSame([], $this->k8sSecrets);
    }

    public function test_requests_are_audited_and_responses_never_cached(): void
    {
        $data = $this->createSandbox(['env' => ['TOKEN' => 'sk-secret']]);
        $this->postJson("/api/sandboxes/{$data['id']}/exec", ['cmd' => ['echo', 'hi']])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson("/api/sandboxes/{$data['id']}")->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('ETag');
        $this->deleteJson("/api/sandboxes/{$data['id']}")->assertOk();

        $actions = AuditEvent::query()->where('target_id', $data['id'])->pluck('action')->all();
        $this->assertContains('sandbox.create', $actions);
        $this->assertContains('sandbox.exec', $actions);
        $this->assertContains('sandbox.delete', $actions);
        $create = AuditEvent::query()->where('action', 'sandbox.create')->sole();
        $this->assertSame(['TOKEN'], $create->metadata['env_keys']);
        $this->assertStringNotContainsString('sk-secret', json_encode($create->metadata));
        $exec = AuditEvent::query()->where('action', 'sandbox.exec')->sole();
        $this->assertSame('echo hi', $exec->metadata['command']);
    }

    public function test_list_get_and_filters(): void
    {
        $a = $this->createSandbox(['metadata' => ['agent' => 'a']]);
        $b = $this->createSandbox(['metadata' => ['agent' => 'b']]);
        $this->deleteJson("/api/sandboxes/{$b['id']}")->assertOk();

        $this->getJson('/api/sandboxes')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $a['id']);

        $this->getJson('/api/sandboxes?status=ended')->assertOk()->assertJsonPath('data.0.id', $b['id']);
        $this->getJson('/api/sandboxes?status=all')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/sandboxes?status=all&metadata[agent]=b')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $b['id']);
        $this->getJson("/api/sandboxes?region={$this->region->id}")->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson("/api/sandboxes/{$a['id']}")->assertOk()->assertJsonPath('data.status', 'running');
        $this->getJson('/api/sandboxes/01JB0000000000000000000000')->assertNotFound();
    }

    public function test_sandboxes_of_another_organization_are_invisible(): void
    {
        $foreign = $this->runningSandbox(['tenant_id' => 'tenant-x']);

        $this->getJson("/api/sandboxes/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/sandboxes/{$foreign->id}/exec", ['cmd' => 'id'])->assertNotFound();
        $this->getJson('/api/sandboxes?status=all')->assertJsonPath('meta.total', 0);
    }

    public function test_warm_pool_pods_are_never_listed_or_reachable(): void
    {
        $warm = $this->runningSandbox(['status' => Sandbox::STATUS_WARM]);

        $this->getJson('/api/sandboxes?status=all')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/sandboxes/{$warm->id}")->assertNotFound();
    }

    public function test_delete_removes_pod_and_policy(): void
    {
        $data = $this->createSandbox(['network' => ['egress_allow' => ['pypi.org:443']]]);
        $this->assertArrayHasKey('runr-sbx-default/sbx-'.strtolower($data['id']).'-egress', $this->k8sPolicies);

        $this->deleteJson("/api/sandboxes/{$data['id']}")->assertOk()
            ->assertJsonPath('data.status', 'terminated')
            ->assertJsonPath('data.end_reason', 'deleted');

        $this->assertNull($this->podOf($data['id']));
        $this->assertArrayNotHasKey('runr-sbx-default/sbx-'.strtolower($data['id']).'-egress', $this->k8sPolicies);

        // Idempotent
        $this->deleteJson("/api/sandboxes/{$data['id']}")->assertOk()->assertJsonPath('data.status', 'terminated');
    }

    public function test_egress_allowlist_policy(): void
    {
        $data = $this->createSandbox(['network' => ['egress_allow' => ['pypi.org:443', '10.0.0.0/8']]]);

        $this->assertSame(['mode' => 'allowlist', 'egress_allow' => ['pypi.org:443', '10.0.0.0/8']], $data['network']);
        $policy = $this->k8sPolicies['runr-sbx-default/sbx-'.strtolower($data['id']).'-egress'];
        $cidrs = collect($policy['spec']['egress'])->flatMap(fn ($r) => collect($r['to'])->pluck('ipBlock.cidr'))->filter()->values()->all();
        $this->assertEqualsCanonicalizing(['151.101.0.223/32', '10.0.0.0/8'], $cidrs);

        // Unresolvable names are refused before anything is created
        $this->postJson('/api/sandboxes', ['network' => ['egress_allow' => ['nowhere.example.com']]])
            ->assertStatus(422)->assertJsonPath('reason', 'network_invalid');
    }

    public function test_network_update_replaces_the_policy(): void
    {
        $data = $this->createSandbox();
        $key = 'runr-sbx-default/sbx-'.strtolower($data['id']).'-egress';

        $this->putJson("/api/sandboxes/{$data['id']}/network", ['network' => ['egress_allow' => ['192.0.2.0/24:443']]])
            ->assertOk()->assertJsonPath('data.network.mode', 'allowlist');
        $this->assertArrayHasKey($key, $this->k8sPolicies);

        $this->putJson("/api/sandboxes/{$data['id']}/network", ['network' => 'none'])
            ->assertOk()->assertJsonPath('data.network.mode', 'none');
        $this->assertArrayNotHasKey($key, $this->k8sPolicies);
    }

    public function test_timeout_extends_up_to_the_regions_max_ttl(): void
    {
        SandboxRegionSetting::create(['knative_region_id' => $this->region->id, 'max_ttl_seconds' => 3600]);
        $data = $this->createSandbox(['ttl_seconds' => 600]);

        $this->postJson("/api/sandboxes/{$data['id']}/timeout", ['ttl_seconds' => 1800])->assertOk();
        $this->assertEqualsWithDelta(now()->addSeconds(1800)->timestamp, Sandbox::find($data['id'])->expires_at->timestamp, 5);

        $this->postJson("/api/sandboxes/{$data['id']}/timeout", ['ttl_seconds' => 7200])->assertStatus(422);

        // Late extensions stop at start + max TTL
        Sandbox::whereKey($data['id'])->update(['started_at' => now()->subMinutes(50)]);
        $this->postJson("/api/sandboxes/{$data['id']}/timeout", ['ttl_seconds' => 3600])->assertOk();
        $row = Sandbox::find($data['id']);
        $this->assertEqualsWithDelta($row->started_at->copy()->addSeconds(3600)->timestamp, $row->expires_at->timestamp, 2);
    }

    public function test_keepalive_resets_the_idle_timer(): void
    {
        $row = $this->runningSandbox(['last_activity_at' => now()->subMinutes(9)]);

        $this->postJson("/api/sandboxes/{$row->id}/keepalive")->assertOk();
        $this->assertTrue($row->fresh()->last_activity_at->gt(now()->subMinute()));
    }

    public function test_pause_and_resume_signal_every_process(): void
    {
        $row = $this->runningSandbox();

        $this->postJson("/api/sandboxes/{$row->id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->assertCount(1, $this->exec->callsContaining('kill -STOP -1'));

        // Commands wait for resume
        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'echo hi'])
            ->assertStatus(409)->assertJsonPath('reason', 'not_running')->assertJsonPath('status', 'paused');

        $this->postJson("/api/sandboxes/{$row->id}/resume")->assertOk()->assertJsonPath('data.status', 'running');
        $this->assertCount(1, $this->exec->callsContaining('kill -CONT -1'));
    }

    public function test_activity_log_and_pod_details(): void
    {
        $data = $this->createSandbox();
        $this->postJson("/api/sandboxes/{$data['id']}/exec", ['cmd' => 'echo hi'])->assertOk();

        $response = $this->getJson("/api/sandboxes/{$data['id']}/logs?pod=1")->assertOk();
        $types = collect($response->json('data.events'))->pluck('type')->all();
        $this->assertContains('sandbox.create', $types);
        $this->assertContains('sandbox.start', $types);
        $this->assertContains('sandbox.exec', $types);
        $this->assertSame('Running', $response->json('data.pod.phase'));
        $this->assertSame('sandbox log', $response->json('data.pod.log'));
    }

    public function test_without_wait_the_start_finishes_in_the_background(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/sandboxes', ['wait' => false])->assertStatus(202);
        $this->assertSame('pending', $response->json('data.status'));
        Queue::assertPushed(StartSandboxJob::class, fn ($job) => $job->sandboxId === $response->json('data.id'));

        // The job: pod running → env written → running
        (new StartSandboxJob($response->json('data.id'), 15))->handle(app(SandboxService::class));
        $row = Sandbox::find($response->json('data.id'));
        $this->assertSame('running', $row->status);
        $this->assertGreaterThanOrEqual(15, $row->start_ms);
    }

    public function test_a_pod_that_cannot_pull_its_image_fails_the_create(): void
    {
        $this->podStartState = 'image_error';

        $response = $this->postJson('/api/sandboxes', ['image' => 'example.com/nope:1'])->assertStatus(502);
        $this->assertSame('failed', $response->json('data.status'));
        $this->assertSame('failed', $response->json('data.end_reason'));
        $this->assertStringContainsString('ErrImagePull', $response->json('data.error'));
        $this->assertNull($this->podOf($response->json('data.id')));
    }

    public function test_custom_image(): void
    {
        $data = $this->createSandbox(['image' => 'docker.io/library/alpine:3.21', 'sandbox_class' => 'gvisor']);

        $this->assertNull($data['runtime']);
        $this->assertSame('docker.io/library/alpine:3.21', $data['image']);
        $this->assertSame('gvisor', $data['sandbox_class']);

        config(['sandbox.allow_custom_images' => false]);
        $this->postJson('/api/sandboxes', ['image' => 'docker.io/library/alpine:3.21'])->assertStatus(422)->assertJsonPath('reason', 'runtime_unavailable');
    }

    public function test_validation(): void
    {
        $this->postJson('/api/sandboxes', ['cpu' => 'fast'])->assertStatus(422)->assertJsonValidationErrors('cpu');
        $this->postJson('/api/sandboxes', ['cpu' => '64'])->assertStatus(422)->assertJsonValidationErrors('cpu');
        $this->postJson('/api/sandboxes', ['memory' => '32Mi'])->assertStatus(422)->assertJsonValidationErrors('memory');
        $this->postJson('/api/sandboxes', ['ttl_seconds' => 999999])->assertStatus(422)->assertJsonValidationErrors('ttl_seconds');
        $this->postJson('/api/sandboxes', ['env' => ['BAD-KEY' => 'x']])->assertStatus(422)->assertJsonValidationErrors('env');
        $this->postJson('/api/sandboxes', ['runtime' => 'cobol'])->assertStatus(422)->assertJsonPath('reason', 'runtime_unavailable');
        $this->postJson('/api/sandboxes', ['version' => '1.0'])->assertStatus(422)->assertJsonValidationErrors('version');
        $this->postJson('/api/sandboxes', ['region' => 'mars'])->assertStatus(422)->assertJsonPath('reason', 'region_unavailable');
        $this->postJson('/api/sandboxes', ['files' => [['path' => 'relative.txt', 'content_base64' => '']]])->assertStatus(422);
        $this->postJson('/api/sandboxes', ['image' => 'bad image name'])->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertSame(0, Sandbox::count());
        $this->assertSame([], $this->k8sPods);
    }

    public function test_a_region_without_sandbox_runtime_needs_the_runc_opt_in(): void
    {
        $bare = $this->sandboxRegion(['name' => 'bare', 'sandbox_gvisor_status' => 'not_installed', 'sandbox_kata_status' => 'not_installed']);

        $this->postJson('/api/sandboxes', ['region' => 'bare'])->assertStatus(422)->assertJsonPath('reason', 'class_unavailable');

        SandboxRegionSetting::create(['knative_region_id' => $bare->id, 'allow_runc' => true]);
        $data = $this->postJson('/api/sandboxes', ['region' => 'bare'])->assertCreated()->json('data');
        $this->assertSame('runc', $data['sandbox_class']);
        $this->assertArrayNotHasKey('runtimeClassName', $this->podOf($data['id'])['spec']);
    }

    public function test_a_disabled_region_takes_no_sandboxes(): void
    {
        SandboxRegionSetting::create(['knative_region_id' => $this->region->id, 'enabled' => false]);

        $this->postJson('/api/sandboxes', ['region' => $this->region->id])->assertStatus(422)->assertJsonPath('reason', 'region_unavailable');
        $this->postJson('/api/sandboxes')->assertStatus(422)->assertJsonPath('reason', 'region_unavailable');
    }

    public function test_api_tokens_work(): void
    {
        $token = $this->user->createToken('agent')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/sandboxes', ['region' => $this->region->id])
            ->assertCreated();
    }

    public function test_guests_are_refused(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->withHeaders(['Authorization' => ''])->getJson('/api/sandboxes')->assertUnauthorized();
    }
}
