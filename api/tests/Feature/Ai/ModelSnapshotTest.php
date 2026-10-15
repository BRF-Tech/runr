<?php

namespace Tests\Feature\Ai;

use App\Jobs\Ai\CheckModelSnapshotJob;
use App\Jobs\Ai\DeployModelServiceJob;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\User;
use App\Services\Ai\Models\ModelServiceDeployer;
use App\Services\Ai\Models\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Offline copies ("closed circuit"): a mirror Job is created in the region (model volume and token
 * Secret first), its status and its RUNR_SNAPSHOT / RUNR_ERROR log line make the snapshot ready
 * (size, file count, resolved commit) or failed, offline services wait for it and deploy when it
 * is ready; deleting runs a cleanup Job.
 */
class ModelSnapshotTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function region()
    {
        return $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()]);
    }

    public function test_an_offline_deploy_without_snapshot_starts_the_mirror_and_waits(): void
    {
        $this->fakeCluster();
        $region = $this->region();
        $model = $this->hubModel();
        $service = $this->modelService($model, $region, ['mode' => 'offline', 'engine' => 'transformers', 'status' => AiModelService::STATUS_DEPLOYING]);

        app(ModelServiceDeployer::class)->deploy($service);

        $service->refresh();
        $this->assertSame(AiModelService::STATUS_WAITING_SNAPSHOT, $service->status);
        $snapshot = AiModelSnapshot::findOrFail($service->snapshot_id);
        $this->assertSame(AiModelSnapshot::STATUS_RUNNING, $snapshot->status);
        $this->assertSame('snapshots/default/all-minilm-l6-v2/'.str_repeat('a', 40), $snapshot->path);

        $this->assertNotEmpty($this->k8sCallsTo('POST', '/namespaces/fn/persistentvolumeclaims'), 'model volume created');
        $jobs = $this->k8sCallsTo('POST', '/namespaces/fn/jobs');
        $this->assertCount(1, $jobs);
        $this->assertSame('model-mirror-'.strtolower($snapshot->id), $jobs[0]['body']['metadata']['name']);
        $this->assertEmpty($this->k8sCallsTo('PATCH', '/services/'.$service->service_name), 'no Knative Service before the snapshot');
        Queue::assertPushed(CheckModelSnapshotJob::class);
    }

    public function test_a_completed_job_makes_the_snapshot_ready_and_deploys_waiting_services(): void
    {
        $region = $this->region();
        $model = $this->hubModel(['revision_sha' => null, 'revision' => 'main']);
        $snapshot = AiModelSnapshot::create([
            'ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'source' => 'hub', 'revision' => 'main',
            'path' => 'snapshots/default/all-minilm-l6-v2/main', 'status' => 'running', 'job_name' => 'model-mirror-x',
        ]);
        $waiting = $this->modelService($model, $region, ['mode' => 'offline', 'status' => AiModelService::STATUS_WAITING_SNAPSHOT]);

        $this->fakeCluster([
            'GET /apis/batch/v1/namespaces/fn/jobs/model-mirror-x' => ['status' => ['succeeded' => 1, 'conditions' => [['type' => 'Complete', 'status' => 'True']]]],
            'GET /api/v1/namespaces/fn/pods' => ['items' => [['metadata' => ['name' => 'model-mirror-x-abc', 'creationTimestamp' => '2026-10-11T10:00:00Z'], 'spec' => ['containers' => [['name' => 'mirror']]]]]],
            'GET /api/v1/namespaces/fn/pods/model-mirror-x-abc/log' => fn () => Http::response("Fetching 6 files\nRUNR_SNAPSHOT ".json_encode(['repo' => 'x', 'revision' => str_repeat('d', 40), 'size_bytes' => 91_000_000, 'files' => [['path' => 'a', 'size' => 1], ['path' => 'b', 'size' => 2]]])."\n"),
        ]);

        (new CheckModelSnapshotJob($snapshot->id))->handle(app(SnapshotService::class), app(ModelServiceDeployer::class));

        $snapshot->refresh();
        $this->assertSame(AiModelSnapshot::STATUS_READY, $snapshot->status);
        $this->assertSame(91_000_000, $snapshot->size_bytes);
        $this->assertSame(2, $snapshot->files_count);
        $this->assertSame(str_repeat('d', 40), $model->fresh()->revision_sha, 'the commit main resolved to');
        Queue::assertPushed(DeployModelServiceJob::class, fn ($job) => $job->serviceId === $waiting->id);
    }

    public function test_a_failed_job_fails_the_snapshot_and_its_waiting_services(): void
    {
        $region = $this->region();
        $model = $this->hubModel();
        $snapshot = AiModelSnapshot::create([
            'ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'source' => 'hub', 'revision' => str_repeat('a', 40),
            'path' => 'snapshots/default/x/a', 'status' => 'running', 'job_name' => 'model-mirror-y',
        ]);
        $waiting = $this->modelService($model, $region, ['mode' => 'offline', 'status' => AiModelService::STATUS_WAITING_SNAPSHOT]);

        $this->fakeCluster([
            'GET /apis/batch/v1/namespaces/fn/jobs/model-mirror-y' => ['status' => ['failed' => 2, 'conditions' => [['type' => 'Failed', 'status' => 'True']]]],
            'GET /api/v1/namespaces/fn/pods' => ['items' => [['metadata' => ['name' => 'model-mirror-y-1'], 'spec' => ['containers' => [['name' => 'mirror']]]]]],
            'GET /api/v1/namespaces/fn/pods/model-mirror-y-1/log' => fn () => Http::response("RUNR_ERROR 401 Client Error: gated repo\n"),
        ]);

        (new CheckModelSnapshotJob($snapshot->id))->handle(app(SnapshotService::class), app(ModelServiceDeployer::class));

        $this->assertSame(AiModelSnapshot::STATUS_FAILED, $snapshot->fresh()->status);
        $this->assertSame('401 Client Error: gated repo', $snapshot->fresh()->message);
        $this->assertSame(AiModelService::STATUS_FAILED, $waiting->fresh()->status);
        Queue::assertNotPushed(DeployModelServiceJob::class);
    }

    public function test_a_running_job_is_polled_again(): void
    {
        $region = $this->region();
        $snapshot = AiModelSnapshot::create([
            'ai_model_id' => $this->hubModel()->id, 'knative_region_id' => $region->id, 'source' => 'hub', 'revision' => 'main',
            'path' => 'snapshots/default/x/main', 'status' => 'running', 'job_name' => 'model-mirror-z',
        ]);
        $this->fakeCluster(['GET /apis/batch/v1/namespaces/fn/jobs/model-mirror-z' => ['status' => ['active' => 1]]]);

        (new CheckModelSnapshotJob($snapshot->id, 3))->handle(app(SnapshotService::class), app(ModelServiceDeployer::class));

        $this->assertSame(AiModelSnapshot::STATUS_RUNNING, $snapshot->fresh()->status);
        Queue::assertPushed(CheckModelSnapshotJob::class, fn ($job) => $job->attempt === 4);
    }

    /** A mirror Job of the region whose pod has not started (no condition yet). */
    private function runningSnapshot($region, $model, string $job): AiModelSnapshot
    {
        return AiModelSnapshot::create([
            'ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'source' => 'hub', 'revision' => str_repeat('a', 40),
            'path' => 'snapshots/default/x/a', 'status' => 'running', 'job_name' => $job, 'started_at' => now()->subMinutes(2),
        ]);
    }

    /** @var array{phase: string, events: list<array>} The model volume claim the fake cluster answers with. */
    private array $claim = ['phase' => 'Pending', 'events' => []];

    /**
     * A cluster whose Job has not finished and whose model volume claim is in $claim's phase,
     * with $claim's events (oldest first, as the API lists them). Http::fake keeps its first
     * handler: a test changes $claim, not the fake.
     */
    private function fakeVolume(string $job, string $phase, array $events): void
    {
        $this->claim = ['phase' => $phase, 'events' => $events];
        $this->fakeCluster([
            "GET /apis/batch/v1/namespaces/fn/jobs/{$job}" => ['status' => ['active' => 1]],
            'GET /api/v1/namespaces/fn/persistentvolumeclaims/runr-models' => fn () => [
                'metadata' => ['name' => 'runr-models'],
                'spec' => ['accessModes' => ['ReadWriteMany'], 'storageClassName' => 'local-path', 'resources' => ['requests' => ['storage' => '100Gi']]],
                'status' => ['phase' => $this->claim['phase']],
            ],
            'GET /api/v1/namespaces/fn/events' => fn () => ['items' => $this->claim['events']],
        ]);
    }

    private function pvcEvent(string $type, string $reason, string $message, string $at): array
    {
        return [
            'type' => $type, 'reason' => $reason, 'message' => $message, 'lastTimestamp' => $at,
            'involvedObject' => ['kind' => 'PersistentVolumeClaim', 'name' => 'runr-models'],
        ];
    }

    public function test_a_job_waiting_for_a_pending_model_volume_makes_the_snapshot_pending_with_the_reason(): void
    {
        $region = $this->region();
        $model = $this->hubModel();
        $snapshot = $this->runningSnapshot($region, $model, 'model-mirror-v');
        $waiting = $this->modelService($model, $region, ['mode' => 'offline', 'status' => AiModelService::STATUS_WAITING_SNAPSHOT]);

        // local-path can not provision ReadWriteMany: the claim stays Pending, the Job's pod with it
        $provisioning = 'failed to provision volume with StorageClass "local-path": NodePath only supports ReadWriteOnce and ReadWriteOncePod (1.22+) access modes';
        $this->fakeVolume('model-mirror-v', 'Pending', [
            $this->pvcEvent('Normal', 'WaitForFirstConsumer', 'waiting for first consumer to be created before binding', '2026-10-11T10:00:00Z'),
            $this->pvcEvent('Warning', 'ProvisioningFailed', $provisioning, '2026-10-11T10:00:05Z'),
            $this->pvcEvent('Normal', 'ExternalProvisioning', 'Waiting for a volume to be created by the external provisioner', '2026-10-11T10:00:06Z'),
        ]);

        (new CheckModelSnapshotJob($snapshot->id, 3))->handle(app(SnapshotService::class), app(ModelServiceDeployer::class));

        $snapshot->refresh();
        $this->assertSame(AiModelSnapshot::STATUS_PENDING, $snapshot->status, 'not "running" while no pod of the Job can start');
        $this->assertSame("The model volume runr-models is Pending: {$provisioning}", $snapshot->message, 'the newest Warning of the claim');
        $events = $this->k8sCallsTo('GET', '/namespaces/fn/events');
        $this->assertSame('fieldSelector='.urlencode('involvedObject.kind=PersistentVolumeClaim,involvedObject.name=runr-models'), $events[0]['query']);
        $this->assertStringContainsString($provisioning, (string) $waiting->fresh()->status_message);
        $this->assertSame(AiModelService::STATUS_WAITING_SNAPSHOT, $waiting->fresh()->status, 'the services keep waiting');
        Queue::assertPushed(CheckModelSnapshotJob::class, fn ($job) => $job->attempt === 4);

        // The API (and the library in the console, which shows status and message) says so
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->getJson("/api/models/{$model->id}/snapshots")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.message', "The model volume runr-models is Pending: {$provisioning}");

        // The administrator fixes the storage: the claim binds, the Job runs, the snapshot with it
        $this->claim = ['phase' => 'Bound', 'events' => []];
        (new CheckModelSnapshotJob($snapshot->id, 4))->handle(app(SnapshotService::class), app(ModelServiceDeployer::class));

        $snapshot->refresh();
        $this->assertSame(AiModelSnapshot::STATUS_RUNNING, $snapshot->status);
        $this->assertNull($snapshot->message);
        $this->assertSame(__('ai.models.waiting_snapshot', ['status' => 'running']), $waiting->fresh()->status_message);
        Queue::assertPushed(CheckModelSnapshotJob::class, fn ($job) => $job->attempt === 5);
    }

    public function test_a_pending_claim_without_events_points_to_the_volume_settings(): void
    {
        $region = $this->region();
        $snapshot = $this->runningSnapshot($region, $this->hubModel(), 'model-mirror-w');
        $this->fakeVolume('model-mirror-w', 'Pending', []);

        (new CheckModelSnapshotJob($snapshot->id))->handle(app(SnapshotService::class), app(ModelServiceDeployer::class));

        $this->assertSame(AiModelSnapshot::STATUS_PENDING, $snapshot->fresh()->status);
        $this->assertSame(
            __('ai.models.volume_not_bound', ['name' => 'runr-models', 'phase' => 'Pending', 'reason' => __('ai.models.volume_no_events')]),
            $snapshot->fresh()->message,
        );
    }

    public function test_a_snapshot_that_waited_for_the_volume_until_the_deadline_fails_with_the_reason(): void
    {
        $region = $this->region();
        $model = $this->hubModel();
        $snapshot = $this->runningSnapshot($region, $model, 'model-mirror-t');
        $waiting = $this->modelService($model, $region, ['mode' => 'offline', 'status' => AiModelService::STATUS_WAITING_SNAPSHOT]);
        $this->fakeVolume('model-mirror-t', 'Pending', [
            $this->pvcEvent('Warning', 'ProvisioningFailed', 'storageclass.storage.k8s.io "fast-rwx" not found', '2026-10-11T10:00:00Z'),
        ]);

        (new CheckModelSnapshotJob($snapshot->id, CheckModelSnapshotJob::maxAttempts()))->handle(app(SnapshotService::class), app(ModelServiceDeployer::class));

        $snapshot->refresh();
        $this->assertSame(AiModelSnapshot::STATUS_FAILED, $snapshot->status);
        $this->assertStringStartsWith(__('ai.models.job_timeout'), $snapshot->message);
        $this->assertStringContainsString('storageclass.storage.k8s.io "fast-rwx" not found', $snapshot->message);
        $this->assertSame(AiModelService::STATUS_FAILED, $waiting->fresh()->status);
        Queue::assertNotPushed(CheckModelSnapshotJob::class);
    }

    public function test_the_poll_of_an_earlier_start_ends_when_the_snapshot_is_started_again(): void
    {
        $region = $this->region();
        $snapshot = $this->runningSnapshot($region, $this->hubModel(), 'model-mirror-r');
        $earlier = CheckModelSnapshotJob::startToken($snapshot);
        $this->fakeVolume('model-mirror-r', 'Pending', []);

        // Mirrored again while it waited for the volume: the new start has a poll of its own
        $snapshot->update(['started_at' => now()]);
        (new CheckModelSnapshotJob($snapshot->id, 7, $earlier))->handle(app(SnapshotService::class), app(ModelServiceDeployer::class));

        $this->assertSame([], $this->k8sCalls, 'the earlier poll does not look at the cluster');
        $this->assertSame(AiModelSnapshot::STATUS_RUNNING, $snapshot->fresh()->status);
        Queue::assertNotPushed(CheckModelSnapshotJob::class);

        // The current start's poll goes on and passes its start along
        $current = CheckModelSnapshotJob::startToken($snapshot->fresh());
        (new CheckModelSnapshotJob($snapshot->id, 1, $current))->handle(app(SnapshotService::class), app(ModelServiceDeployer::class));
        $this->assertSame(AiModelSnapshot::STATUS_PENDING, $snapshot->fresh()->status);
        Queue::assertPushed(CheckModelSnapshotJob::class, fn ($job) => $job->attempt === 2 && $job->startedAt === $current);
    }

    public function test_starting_a_mirror_ties_its_poll_to_the_start(): void
    {
        $this->fakeCluster();
        $region = $this->region();
        $snapshot = app(SnapshotService::class)->ensureFor($this->hubModel(), $region);

        $this->assertSame(AiModelSnapshot::STATUS_RUNNING, $snapshot->status);
        $this->assertNotNull($snapshot->started_at);
        Queue::assertPushed(CheckModelSnapshotJob::class, fn ($job) => $job->attempt === 1 && $job->startedAt === CheckModelSnapshotJob::startToken($snapshot));
    }

    public function test_a_queued_start_is_not_polled(): void
    {
        $region = $this->region();
        // Mirror requested again after a failure: pending until StartModelSnapshotJob ran
        $snapshot = AiModelSnapshot::create([
            'ai_model_id' => $this->hubModel()->id, 'knative_region_id' => $region->id, 'source' => 'hub', 'revision' => 'main',
            'path' => 'snapshots/default/x/main', 'status' => 'pending', 'job_name' => 'model-mirror-s',
            'started_at' => now()->subHour(), 'completed_at' => now()->subMinutes(50),
        ]);
        $this->fakeCluster();

        (new CheckModelSnapshotJob($snapshot->id))->handle(app(SnapshotService::class), app(ModelServiceDeployer::class));

        $this->assertSame([], $this->k8sCalls);
        $this->assertSame(AiModelSnapshot::STATUS_PENDING, $snapshot->fresh()->status);
        Queue::assertNotPushed(CheckModelSnapshotJob::class);
    }

    public function test_the_model_volume_status_says_why_the_claim_is_not_bound(): void
    {
        $region = $this->region();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $provisioning = 'failed to provision volume with StorageClass "local-path": NodePath only supports ReadWriteOnce and ReadWriteOncePod (1.22+) access modes';
        $this->fakeVolume('none', 'Pending', [
            $this->pvcEvent('Warning', 'ProvisioningFailed', $provisioning, '2026-10-11T10:00:05Z'),
        ]);

        $pending = $this->getJson("/api/knative-regions/{$region->id}/model-storage")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('storage_class', 'local-path')
            ->assertJsonPath('message', "The model volume runr-models is Pending: {$provisioning}");
        $this->assertStringContainsString('no-store', (string) $pending->headers->get('Cache-Control'));

        // bound now: the next read says so — the live status is never answered from the
        // response cache (60 s for the other region routes)
        $this->claim = ['phase' => 'Bound', 'events' => []];
        $bound = $this->getJson("/api/knative-regions/{$region->id}/model-storage")
            ->assertOk()
            ->assertJsonPath('status', 'bound')
            ->assertJsonPath('message', null);
        $this->assertNotSame('HIT', $bound->headers->get('X-Cache'));
        $this->assertSame(2, count($this->k8sCallsTo('GET', '/persistentvolumeclaims/runr-models')), 'the cluster is asked each time');
    }

    public function test_deleting_a_snapshot_runs_the_cleanup_job(): void
    {
        $this->fakeCluster();
        $region = $this->region();
        $snapshot = AiModelSnapshot::create([
            'ai_model_id' => $this->hubModel()->id, 'knative_region_id' => $region->id, 'source' => 'hub', 'revision' => 'main',
            'path' => 'snapshots/default/x/main', 'status' => 'ready', 'job_name' => 'model-mirror-q',
        ]);

        app(SnapshotService::class)->delete($snapshot->load('region'));

        $this->assertNull(AiModelSnapshot::find($snapshot->id));
        $created = $this->k8sCallsTo('POST', '/namespaces/fn/jobs');
        $this->assertSame('model-rm-'.strtolower($snapshot->id), $created[0]['body']['metadata']['name']);
    }

    public function test_job_state_and_result_lines(): void
    {
        $this->assertSame('complete', SnapshotService::jobState(['status' => ['succeeded' => 1]]));
        $this->assertSame('failed', SnapshotService::jobState(['status' => ['conditions' => [['type' => 'Failed', 'status' => 'True']]]]));
        $this->assertNull(SnapshotService::jobState(['status' => ['active' => 1]]));

        $this->assertSame(['size_bytes' => 5, 'files_count' => 1], SnapshotService::parseResult("noise\nRUNR_SNAPSHOT {\"size_bytes\":5,\"files_count\":1}\n"));
        $this->assertSame(['error' => 'boom'], SnapshotService::parseResult('RUNR_ERROR boom'));
        $this->assertSame([], SnapshotService::parseResult(null));
    }
}
