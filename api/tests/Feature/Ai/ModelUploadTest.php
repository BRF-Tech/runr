<?php

namespace Tests\Feature\Ai;

use App\Jobs\Ai\DeployModelServiceJob;
use App\Models\AiModel;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\AiModelUpload;
use App\Models\User;
use App\Services\Ai\Models\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Chunked uploads into air-gapped regions: starting one creates the snapshot and the receiver
 * pod + Service in the region; every chunk is forwarded through the Kubernetes API server to the
 * receiver at its offset (nothing kept on the API's disk); chunks that do not fit the declared
 * file are refused; completing verifies on the receiver, marks the snapshot ready, removes the
 * receiver and deploys services waiting for the snapshot; aborting cleans up.
 */
class ModelUploadTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private AiModel $model;

    private $region;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.models.upload_chunk_bytes' => 4]);
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->region = $this->gpuRegion(null, ['kubeconfig' => $this->fakeKubeconfig()]);
        $this->model = AiModel::create(['name' => 'airgap-bert', 'source' => 'upload', 'task' => 'fill-mask']);
    }

    private function receiverReady(): array
    {
        return [
            'GET /api/v1/namespaces/fn/pods/model-recv-*' => ['status' => ['phase' => 'Running', 'conditions' => [['type' => 'Ready', 'status' => 'True']]]],
        ];
    }

    private function start(array $files = [['path' => 'config.json', 'size' => 6], ['path' => 'weights/model.safetensors', 'size' => 8, 'sha256' => null]]): array
    {
        return $this->postJson("/api/models/{$this->model->id}/uploads", ['knative_region_id' => $this->region->id, 'files' => $files])
            ->assertCreated()
            ->json();
    }

    public function test_an_upload_starts_a_receiver_and_forwards_chunks_at_their_offsets(): void
    {
        $this->fakeCluster($this->receiverReady() + [
            'PUT /api/v1/namespaces/fn/services/http:model-recv-*' => ['path' => 'x', 'size' => 4],
        ]);

        $upload = $this->start();
        $this->assertSame(4, $upload['chunk_size']);
        $this->assertSame(AiModelUpload::STATUS_PREPARING, $upload['status']);

        $pods = $this->k8sCallsTo('POST', '/namespaces/fn/pods');
        $this->assertCount(1, $pods, 'receiver pod created');
        $this->assertSame(['python', '-m', 'runr_hf_serve', 'receive', '--root', '/snapshot'], $pods[0]['body']['spec']['containers'][0]['command']);
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/namespaces/fn/services/model-recv-'), 'receiver Service applied');
        $this->assertSame(AiModelSnapshot::STATUS_RUNNING, AiModelSnapshot::firstOrFail()->status);

        $url = "/api/models/{$this->model->id}/uploads/{$upload['id']}/chunk";
        $this->call('PUT', "{$url}?path=weights/model.safetensors&offset=4", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'efgh')
            ->assertOk()->assertJsonPath('uploaded_bytes', 8);

        $put = $this->k8sCallsTo('PUT', 'http:model-recv-')[0];
        $this->assertStringEndsWith('/proxy/files/weights/model.safetensors', $put['path']);
        $this->assertSame('offset=4', $put['query']);
        $this->assertSame('efgh', $put['body']);

        // too large for the chunk size, beyond the declared size, an unknown file
        $this->call('PUT', "{$url}?path=config.json&offset=0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'abcdefg')->assertStatus(422);
        $this->call('PUT', "{$url}?path=config.json&offset=4", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'abc')->assertStatus(422);
        $this->call('PUT', "{$url}?path=other.bin&offset=0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'ab')->assertStatus(422);
    }

    public function test_complete_verifies_on_the_receiver_and_readies_the_snapshot(): void
    {
        Queue::fake();
        $this->fakeCluster($this->receiverReady() + [
            'PUT /api/v1/namespaces/fn/services/http:model-recv-*' => ['ok' => true],
            'POST /api/v1/namespaces/fn/services/http:model-recv-*' => ['ok' => true, 'size_bytes' => 14, 'files' => 2],
        ]);
        $upload = $this->start();
        $waiting = $this->modelService($this->model, $this->region, ['mode' => 'offline', 'engine' => 'transformers', 'status' => AiModelService::STATUS_WAITING_SNAPSHOT]);

        $url = "/api/models/{$this->model->id}/uploads/{$upload['id']}";
        $this->postJson("{$url}/complete")->assertStatus(422)->assertJsonPath('missing', ['config.json', 'weights/model.safetensors']);

        foreach ([['config.json', 0, 'abcd'], ['config.json', 4, 'ef'], ['weights/model.safetensors', 0, 'abcd'], ['weights/model.safetensors', 4, 'efgh']] as [$path, $offset, $bytes]) {
            $this->call('PUT', "{$url}/chunk?path={$path}&offset={$offset}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes)->assertOk();
        }

        $this->postJson("{$url}/complete")
            ->assertOk()
            ->assertJsonPath('upload.status', AiModelUpload::STATUS_COMPLETED)
            ->assertJsonPath('snapshot.status', AiModelSnapshot::STATUS_READY)
            ->assertJsonPath('snapshot.size_bytes', 14)
            ->assertJsonPath('snapshot.files_count', 2);

        $complete = $this->k8sCallsTo('POST', 'http:model-recv-');
        $this->assertStringEndsWith('/proxy/complete', end($complete)['path']);
        $this->assertSame([['path' => 'config.json', 'size' => 6], ['path' => 'weights/model.safetensors', 'size' => 8]], end($complete)['body']['files']);
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', '/pods/model-recv-'), 'receiver removed');

        $this->assertSame(14, $this->model->fresh()->size_bytes);
        Queue::assertPushed(DeployModelServiceJob::class, fn ($job) => $job->serviceId === $waiting->id);
    }

    public function test_a_failed_verification_keeps_the_upload_open(): void
    {
        $this->fakeCluster($this->receiverReady() + [
            'PUT /api/v1/namespaces/fn/services/http:model-recv-*' => ['ok' => true],
            'POST /api/v1/namespaces/fn/services/http:model-recv-*' => [['ok' => false, 'error' => 'sha256 mismatch: config.json'], 422],
        ]);
        $upload = $this->start([['path' => 'config.json', 'size' => 2, 'sha256' => str_repeat('0', 64)]]);
        $url = "/api/models/{$this->model->id}/uploads/{$upload['id']}";
        $this->call('PUT', "{$url}/chunk?path=config.json&offset=0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'ab')->assertOk();

        $this->postJson("{$url}/complete")->assertStatus(422)->assertJsonPath('message', __('ai.uploads.verify_failed', ['message' => 'sha256 mismatch: config.json']));
        $this->assertSame(AiModelUpload::STATUS_READY, AiModelUpload::findOrFail($upload['id'])->status, 'chunks can be sent again');
    }

    public function test_uploads_are_validated_and_aborted(): void
    {
        $this->fakeCluster($this->receiverReady());

        $this->postJson("/api/models/{$this->model->id}/uploads", ['knative_region_id' => $this->region->id, 'files' => [['path' => '../escape', 'size' => 1]]])->assertStatus(422);
        $hub = $this->hubModel();
        $this->postJson("/api/models/{$hub->id}/uploads", ['knative_region_id' => $this->region->id, 'files' => [['path' => 'a', 'size' => 1]]])->assertStatus(422);

        $upload = $this->start();
        $this->postJson("/api/models/{$this->model->id}/uploads", ['knative_region_id' => $this->region->id, 'files' => [['path' => 'b', 'size' => 1]]])->assertStatus(409);

        $this->deleteJson("/api/models/{$this->model->id}/uploads/{$upload['id']}")->assertNoContent();
        $this->assertSame(AiModelUpload::STATUS_ABORTED, AiModelUpload::findOrFail($upload['id'])->status);
        $this->assertSame(AiModelSnapshot::STATUS_FAILED, AiModelSnapshot::firstOrFail()->status);
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', '/services/model-recv-'));
    }

    public function test_expired_uploads_are_aborted_by_the_housekeeping(): void
    {
        $this->fakeCluster($this->receiverReady());
        $upload = $this->start();
        AiModelUpload::whereKey($upload['id'])->update(['expires_at' => now()->subMinute()]);

        // the hourly models:gc
        $this->artisan('models:gc')->expectsOutput('Expired 1 upload(s).')->assertSuccessful();
        $this->assertSame(AiModelUpload::STATUS_ABORTED, AiModelUpload::findOrFail($upload['id'])->status);
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', '/pods/model-recv-'), 'the receiver is removed');
        $this->assertSame(0, app(UploadService::class)->expire(), 'nothing left to expire');
    }

    public function test_paths_are_normalized(): void
    {
        $this->assertSame('weights/model.safetensors', UploadService::normalizePath('./weights//model.safetensors'));
        $this->assertSame('a/b', UploadService::normalizePath('\\a\\b'));
        $this->assertSame('', UploadService::normalizePath('../x'));
        $this->assertSame('', UploadService::normalizePath('/'));
    }
}
