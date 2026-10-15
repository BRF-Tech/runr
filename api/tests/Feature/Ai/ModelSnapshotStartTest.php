<?php

namespace Tests\Feature\Ai;

use App\Jobs\Ai\CheckModelSnapshotJob;
use App\Jobs\Ai\StartModelSnapshotJob;
use App\Models\AiModel;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\Ai\Models\SnapshotService;
use App\Support\DeployGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Starting an offline copy per source and region: imports from the model volume (pvc) and from
 * an OCI artifact (ORAS, with the region registry's credentials for its own artifacts), uploads
 * that wait for their files, demo regions (ready at once), regions without cluster access, a
 * blocked context (tenant being deleted) and a Job the cluster refuses; a Job that vanished;
 * the copy's logs from its newest pod.
 */
class ModelSnapshotStartTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        DeployGate::setResolver(null);
        parent::tearDown();
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()] + $attributes);
    }

    private function model(string $source, ?string $ref = null): AiModel
    {
        return AiModel::create(['name' => "bert-{$source}", 'source' => $source, 'source_ref' => $ref, 'task' => 'fill-mask', 'size_bytes' => 440_000_000]);
    }

    private function snapshot(AiModel $model, KnativeRegion $region, array $attributes = []): AiModelSnapshot
    {
        return AiModelSnapshot::create(array_merge([
            'ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'source' => $model->source, 'revision' => 'local',
            'path' => "snapshots/default/{$model->name}/local", 'status' => AiModelSnapshot::STATUS_PENDING,
        ], $attributes));
    }

    private function start(AiModelSnapshot $snapshot): AiModelSnapshot
    {
        (new StartModelSnapshotJob($snapshot->id))->handle(app(SnapshotService::class));

        return $snapshot->fresh();
    }

    /** The import Job the cluster received. */
    private function createdJob(): array
    {
        $jobs = $this->k8sCallsTo('POST', '/namespaces/fn/jobs');
        $this->assertCount(1, $jobs);

        return $jobs[0]['body'];
    }

    public function test_an_import_from_the_model_volume_copies_the_folder_with_a_job(): void
    {
        $this->fakeCluster();
        $region = $this->region();
        $snapshot = $this->snapshot($this->model('pvc', 'bert-base'), $region);

        $snapshot = $this->start($snapshot);

        $this->assertSame(AiModelSnapshot::STATUS_RUNNING, $snapshot->status);
        $job = $this->createdJob();
        $this->assertSame('model-import-'.strtolower($snapshot->id), $job['metadata']['name']);
        $this->assertSame($job['metadata']['name'], $snapshot->job_name);
        $mounts = $job['spec']['template']['spec']['containers'][0]['volumeMounts'];
        $this->assertSame(['imports/default/bert-base', 'snapshots/default/bert-pvc/local'], array_column($mounts, 'subPath'));
        $this->assertTrue($mounts[0]['readOnly'], 'the source folder is mounted read-only');
        Queue::assertPushed(CheckModelSnapshotJob::class, fn ($job) => $job->snapshotId === $snapshot->id);
    }

    public function test_an_oci_import_from_the_region_registry_pulls_with_its_credentials(): void
    {
        $this->fakeCluster();
        $region = $this->region(['registry_address' => 'https://registry.eu.test/', 'registry_username' => 'runr', 'registry_password' => 's3cret']);
        $snapshot = $this->snapshot($this->model('oci', 'registry.eu.test/models/bert:1'), $region);

        $snapshot = $this->start($snapshot);

        $this->assertSame(AiModelSnapshot::STATUS_RUNNING, $snapshot->status);
        $secrets = $this->k8sCallsTo('PATCH', '/namespaces/fn/secrets/runr-models-registry');
        $this->assertCount(1, $secrets);
        $this->assertSame('kubernetes.io/dockerconfigjson', $secrets[0]['body']['type']);
        $this->assertSame(
            ['auths' => ['registry.eu.test' => ['auth' => base64_encode('runr:s3cret')]]],
            json_decode($secrets[0]['body']['stringData']['.dockerconfigjson'], true),
        );

        $pod = $this->createdJob()['spec']['template']['spec'];
        $pull = $pod['initContainers'][0];
        $this->assertSame(['pull', 'registry.eu.test/models/bert:1', '--output', '/snapshot', '--registry-config', '/auth/config.json'], $pull['args']);
        $this->assertSame('runr-models-registry', collect($pod['volumes'])->firstWhere('name', 'registry-auth')['secret']['secretName']);
    }

    public function test_an_oci_import_from_another_registry_pulls_anonymously(): void
    {
        $this->fakeCluster();
        $region = $this->region(['registry_address' => 'https://registry.eu.test', 'registry_username' => 'runr', 'registry_password' => 's3cret']);
        $snapshot = $this->snapshot($this->model('oci', 'ghcr.io/acme/bert:1'), $region);

        $this->start($snapshot);

        $this->assertSame([], $this->k8sCallsTo('PATCH', '/secrets/runr-models-registry'));
        $pull = $this->createdJob()['spec']['template']['spec']['initContainers'][0];
        $this->assertSame(['pull', 'ghcr.io/acme/bert:1', '--output', '/snapshot'], $pull['args']);
    }

    public function test_an_upload_waits_for_its_files(): void
    {
        $this->fakeCluster();
        $snapshot = $this->start($this->snapshot($this->model('upload'), $this->region()));

        $this->assertSame(AiModelSnapshot::STATUS_PENDING, $snapshot->status);
        $this->assertSame(__('ai.models.snapshot_needs_upload'), $snapshot->message);
        $this->assertSame([], $this->k8sCalls, 'nothing runs in the cluster');
    }

    public function test_a_demo_region_has_the_copy_at_once(): void
    {
        $this->fakeCluster();
        $demo = $this->region(['is_demo' => true]);

        $snapshot = $this->start($this->snapshot($this->model('pvc', 'bert-base'), $demo));

        $this->assertSame(AiModelSnapshot::STATUS_READY, $snapshot->status);
        $this->assertSame(440_000_000, $snapshot->size_bytes);
        $this->assertNotNull($snapshot->completed_at);
        $this->assertSame([], $this->k8sCalls);
    }

    public function test_a_region_without_cluster_access_fails_the_copy(): void
    {
        $region = $this->region();
        $region->forceFill(['kubeconfig' => null])->save();

        $snapshot = $this->start($this->snapshot($this->model('pvc', 'bert-base'), $region));

        $this->assertSame(AiModelSnapshot::STATUS_FAILED, $snapshot->status);
        $this->assertSame(__('ai.models.region_unavailable', ['region' => $region->name]), $snapshot->message);
    }

    public function test_nothing_starts_while_new_workloads_are_refused(): void
    {
        $this->fakeCluster();
        DeployGate::setResolver(fn () => 'The organization is being deleted.');

        $snapshot = $this->start($this->snapshot($this->model('pvc', 'bert-base'), $this->region()));

        $this->assertSame(AiModelSnapshot::STATUS_FAILED, $snapshot->status);
        $this->assertSame('The organization is being deleted.', $snapshot->message);
        $this->assertSame([], $this->k8sCallsTo('POST', '/jobs'));
    }

    public function test_a_job_the_cluster_refuses_fails_the_copy(): void
    {
        $this->fakeCluster(['POST /apis/batch/v1/namespaces/fn/jobs' => [['kind' => 'Status', 'message' => 'forbidden: exceeded quota'], 403]]);

        $snapshot = $this->start($this->snapshot($this->model('pvc', 'bert-base'), $this->region()));

        $this->assertSame(AiModelSnapshot::STATUS_FAILED, $snapshot->status);
        $this->assertSame(__('ai.models.apply_failed', ['kind' => 'Job']), $snapshot->message);
        Queue::assertNotPushed(CheckModelSnapshotJob::class);
    }

    public function test_a_vanished_job_fails_the_copy(): void
    {
        $this->fakeCluster();   // the Job: 404
        $region = $this->region();
        $snapshot = $this->snapshot($this->model('pvc', 'bert-base'), $region, ['status' => AiModelSnapshot::STATUS_RUNNING, 'job_name' => 'model-import-gone']);

        $this->assertTrue(app(SnapshotService::class)->check($snapshot->load('region')));

        $this->assertSame(AiModelSnapshot::STATUS_FAILED, $snapshot->fresh()->status);
        $this->assertSame(__('ai.models.job_missing', ['name' => 'model-import-gone']), $snapshot->fresh()->message);
    }

    public function test_mirror_requests_respect_the_state_of_the_copy(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $region = $this->region();
        $model = $this->hubModel();
        $revision = str_repeat('a', 40);
        $snapshot = $this->snapshot($model, $region, ['source' => 'hub', 'revision' => $revision, 'status' => AiModelSnapshot::STATUS_RUNNING, 'job_name' => 'model-mirror-x']);
        $mirror = fn (array $extra = []) => $this->postJson("/api/models/{$model->id}/snapshots", ['knative_region_id' => $region->id] + $extra);

        // being copied: not twice
        $mirror()->assertStatus(409)->assertJsonPath('message', __('ai.models.snapshot_busy'));

        // waiting for the model volume (pending with its Job): may be started again
        $snapshot->update(['status' => AiModelSnapshot::STATUS_PENDING, 'message' => 'The model volume runr-models is Pending: …', 'started_at' => now()]);
        $mirror()->assertStatus(202)->assertJsonPath('status', AiModelSnapshot::STATUS_PENDING)->assertJsonPath('message', null);
        Queue::assertPushed(StartModelSnapshotJob::class, fn ($job) => $job->snapshotId === $snapshot->id && ! $job->delete);

        // ready: answered as it is, unless forced
        $snapshot->update(['status' => AiModelSnapshot::STATUS_READY]);
        Queue::fake();
        $mirror()->assertOk()->assertJsonPath('status', AiModelSnapshot::STATUS_READY)->assertJsonPath('id', $snapshot->id);
        Queue::assertNotPushed(StartModelSnapshotJob::class);
        $mirror(['force' => true])->assertStatus(202);
        Queue::assertPushed(StartModelSnapshotJob::class);
        $this->assertSame(1, AiModelSnapshot::count(), 'the same copy, not a second one');

        // a region without a cluster, an import into a region it was not made for
        $idle = $this->gpuRegion(null, ['is_active' => false, 'kubeconfig' => $this->fakeKubeconfig()]);
        $this->postJson("/api/models/{$model->id}/snapshots", ['knative_region_id' => $idle->id])
            ->assertStatus(422)->assertJsonPath('message', __('ai.models.region_unavailable', ['region' => $idle->name]));
        $imported = $this->model('pvc', 'bert-base');
        $this->snapshot($imported, $region);
        $this->postJson("/api/models/{$imported->id}/snapshots", ['knative_region_id' => $this->region()->id])
            ->assertStatus(422)->assertJsonPath('message', __('ai.models.import_other_region'));
    }

    public function test_a_copy_in_use_is_kept_and_a_free_one_removed(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->fakeCluster();
        $region = $this->region();
        $model = $this->hubModel();
        $snapshot = $this->snapshot($model, $region, ['source' => 'hub', 'status' => AiModelSnapshot::STATUS_READY, 'job_name' => 'model-mirror-y']);
        $service = $this->modelService($model, $region, ['mode' => 'offline', 'snapshot_id' => $snapshot->id, 'status' => AiModelService::STATUS_READY]);

        $this->deleteJson("/api/models/{$model->id}/snapshots/{$snapshot->id}")
            ->assertStatus(409)->assertJsonPath('message', __('ai.models.snapshot_in_use', ['services' => $service->name]));

        $service->update(['status' => AiModelService::STATUS_STOPPED]);
        $this->deleteJson("/api/models/{$model->id}/snapshots/{$snapshot->id}")->assertNoContent();
        $this->assertSame(AiModelSnapshot::STATUS_DELETING, $snapshot->fresh()->status);
        Queue::assertPushed(StartModelSnapshotJob::class, fn ($job) => $job->snapshotId === $snapshot->id && $job->delete);

        // the queued removal: the Job and the files go, then the row
        (new StartModelSnapshotJob($snapshot->id, true))->handle(app(SnapshotService::class));
        $this->assertNull(AiModelSnapshot::find($snapshot->id));
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', '/jobs/model-mirror-y'));
        $this->assertSame('model-rm-'.strtolower($snapshot->id), $this->k8sCallsTo('POST', '/namespaces/fn/jobs')[0]['body']['metadata']['name']);
    }

    public function test_the_logs_of_a_copy_come_from_its_newest_pod(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $region = $this->region();
        $model = $this->model('pvc', 'bert-base');
        $snapshot = $this->snapshot($model, $region, ['status' => AiModelSnapshot::STATUS_RUNNING, 'job_name' => 'model-import-x']);
        $this->fakeCluster([
            'GET /api/v1/namespaces/fn/pods' => ['items' => [
                ['metadata' => ['name' => 'model-import-x-old', 'creationTimestamp' => '2026-10-11T10:00:00Z'], 'spec' => ['containers' => [['name' => 'import']]]],
                ['metadata' => ['name' => 'model-import-x-new', 'creationTimestamp' => '2026-10-11T10:05:00Z'], 'spec' => ['containers' => [['name' => 'import']]]],
            ]],
            'GET /api/v1/namespaces/fn/pods/model-import-x-new/log' => fn () => Http::response("copying\nRUNR_SNAPSHOT {\"files_count\":3}\n"),
        ]);

        $this->getJson("/api/models/{$model->id}/snapshots/{$snapshot->id}/logs")
            ->assertOk()
            ->assertJsonPath('logs', "copying\nRUNR_SNAPSHOT {\"files_count\":3}\n");
        $this->assertStringContainsString('job-name%3Dmodel-import-x', $this->k8sCallsTo('GET', '/namespaces/fn/pods')[0]['query']);

        // no Job (an upload): no logs
        $upload = $this->snapshot($this->model('upload'), $region);
        $this->getJson("/api/models/{$upload->model->id}/snapshots/{$upload->id}/logs")->assertOk()->assertJsonPath('logs', null);
    }
}
