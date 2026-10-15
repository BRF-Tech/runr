<?php

namespace Tests\Feature\Workflows;

use App\Models\KnativeRegion;
use App\Models\Version;
use App\Services\KnativeDeployService;
use App\Workflows\Engine\Signature;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowEvent;
use App\Workflows\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

/**
 * Signed requests: only the engine (holding the project's signing key) drives a function.
 * Covers the request signature, a function rejecting a wrong key, key rotation with a grace
 * period, the signing key in the deployed Knative Service, and the signed queue bridge.
 */
class WorkflowHmacTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
    }

    public function test_every_engine_request_is_signed_with_the_project_key(): void
    {
        $workflow = $this->defineWorkflow('signed', fn () => 'ok');
        $run = $this->startRun($workflow);
        $this->drain();

        $key = WorkflowApp::where('project_id', $this->project->id)->firstOrFail()->signing_key;
        $this->assertStringStartsWith('rwk_', $key);

        $recorded = Http::recorded(fn (Request $request) => str_contains($request->url(), 'fn.test'));
        $this->assertCount(1, $recorded);

        foreach ($recorded as [$request]) {
            $envelope = json_decode($request->body(), true);
            $header = $request->header('X-RunR-Signature')[0];

            $this->assertSame('v1', $envelope['runr_workflow']);
            $this->assertSame($header, $envelope['signature']);
            $this->assertSame('v1', $request->header('X-RunR-Workflow-Protocol')[0]);
            $this->assertSame($run->id, $request->header('X-RunR-Run-Id')[0]);
            $this->assertSame('signed', $request->header('X-RunR-Workflow')[0]);
            $this->assertTrue(Signature::verify($envelope['request'], $header, [$key]));
            $this->assertFalse(Signature::verify($envelope['request'], $header, ['rwk_someone_else']));
        }
    }

    public function test_a_function_with_another_key_rejects_the_engine_and_the_run_fails(): void
    {
        $workflow = $this->defineWorkflow('mismatch', fn () => 'never', ['retries' => 1]);
        $this->fn->signingKey = 'rwk_not_the_project_key';

        $run = $this->startRun($workflow);
        $this->drain();
        $this->assertSame('SignatureRejected', $run->fresh()->error['name']);

        $this->travel(3)->seconds();
        $this->drain();

        $run->refresh();
        $this->assertSame(WorkflowRun::FAILED, $run->status);
        $this->assertSame('SignatureRejected', $run->error['name']);
        $this->assertCount(0, $this->fn->requests, 'the function never accepted a request');
    }

    public function test_rotation_keeps_the_previous_key_valid_during_the_grace_period(): void
    {
        config(['workflows.key_rotation_grace' => 3600]);
        $workflow = $this->defineWorkflow('rotating', fn () => 'ok');
        $app = WorkflowApp::forProject($this->project);
        $old = $app->signing_key;

        $app->rotateKey();
        $app->refresh();
        $this->assertNotSame($old, $app->signing_key);
        $this->assertSame([$app->signing_key, $old], $app->signingKeys());

        // Function still deployed with the old key
        $run = $this->startRun($workflow);
        $this->drain();
        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($run));

        // After the grace period only the new key signs
        $this->travel(3601)->seconds();
        $this->assertSame([$app->signing_key], $app->fresh()->signingKeys());
        $late = $this->startRun($workflow);
        $this->drain();
        $this->assertSame('SignatureRejected', $late->fresh()->error['name']);
    }

    public function test_the_signing_key_is_hidden_from_serialization_and_encrypted_at_rest(): void
    {
        $app = WorkflowApp::forProject($this->project);

        $this->assertArrayNotHasKey('signing_key', $app->toArray());
        $raw = DB::table('workflow_apps')->where('id', $app->id)->value('signing_key');
        $this->assertNotSame($app->signing_key, $raw);
        $this->assertSame(12, strlen($app->keyFingerprint()));
    }

    // ── Deploy ─────────────────────────────────────────────────────────────

    private function deployableProject(?string $queueStream, bool $redis = true): array
    {
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true]);
        if ($redis) {
            $region->forceFill(['redis_mode' => 'external', 'redis_address' => 'redis.fn:6379', 'knative_redis_status' => 'ready'])->saveQuietly();
        }

        $project = $this->makeWorkflowProject('wf-deploy', $queueStream);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        return [$project->fresh(), $version];
    }

    public function test_deploy_injects_the_signing_key_into_the_knative_service(): void
    {
        [$project, $version] = $this->deployableProject(null);
        $manifests = app(KnativeDeployService::class)->buildManifests($project, $version);

        $kinds = array_column($manifests, 'kind');
        $this->assertContains('Service', $kinds);
        $this->assertNotContains('Deployment', $kinds, 'no queue bridge without a stream');

        $service = collect($manifests)->firstWhere('kind', 'Service');
        $env = collect($service['spec']['template']['spec']['containers'][0]['env'] ?? [])->keyBy('name');

        // The key comes from the project's secret env Secret, never as a value in the spec
        $this->assertArrayNotHasKey('value', $env['RUNR_WORKFLOW_SIGNING_KEY']);
        $this->assertSame(
            ['name' => $project->slug.'-secret-env', 'key' => 'RUNR_WORKFLOW_SIGNING_KEY'],
            $env['RUNR_WORKFLOW_SIGNING_KEY']['valueFrom']['secretKeyRef'],
        );
        $secret = collect($manifests)->first(fn ($m) => $m['kind'] === 'Secret' && $m['metadata']['name'] === $project->slug.'-secret-env');
        $this->assertNotNull($secret);
        $this->assertSame(WorkflowApp::forProject($project)->signing_key, base64_decode($secret['data']['RUNR_WORKFLOW_SIGNING_KEY']));
        $this->assertStringNotContainsString(WorkflowApp::forProject($project)->signing_key, json_encode($service));
        $this->assertSame('v1', $env['RUNR_WORKFLOW_PROTOCOL']['value']);
        $this->assertArrayNotHasKey('networking.knative.dev/visibility', $service['metadata']['labels'], 'the engine reaches the function over its public URL');
    }

    public function test_deploy_builds_the_queue_bridge_with_a_signed_sink(): void
    {
        config(['workflows.public_api_url' => 'https://console.runr.test']);
        [$project, $version] = $this->deployableProject('orders');

        $manifests = app(KnativeDeployService::class)->buildManifests($project, $version);
        $bridge = collect($manifests)->firstWhere('kind', 'Deployment');

        $this->assertNotNull($bridge);
        $this->assertSame($project->slug.'-qc', $bridge['metadata']['name']);
        $this->assertSame('workflow-queue-bridge', $bridge['metadata']['labels']['runr/component-mode']);

        $env = collect($bridge['spec']['template']['spec']['containers'][0]['env'])->keyBy('name');
        $app = WorkflowApp::forProject($project);

        // The sink URL carries the hook token: it comes from the consumer's Secret, applied first
        $secretName = $project->slug.'-qc-redis';
        $this->assertSame(['name' => $secretName, 'key' => 'SINK_URL'], $env['SINK_URL']['valueFrom']['secretKeyRef']);
        $this->assertStringNotContainsString($app->queueHookToken(), json_encode($bridge));
        $secretAt = collect($manifests)->search(fn ($m) => $m['kind'] === 'Secret' && $m['metadata']['name'] === $secretName);
        $this->assertIsInt($secretAt);
        $this->assertLessThan(collect($manifests)->search(fn ($m) => $m['kind'] === 'Deployment'), $secretAt);
        $this->assertSame(
            "https://console.runr.test/api/internal/workflows/queue/central/{$project->id}/{$app->queueHookToken()}",
            base64_decode($manifests[$secretAt]['data']['SINK_URL']),
        );
        $this->assertSame('redis.fn:6379', preg_replace('#^redis://#', '', base64_decode($manifests[$secretAt]['data']['REDIS_ADDRESS'])));
        $this->assertSame('orders', $env['STREAM']['value']);
    }

    public function test_no_queue_bridge_without_redis(): void
    {
        [$project, $version] = $this->deployableProject('orders', redis: false);

        $kinds = array_column(app(KnativeDeployService::class)->buildManifests($project, $version), 'kind');
        $this->assertNotContains('Deployment', $kinds);
    }

    // ── Queue bridge sink ──────────────────────────────────────────────────

    public function test_queue_hook_ingests_messages_and_starts_queue_triggered_workflows(): void
    {
        $project = $this->makeWorkflowProject('wf-queue', 'orders');
        $app = WorkflowApp::forProject($project);
        $this->project = $project;
        $app->forceFill(['endpoint_url' => 'https://fn.test/'])->save();
        $this->fn->signingKey = $app->signing_key;

        $workflow = $this->defineWorkflow('from-queue', fn ($step, $event) => $event->data->sku, ['triggers' => [['queue' => 'orders']]]);

        $url = "/api/internal/workflows/queue/central/{$project->id}/{$app->queueHookToken()}";

        $this->postJson($url, ['data' => json_encode(['sku' => 'A-1'])], ['ce-id' => '1700000000000-0'])
            ->assertOk()
            ->assertJsonCount(1, 'ids');

        // Redelivery of the same stream entry is deduplicated
        $this->postJson($url, ['data' => json_encode(['sku' => 'A-1'])], ['ce-id' => '1700000000000-0'])->assertOk();

        $event = WorkflowEvent::firstOrFail();
        $this->assertSame('queue/orders', $event->name);
        $this->assertSame('queue', $event->source);
        $this->assertSame('A-1', $event->data->sku);
        $this->assertSame(1, WorkflowEvent::count());

        $run = WorkflowRun::where('workflow_id', $workflow->id)->sole();
        $this->assertSame('queue', $run->trigger_source);
        $this->drain();
        $this->assertSame('A-1', $run->fresh()->output);
    }

    public function test_queue_hook_rejects_wrong_tokens_contexts_and_projects(): void
    {
        $project = $this->makeWorkflowProject('wf-queue2', 'orders');
        $app = WorkflowApp::forProject($project);
        $token = $app->queueHookToken();

        $this->postJson("/api/internal/workflows/queue/central/{$project->id}/".str_repeat('0', 40), ['a' => 1])->assertNotFound();
        $this->postJson("/api/internal/workflows/queue/othertenant/{$project->id}/{$token}", ['a' => 1])->assertNotFound();
        $this->postJson('/api/internal/workflows/queue/central/01JAAAAAAAAAAAAAAAAAAAAAAA/'.$token, ['a' => 1])->assertNotFound();
        $this->assertSame(0, WorkflowEvent::count());

        // Rotating the key invalidates the old sink URL
        $app->rotateKey();
        $this->postJson("/api/internal/workflows/queue/central/{$project->id}/{$token}", ['a' => 1])->assertNotFound();
    }
}
