<?php

namespace Tests\Feature\Workflows;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Models\Version;
use App\Services\Environments\EnvironmentManager;
use App\Services\KnativeDeployService;
use App\Support\SchemaColumns;
use App\Workflows\Database\WorkflowEnvironmentSchema;
use App\Workflows\Engine\EndpointResolver;
use App\Workflows\Engine\EventIngestor;
use App\Workflows\Engine\RunStarter;
use App\Workflows\Engine\WorkflowRegistry;
use App\Workflows\Jobs\SyncWorkflowsJob;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowEvent;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowTrigger;
use App\Workflows\Support\WorkflowEnvironments;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\FakesRegionSecrets;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeStepTools;
use Tests\Support\Workflows\FakeWorkflowFunction;
use Tests\TestCase;

/**
 * Workflows per environment (docs/workflows.md#environments): every environment of a project is a
 * workflow app of its own — its key, its definitions, its runs against its own workload, its queue
 * bridge — and events reach the workflows of their environment only.
 */
class WorkflowEnvironmentsTest extends TestCase
{
    use FakesRegionSecrets;
    use RefreshDatabase;
    use RunsWorkflows;

    private KnativeRegion $region;

    private ProjectEnvironment $staging;

    private Project $stagingView;

    private FakeWorkflowFunction $stagingFn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();

        $this->region = KnativeRegion::create(['name' => 'eu', 'project_namespace' => 'eu-fn', 'is_active' => true]);
        $this->project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        $this->project = $this->project->fresh(['containerConfig']);

        $this->staging = app(EnvironmentManager::class)->create($this->project, ['name' => 'staging']);
        $this->stagingView = WorkflowEnvironments::view($this->project, $this->staging->id);

        // The staging workload answers at its own URL with its own key
        $app = WorkflowApp::forProject($this->stagingView);
        $app->forceFill(['endpoint_url' => 'https://staging.test/'])->save();
        $this->stagingFn = new FakeWorkflowFunction($app->signing_key);
        Http::fake(['staging.test/*' => fn ($request) => $this->stagingFn->handle($request)]);
    }

    /** Declare definitions in staging, served by the staging function. */
    private function defineStaging(string $name, \Closure $handler, array $definition = []): Workflow
    {
        $this->stagingFn->define($name, $handler, $definition);
        app(WorkflowRegistry::class)->declare($this->stagingView, array_map(
            fn (array $w) => $w['definition'],
            array_values($this->stagingFn->workflows),
        ));

        return Workflow::where('project_id', $this->project->id)->where('environment_id', $this->staging->id)->where('name', $name)->firstOrFail();
    }

    public function test_every_environment_is_a_workflow_app_with_a_key_of_its_own(): void
    {
        $production = WorkflowApp::forProject($this->project);
        $staging = WorkflowApp::forProject($this->stagingView);

        $this->assertNull($production->environment_id);
        $this->assertSame($this->staging->id, $staging->environment_id);
        $this->assertNotSame($production->id, $staging->id);
        $this->assertNotSame($production->signing_key, $staging->signing_key, 'staging code never holds production\'s key');
        $this->assertNotSame($production->queueHookToken(), $staging->queueHookToken());
        $this->assertSame($staging->id, WorkflowApp::forProject($this->stagingView)->id, 'one app per environment');
        $this->assertSame(2, WorkflowApp::where('project_id', $this->project->id)->count());
    }

    public function test_the_environment_s_workload_gets_its_own_key_and_queue_bridge(): void
    {
        config(['workflows.public_api_url' => 'https://console.runr.test']);
        $this->fakeRegionSecrets();
        $this->region->forceFill([
            'kubeconfig' => self::kubeconfigFor('https://k8s.test'),
            'redis_mode' => 'external', 'redis_address' => 'redis.eu:6379', 'knative_redis_status' => 'ready',
        ])->saveQuietly();
        $this->project->containerConfig->forceFill(['queue_stream' => 'orders'])->save();
        $project = $this->project->fresh(['containerConfig', 'knativeRegion']);
        $view = WorkflowEnvironments::view($project, $this->staging->id);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        $manifests = app(KnativeDeployService::class)->buildManifests($view, $version);

        $service = collect($manifests)->first(fn ($m) => $m['kind'] === 'Service' && $m['metadata']['name'] === $view->slug);
        $env = collect($service['spec']['template']['spec']['containers'][0]['env'] ?? [])->keyBy('name');
        $this->assertSame(
            ['name' => $view->slug.'-secret-env', 'key' => 'RUNR_WORKFLOW_SIGNING_KEY'],
            $env['RUNR_WORKFLOW_SIGNING_KEY']['valueFrom']['secretKeyRef'],
        );

        // The environment's queue consumer delivers to its own bridge
        $stagingApp = WorkflowApp::forProject($view);
        $secret = collect($manifests)->first(fn ($m) => $m['kind'] === 'Secret' && $m['metadata']['name'] === $view->slug.'-qc-redis');
        $this->assertSame(
            "https://console.runr.test/api/internal/workflows/queue/central/{$project->id}/{$this->staging->id}/{$stagingApp->queueHookToken()}",
            base64_decode($secret['data']['SINK_URL']),
        );
        $bridge = collect($manifests)->firstWhere('kind', 'Deployment');
        $this->assertSame('orders-staging', collect($bridge['spec']['template']['spec']['containers'][0]['env'])->keyBy('name')['STREAM']['value']);

        // Its own key in its own Secret; production's Secret holds production's key
        app(KnativeDeployService::class)->prepareSecrets($view);
        app(KnativeDeployService::class)->prepareSecrets($project);
        $this->assertSame($stagingApp->signing_key, $this->regionSecrets->values($project->knativeRegion, $view->slug.'-secret-env')['RUNR_WORKFLOW_SIGNING_KEY'] ?? null);
        $this->assertSame(
            WorkflowApp::forProject($project)->signing_key,
            $this->regionSecrets->values($project->knativeRegion, $project->slug.'-secret-env')['RUNR_WORKFLOW_SIGNING_KEY'] ?? null,
        );
    }

    public function test_the_environment_s_function_url_is_its_workload_s(): void
    {
        $this->region->forceFill(['knative_settings' => ['knative_domain' => 'eu.fn.test']])->saveQuietly();
        $project = $this->project->fresh();
        $view = WorkflowEnvironments::view($project, $this->staging->id);

        $this->assertSame("https://{$project->slug}.eu.fn.test", app(EndpointResolver::class)->functionUrl($project));
        $this->assertSame("https://{$this->staging->workload_name}.eu.fn.test", app(EndpointResolver::class)->functionUrl($view));
    }

    public function test_definitions_are_synced_and_declared_per_environment(): void
    {
        $this->fn->define('orders', fn () => 1, ['triggers' => [['event' => 'order.created']]]);
        $this->stagingFn->define('orders', fn () => 2, ['triggers' => [['event' => 'order.created']]]);
        $this->stagingFn->define('experiment', fn () => 3);

        app(WorkflowRegistry::class)->sync($this->project);
        app(WorkflowRegistry::class)->sync($this->stagingView);

        $this->assertSame(['orders'], Workflow::where('project_id', $this->project->id)->whereNull('environment_id')->pluck('name')->all());
        $this->assertSame(['experiment', 'orders'], Workflow::where('environment_id', $this->staging->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame('introspect', $this->stagingFn->lastRequest()->type, 'staging\'s definitions come from staging\'s function');
        $this->assertSame('ok', WorkflowApp::forProject($this->stagingView)->sync_status);

        $stagingOrders = Workflow::where('environment_id', $this->staging->id)->where('name', 'orders')->firstOrFail();
        $this->assertSame('staging', $stagingOrders->environmentName());
        $this->assertSame(['staging'], WorkflowTrigger::where('workflow_id', $stagingOrders->id)->pluck('environment')->all());

        // A declaration replaces staging's set only: production keeps its workflows
        app(WorkflowRegistry::class)->declare($this->stagingView, [['name' => 'only-staging']]);
        $this->assertNotNull($stagingOrders->fresh()->archived_at);
        $this->assertNull(Workflow::whereNull('environment_id')->where('name', 'orders')->firstOrFail()->archived_at);
    }

    public function test_a_run_executes_against_the_environment_s_workload_with_its_key(): void
    {
        $production = $this->defineWorkflow('greet', fn (FakeStepTools $step) => $step->run('who', fn () => 'production'));
        $staging = $this->defineStaging('greet', fn (FakeStepTools $step) => $step->run('who', fn () => 'staging'));

        $stagingRun = $this->startRun($staging);
        $productionRun = $this->startRun($production);
        $this->drain();

        $this->assertSame(WorkflowRun::COMPLETED, $stagingRun->fresh()->status, json_encode($stagingRun->fresh()->error));
        $this->assertSame('staging', $stagingRun->fresh()->output);
        $this->assertSame('production', $productionRun->fresh()->output);
        $this->assertSame($this->staging->id, $stagingRun->fresh()->environment_id);
        $this->assertSame('staging', $stagingRun->fresh()->environment);
        $this->assertNull($productionRun->fresh()->environment_id);
        $this->assertCount(2, $this->stagingFn->executeRequests(), 'staging\'s run went to staging\'s function, signed with its key');
        $this->assertCount(2, $this->fn->executeRequests());
    }

    public function test_a_run_whose_environment_is_gone_fails_without_retrying(): void
    {
        $workflow = $this->defineStaging('greet', fn () => 'hi');
        $run = $this->startRun($workflow);
        // The environment row goes without its cleanup (as if deleted elsewhere)
        ProjectEnvironment::query()->whereKey($this->staging->id)->delete();

        $this->drain();

        $this->assertSame(WorkflowRun::FAILED, $run->fresh()->status);
        $this->assertSame('EnvironmentMissing', $run->fresh()->error['name']);
    }

    public function test_events_reach_the_workflows_of_their_environment_only(): void
    {
        $production = $this->defineWorkflow('on-order', fn () => 'p', ['triggers' => [['event' => 'order.created']]]);
        $staging = $this->defineStaging('on-order', fn () => 's', ['triggers' => [['event' => 'order.created']]]);
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->postJson('/api/events', ['name' => 'order.created', 'data' => ['id' => 1]])
            ->assertOk()->assertJsonPath('events.0.environment', null);
        $this->assertSame(1, WorkflowRun::where('workflow_id', $production->id)->count());
        $this->assertSame(0, WorkflowRun::where('workflow_id', $staging->id)->count());

        $this->postJson('/api/events', ['name' => 'order.created', 'data' => ['id' => 2], 'environment' => 'staging'])
            ->assertOk()->assertJsonPath('events.0.environment', 'staging');
        $this->assertSame(1, WorkflowRun::where('workflow_id', $staging->id)->count());
        $this->assertSame(1, WorkflowRun::where('workflow_id', $production->id)->count());

        // A list of events takes ?environment=, an event's own wins
        $this->postJson('/api/events?environment=staging', [
            ['name' => 'order.created', 'data' => ['id' => 3]],
            ['name' => 'order.created', 'data' => ['id' => 4], 'environment' => 'production'],
        ])->assertOk();
        $this->assertSame(2, WorkflowRun::where('workflow_id', $staging->id)->count());
        $this->assertSame(2, WorkflowRun::where('workflow_id', $production->id)->count());
        $this->assertSame(2, WorkflowEvent::where('environment', 'staging')->count());
        $this->assertSame(2, WorkflowEvent::whereNull('environment')->count());

        $this->getJson('/api/events?environment=staging')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/events', ['name' => 'order.created', 'environment' => 'Not A Name'])
            ->assertStatus(422)->assertJsonPath('code', 'event_environment_invalid');
    }

    public function test_waits_and_cancellations_follow_the_environment(): void
    {
        $waiter = fn (FakeStepTools $step) => $step->waitForEvent('shipped', 'order.shipped', timeout: '1d') !== null;
        $production = $this->defineWorkflow('ship', $waiter);
        $staging = $this->defineStaging('ship', $waiter, ['cancel_on' => [['event' => 'order.cancelled']]]);
        $productionRun = $this->startRun($production);
        $stagingRun = $this->startRun($staging);
        $this->drain();
        $this->assertSame(WorkflowRun::WAITING, $stagingRun->fresh()->status);

        app(EventIngestor::class)->ingest([['name' => 'order.shipped']], 'api', null, null, 'staging');
        $this->drain();
        $this->assertSame(WorkflowRun::COMPLETED, $stagingRun->fresh()->status);
        $this->assertSame(WorkflowRun::WAITING, $productionRun->fresh()->status, 'staging\'s event does not resume production');

        // production's cancel event does not cancel staging's runs
        $other = $this->startRun($staging);
        $this->drain();
        app(EventIngestor::class)->ingest([['name' => 'order.cancelled']], 'api');
        $this->assertSame(WorkflowRun::WAITING, $other->fresh()->status);
        app(EventIngestor::class)->ingest([['name' => 'order.cancelled']], 'api', null, null, 'staging');
        $this->assertSame(WorkflowRun::CANCELLED, $other->fresh()->status);
    }

    public function test_events_a_run_sends_stay_in_its_environment(): void
    {
        $this->defineStaging('announce', fn (FakeStepTools $step) => $step->sendEvent('done', [['name' => 'job.done', 'environment' => 'production']]));
        $listener = $this->defineStaging('listener', fn () => 'heard', ['triggers' => [['event' => 'job.done']]]);
        $production = $this->defineWorkflow('listener', fn () => 'heard', ['triggers' => [['event' => 'job.done']]]);

        app(RunStarter::class)->start(Workflow::where('environment_id', $this->staging->id)->where('name', 'announce')->firstOrFail(), ['name' => 'go']);
        $this->drain();

        $this->assertSame(1, WorkflowRun::where('workflow_id', $listener->id)->count(), 'a staging run\'s event starts staging\'s workflows');
        $this->assertSame(0, WorkflowRun::where('workflow_id', $production->id)->count(), 'and never production\'s, whatever it names');
        $this->assertSame('staging', WorkflowEvent::where('name', 'job.done')->sole()->environment);
    }

    public function test_invoke_calls_the_workflow_of_the_same_environment(): void
    {
        $this->defineWorkflow('child', fn () => 'production child');
        $this->defineStaging('child', fn () => 'staging child');
        $parent = $this->defineStaging('parent', fn (FakeStepTools $step) => $step->invoke('call', 'child'));

        $run = $this->startRun($parent);
        $this->drain();

        $this->assertSame('staging child', $run->fresh()->output);
    }

    public function test_the_queue_bridge_of_an_environment_delivers_events_of_that_environment(): void
    {
        $this->project->containerConfig->forceFill(['queue_stream' => 'orders'])->save();
        $workflow = $this->defineStaging('from-queue', fn (FakeStepTools $step, object $event) => $event->data->sku, ['triggers' => [['event' => 'queue/orders-staging']]]);
        $app = WorkflowApp::forProject($this->stagingView);
        $url = "/api/internal/workflows/queue/central/{$this->project->id}/{$this->staging->id}/{$app->queueHookToken()}";

        $this->postJson($url, ['data' => json_encode(['sku' => 'S-1'])], ['ce-id' => '1-0'])->assertOk();
        $this->drain();

        $event = WorkflowEvent::sole();
        $this->assertSame('queue/orders-staging', $event->name, 'the environment\'s own stream');
        $this->assertSame('staging', $event->environment);
        $this->assertSame('S-1', WorkflowRun::where('workflow_id', $workflow->id)->sole()->output);

        // production's token, or the environment's on production's URL, is refused
        $production = WorkflowApp::forProject($this->project);
        $this->postJson("/api/internal/workflows/queue/central/{$this->project->id}/{$this->staging->id}/{$production->queueHookToken()}", ['a' => 1])->assertNotFound();
        $this->postJson("/api/internal/workflows/queue/central/{$this->project->id}/{$app->queueHookToken()}", ['a' => 1])->assertNotFound();
    }

    public function test_an_environment_deployment_going_active_syncs_that_environment(): void
    {
        Queue::fake();
        $version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::query()->create([
            'project_id' => $this->project->id, 'version_id' => $version->id, 'environment_id' => $this->staging->id,
            'status' => 'pending', 'k8s_job_name' => 'run', 'deployed_by' => 'dev',
        ]);

        $deployment->update(['status' => 'active']);

        Queue::assertPushed(SyncWorkflowsJob::class, fn (SyncWorkflowsJob $job) => $job->projectId === $this->project->id && $job->environmentId === $this->staging->id);

        $this->stagingFn->define('from-deploy', fn () => 1);
        (new SyncWorkflowsJob($this->project->id, $this->staging->id))->handle(app(WorkflowRegistry::class));
        $this->assertTrue(Workflow::where('environment_id', $this->staging->id)->where('name', 'from-deploy')->exists());
        $this->assertFalse(Workflow::whereNull('environment_id')->where('name', 'from-deploy')->exists());
    }

    public function test_deleting_an_environment_removes_its_workflows(): void
    {
        $staging = $this->defineStaging('greet', fn () => 'hi');
        $production = $this->defineWorkflow('greet', fn () => 'hi');
        $this->startRun($staging);
        $this->drain();

        app(EnvironmentManager::class)->delete($this->staging);

        $this->assertNull(Workflow::find($staging->id));
        $this->assertSame(0, WorkflowRun::where('workflow_id', $staging->id)->count());
        $this->assertSame(0, WorkflowApp::where('environment_id', $this->staging->id)->count());
        $this->assertNotNull(Workflow::find($production->id));
        $this->assertNotNull(WorkflowApp::findFor($this->project->id, null));
    }

    public function test_the_api_addresses_one_environment(): void
    {
        $this->defineWorkflow('greet', fn () => 'production');
        $this->defineStaging('greet', fn () => 'staging');
        $this->defineStaging('extra', fn () => 'x');
        $this->actingAs(User::factory()->create(), 'sanctum');
        $base = "/api/projects/{$this->project->id}";

        $this->getJson("{$base}/workflows")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.environment', 'production')->assertJsonPath('app.environment', 'production');
        $this->getJson("{$base}/workflows?environment=staging")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.environment', 'staging')
            ->assertJsonPath('app.environment', 'staging')
            ->assertJsonPath('app.key_fingerprint', WorkflowApp::forProject($this->stagingView)->keyFingerprint());
        $this->getJson("{$base}/workflows/extra")->assertNotFound();
        $this->getJson("{$base}/workflows/extra?environment={$this->staging->id}")->assertOk();
        $this->getJson("{$base}/workflows?environment=nope")->assertStatus(422)->assertJsonPath('code', 'environment_not_found');

        $this->postJson("{$base}/workflows/greet/runs", ['environment' => 'staging'])->assertCreated()
            ->assertJsonPath('data.environment', 'staging');
        $this->postJson("{$base}/workflows/greet/runs")->assertCreated()->assertJsonPath('data.environment', 'production');
        $this->drain();

        $this->getJson("{$base}/workflow-runs")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.environment', 'production');
        $this->getJson("{$base}/workflow-runs?environment=staging")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.environment', 'staging');
        $this->getJson("{$base}/workflow-runs?environment=all")->assertOk()->assertJsonCount(2, 'data');

        $this->patchJson("{$base}/workflows/greet", ['is_active' => false, 'environment' => 'staging'])->assertOk();
        $this->assertFalse(Workflow::where('environment_id', $this->staging->id)->where('name', 'greet')->sole()->is_active);
        $this->assertTrue(Workflow::whereNull('environment_id')->where('name', 'greet')->sole()->is_active);

        $before = WorkflowApp::forProject($this->stagingView)->signing_key;
        $this->postJson("{$base}/workflows/app/rotate-key", ['environment' => 'staging'])->assertOk();
        $this->assertNotSame($before, WorkflowApp::forProject($this->stagingView)->signing_key);
        $this->getJson("{$base}/workflows/app/signing-key?environment=staging")->assertOk()
            ->assertJsonPath('signing_key', WorkflowApp::forProject($this->stagingView)->signing_key);

        $usage = $this->getJson("{$base}/workflow-usage")->assertOk();
        $this->assertSame(2, $usage->json('totals.runs_started'), 'the project\'s usage covers every environment');
        $this->assertSame(1, $this->getJson("{$base}/workflow-usage?environment=staging")->json('totals.runs_started'));
        $this->assertEqualsCanonicalizing(['production', 'staging'], array_column($usage->json('by_workflow'), 'environment'));
    }

    public function test_names_are_unique_per_project_and_environment(): void
    {
        Workflow::create(['project_id' => $this->project->id, 'name' => 'same']);
        Workflow::create(['project_id' => $this->project->id, 'environment_id' => $this->staging->id, 'name' => 'same']);

        try {
            Workflow::create(['project_id' => $this->project->id, 'name' => 'same']);
            $this->fail('a second production workflow of the name was stored');
        } catch (QueryException) {
            $this->assertSame(1, Workflow::whereNull('environment_id')->where('name', 'same')->count());
        }

        $this->expectException(QueryException::class);
        WorkflowApp::create(['project_id' => $this->project->id, 'signing_key' => WorkflowApp::generateKey()]);
    }

    public function test_the_migration_rolls_back_and_runs_again(): void
    {
        $this->defineStaging('greet', fn () => 'hi');
        $this->defineWorkflow('greet', fn () => 'hi');

        WorkflowEnvironmentSchema::down();
        SchemaColumns::flush();

        $this->assertFalse(Schema::hasColumn('workflows', 'environment_id'));
        $this->assertFalse(Schema::hasColumn('workflow_events', 'environment'));
        $this->assertSame(1, Workflow::count(), 'the environment\'s rows go, production\'s stay');
        $this->assertTrue(Schema::hasIndex('workflow_apps', 'workflow_apps_project_id_unique'));

        WorkflowEnvironmentSchema::up();
        SchemaColumns::flush();

        $this->assertTrue(Schema::hasColumn('workflow_runs', 'environment_id'));
        $this->assertTrue(Schema::hasIndex('workflows', 'workflows_production_name_unique'));
        $this->assertFalse(Schema::hasIndex('workflow_apps', 'workflow_apps_project_id_unique'));
        WorkflowEnvironmentSchema::up(); // idempotent
    }
}
