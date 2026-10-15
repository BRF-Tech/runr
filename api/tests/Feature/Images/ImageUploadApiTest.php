<?php

namespace Tests\Feature\Images;

use App\Jobs\Images\CheckImageUploadJob;
use App\Jobs\StartSupplyChainJob;
use App\Models\AuditEvent;
use App\Models\ImageSecurityReport;
use App\Models\ImageUpload;
use App\Models\KnativeRegion;
use App\Models\UploadedImage;
use App\Services\Images\ImageUploadService;
use App\Services\Residency\RegionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesImageCluster;
use Tests\TestCase;

/**
 * Chunked image uploads (docs/images.md): starting one creates the receiver Job + Service in the
 * region; every chunk goes through the Kubernetes API server's service proxy to the receiver at
 * its offset and uploaded_bytes follows the receiver's contiguous prefix (resume); completing
 * starts verification and push; the receiver's result becomes the uploaded image (replacing the
 * same name, its old manifest removed), is audited and queued for the supply chain; SHA-256
 * mismatches reopen the upload, failed pushes are final; checks: internal registry, residency,
 * quota, one open upload per name; the permissions.
 */
class ImageUploadApiTest extends TestCase
{
    use ActsWithRoles, FakesImageCluster, RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        config(['images.chunk_bytes' => 4]);
        $this->actingAs($this->userWithRole('owner'), 'sanctum');
        $this->region = $this->imageRegion();
    }

    private function start(array $data = []): array
    {
        return $this->postJson('/api/images/uploads', array_merge([
            'knative_region_id' => $this->region->id,
            'filename' => 'app.tar',
            'size' => 8,
            'name' => 'app:1.0',
        ], $data))->assertCreated()->json('data');
    }

    public function test_starting_an_upload_creates_the_receiver_job_and_service(): void
    {
        $this->fakeImageCluster();

        $upload = $this->start(['sha256' => str_repeat('A', 64)]);
        $this->assertSame('preparing', $upload['status']);
        $this->assertSame(4, $upload['chunk_size']);
        $this->assertSame('app:1.0', $upload['name']);

        $job = $this->imgCallsTo('POST', '/namespaces/fn/jobs')[0]['body'];
        $id = strtolower($upload['id']);
        $this->assertSame("image-recv-{$id}", $job['metadata']['name']);
        $pod = $job['spec']['template']['spec'];
        $this->assertFalse($pod['automountServiceAccountToken']);
        $this->assertTrue($pod['securityContext']['runAsNonRoot']);
        $c = $pod['containers'][0];
        $this->assertSame('ghcr.io/runrsh/image-upload:1.0.0', $c['image']);
        $this->assertTrue($c['securityContext']['readOnlyRootFilesystem']);
        $this->assertSame(['ALL'], $c['securityContext']['capabilities']['drop']);
        $env = collect($c['env'])->pluck('value', 'name');
        $this->assertSame('8', $env['RUNR_UPLOAD_SIZE']);
        $this->assertSame(str_repeat('a', 64), $env['RUNR_UPLOAD_SHA256']);
        $this->assertSame(self::IMG_ZOT.'/runr-uploads/platform/app:1.0', $env['RUNR_UPLOAD_TARGET']);
        $this->assertSame('4', $env['RUNR_UPLOAD_MAX_CHUNK']);
        $this->assertSame('513Mi', $pod['volumes'][0]['emptyDir']['sizeLimit'], 'twice the file plus headroom');

        $service = $this->imgCallsTo('PATCH', "/namespaces/fn/services/image-recv-{$id}");
        $this->assertNotEmpty($service, 'receiver Service applied');
    }

    public function test_chunks_are_forwarded_at_their_offsets_and_the_upload_resumes(): void
    {
        $this->fakeImageCluster();
        $upload = $this->start();

        $this->putChunk($upload['id'], 0, 'abcd')->assertOk()->assertJsonPath('uploaded_bytes', 4);
        $put = $this->imgCallsTo('PUT', '/proxy/upload')[0];
        $this->assertSame('offset=0', $put['query']);
        $this->assertSame('abcd', $put['body']);

        // a reload: the upload says where to go on
        $this->getJson("/api/images/uploads/{$upload['id']}")->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.uploaded_bytes', 4);
        $this->getJson('/api/images/uploads')->assertOk()->assertJsonPath('data.0.id', $upload['id']);

        $this->putChunk($upload['id'], 4, 'efgh')->assertOk()->assertJsonPath('uploaded_bytes', 8);

        // larger than the chunk size, beyond the file
        $this->putChunk($upload['id'], 0, 'abcde')->assertStatus(422)->assertJsonPath('code', 'bad_chunk');
        $this->putChunk($upload['id'], 6, 'xyz')->assertStatus(422);
    }

    public function test_chunks_wait_for_the_receiver(): void
    {
        $this->receiverReady = false;
        $this->fakeImageCluster();
        $upload = $this->start();

        $this->putChunk($upload['id'], 0, 'abcd')->assertStatus(409)->assertJsonPath('code', 'upload_not_ready');
    }

    public function test_complete_pushes_and_the_result_becomes_the_uploaded_image(): void
    {
        Queue::fake();
        $this->fakeImageCluster();
        $upload = $this->start();

        $this->putChunk($upload['id'], 0, 'abcd')->assertOk();
        $this->postJson("/api/images/uploads/{$upload['id']}/complete")->assertStatus(422)->assertJsonPath('code', 'upload_incomplete');

        $this->putChunk($upload['id'], 4, 'efgh')->assertOk();
        $this->postJson("/api/images/uploads/{$upload['id']}/complete")->assertStatus(202)->assertJsonPath('data.status', 'verifying');
        $this->assertNotEmpty($this->imgCallsTo('POST', '/proxy/complete'));
        Queue::assertPushed(CheckImageUploadJob::class, fn ($job) => $job->uploadId === $upload['id']);

        // still pushing: the job looks again
        $this->receiverStatus = ['state' => 'pushing', 'received' => 8];
        (new CheckImageUploadJob($upload['id']))->handle(app(ImageUploadService::class));
        $this->assertSame(ImageUpload::STATUS_PUSHING, ImageUpload::findOrFail($upload['id'])->status);

        $this->receiverStatus = $this->pushedStatus();
        (new CheckImageUploadJob($upload['id']))->handle(app(ImageUploadService::class));

        $done = ImageUpload::findOrFail($upload['id']);
        $this->assertSame(ImageUpload::STATUS_COMPLETED, $done->status);
        $image = UploadedImage::findOrFail($done->uploaded_image_id);
        $this->assertSame(['app', '1.0', 'platform'], [$image->repository, $image->tag, $image->owner_key]);
        $this->assertSame('sha256:'.str_repeat('c', 64), $image->digest);
        $this->assertSame('runr-uploads/platform/app', $image->registry_repository);
        $this->assertSame(['linux/amd64', 'linux/arm64'], $image->platforms);
        $this->assertSame([7000, 8, 'oci', 'app.tar'], [$image->size_bytes, $image->archive_bytes, $image->source_format, $image->source_filename]);
        $this->assertSame(self::IMG_ZOT.'/runr-uploads/platform/app@sha256:'.str_repeat('c', 64), $image->imageRef());

        $this->assertNotEmpty($this->imgCallsTo('DELETE', '/jobs/image-recv-'), 'receiver Job removed');
        $this->assertNotEmpty($this->imgCallsTo('DELETE', '/services/image-recv-'), 'receiver Service removed');

        $event = AuditEvent::query()->where('action', 'image.upload.pushed')->firstOrFail();
        $this->assertSame('success', $event->result);
        $this->assertSame($image->digest, $event->metadata['digest']);

        // the supply chain scans and signs it like a build (central report, subject uploaded_image)
        $report = ImageSecurityReport::forUploadedImage($image);
        $this->assertNotNull($report);
        $this->assertSame(ImageSecurityReport::KIND_UPLOADED, $report->image_kind);
        $this->assertSame($image->imageRef(), $report->image_ref);
        Queue::assertPushed(StartSupplyChainJob::class);

        $this->getJson("/api/images/uploads/{$upload['id']}")->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.image.digest', $image->digest);
    }

    public function test_an_upload_of_the_same_name_replaces_the_image_and_removes_the_old_manifest(): void
    {
        Queue::fake();
        $this->fakeImageCluster();
        $old = $this->uploadedImage($this->region, ['digest' => 'sha256:'.str_repeat('1', 64), 'size_bytes' => 50]);

        $upload = $this->start();
        $this->putChunk($upload['id'], 0, 'abcd');
        $this->putChunk($upload['id'], 4, 'efgh');
        $this->postJson("/api/images/uploads/{$upload['id']}/complete")->assertStatus(202);
        $this->receiverStatus = $this->pushedStatus();
        (new CheckImageUploadJob($upload['id']))->handle(app(ImageUploadService::class));

        $this->assertSame(1, UploadedImage::count());
        $this->assertSame('sha256:'.str_repeat('c', 64), $old->fresh()->digest);
        $delete = $this->imgCallsTo('DELETE', '/proxy/v2/runr-uploads/platform/app/manifests/sha256:'.str_repeat('1', 64));
        $this->assertCount(1, $delete, 'the replaced manifest is removed from the registry');
    }

    public function test_a_sha256_mismatch_reopens_the_upload(): void
    {
        Queue::fake();
        $this->fakeImageCluster();
        $upload = $this->start(['sha256' => str_repeat('0', 64)]);
        $this->putChunk($upload['id'], 0, 'abcd');
        $this->putChunk($upload['id'], 4, 'efgh');
        $this->postJson("/api/images/uploads/{$upload['id']}/complete")->assertStatus(202);

        $this->receiverStatus = ['state' => 'receiving', 'received' => 0, 'error' => 'the file\'s SHA-256 is x, the upload declared y; send the file again'];
        (new CheckImageUploadJob($upload['id']))->handle(app(ImageUploadService::class));

        $u = ImageUpload::findOrFail($upload['id']);
        $this->assertSame([ImageUpload::STATUS_READY, 0], [$u->status, $u->uploaded_bytes]);
        $this->assertStringContainsString('send the file again', $u->message);
        $this->assertSame(0, UploadedImage::count());
    }

    public function test_a_failed_push_is_final_and_audited(): void
    {
        Queue::fake();
        $this->fakeImageCluster();
        $upload = $this->start();
        $this->putChunk($upload['id'], 0, 'abcd');
        $this->putChunk($upload['id'], 4, 'efgh');
        $this->postJson("/api/images/uploads/{$upload['id']}/complete")->assertStatus(202);

        $this->receiverStatus = ['state' => 'failed', 'received' => 8, 'error' => 'the archive holds 2 images and none is named "1.0"'];
        (new CheckImageUploadJob($upload['id']))->handle(app(ImageUploadService::class));

        $u = ImageUpload::findOrFail($upload['id']);
        $this->assertSame(ImageUpload::STATUS_FAILED, $u->status);
        $this->assertStringContainsString('none is named', $u->message);
        $this->assertNotEmpty($this->imgCallsTo('DELETE', '/jobs/image-recv-'));
        $this->assertSame('failure', AuditEvent::query()->where('action', 'image.upload.failed')->firstOrFail()->result);
    }

    public function test_aborting_and_expiring_remove_the_receiver(): void
    {
        $this->fakeImageCluster();
        $upload = $this->start();
        $this->deleteJson("/api/images/uploads/{$upload['id']}")->assertNoContent();
        $this->assertSame(ImageUpload::STATUS_ABORTED, ImageUpload::findOrFail($upload['id'])->status);
        $this->assertNotEmpty($this->imgCallsTo('DELETE', '/jobs/image-recv-'));

        $second = $this->start(['name' => 'other:1']);
        ImageUpload::whereKey($second['id'])->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(ImageUploadService::class)->expire());
        $this->assertSame(ImageUpload::STATUS_ABORTED, ImageUpload::findOrFail($second['id'])->status);
    }

    public function test_uploads_are_refused_without_an_internal_registry_or_over_the_quota(): void
    {
        $this->fakeImageCluster();

        $external = $this->imageRegion(['registry_mode' => 'external', 'registry_address' => 'registry.example.test']);
        $this->postJson('/api/images/uploads', ['knative_region_id' => $external->id, 'filename' => 'a.tar', 'size' => 8])
            ->assertStatus(422)->assertJsonPath('code', 'registry_unavailable');

        config(['images.quota_gb' => 1]);
        $this->uploadedImage($this->region, ['repository' => 'big', 'size_bytes' => 1024 ** 3 - 4]);
        $this->postJson('/api/images/uploads', ['knative_region_id' => $this->region->id, 'filename' => 'a.tar', 'size' => 8])
            ->assertStatus(422)->assertJsonPath('code', 'quota_exceeded');

        // replacing an image of the same name: its size comes back
        $this->postJson('/api/images/uploads', ['knative_region_id' => $this->region->id, 'filename' => 'big.tar', 'size' => 8, 'name' => 'big:1.0'])
            ->assertCreated();
    }

    public function test_one_open_upload_per_name_and_valid_names(): void
    {
        $this->fakeImageCluster();
        $this->start();
        $this->postJson('/api/images/uploads', ['knative_region_id' => $this->region->id, 'filename' => 'app.tar', 'size' => 8, 'name' => 'app:1.0'])
            ->assertStatus(409)->assertJsonPath('code', 'upload_open');

        $this->postJson('/api/images/uploads', ['knative_region_id' => $this->region->id, 'filename' => 'x.tar', 'size' => 8, 'name' => 'Bad Name'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        // no name: from the file name; a .wasm is a module
        $wasm = $this->postJson('/api/images/uploads', ['knative_region_id' => $this->region->id, 'filename' => 'Q Interpreter.wasm', 'size' => 8])
            ->assertCreated()->json('data');
        $this->assertSame(['q-interpreter', 'latest', 'wasm'], [$wasm['repository'], $wasm['tag'], $wasm['format']]);
    }

    public function test_data_residency_is_checked(): void
    {
        $this->fakeImageCluster();
        $this->app->instance(RegionPolicy::class, new class extends RegionPolicy
        {
            public function check(KnativeRegion|string|null $region, string $action, ?string $tenantId = null): array
            {
                return ['allowed' => $action !== self::IMAGE_UPLOAD, 'reason' => 'Region not allowed for this organization.', 'location' => null];
            }
        });

        $this->postJson('/api/images/uploads', ['knative_region_id' => $this->region->id, 'filename' => 'a.tar', 'size' => 8])
            ->assertStatus(422)->assertJsonPath('code', 'region_not_allowed');
        $this->assertSame(0, ImageUpload::count());
    }

    public function test_viewers_cannot_upload(): void
    {
        $this->fakeImageCluster();
        $this->actingAs($this->userWithRole('viewer'), 'sanctum');

        $this->postJson('/api/images/uploads', ['knative_region_id' => $this->region->id, 'filename' => 'a.tar', 'size' => 8])->assertForbidden();
        $this->getJson('/api/images')->assertOk();
    }

    public function test_demo_regions_take_uploads_without_a_cluster(): void
    {
        Queue::fake();
        $demo = KnativeRegion::create(['name' => 'demo-img', 'is_active' => true, 'is_demo' => true, 'project_namespace' => 'fn']);
        $upload = $this->postJson('/api/images/uploads', ['knative_region_id' => $demo->id, 'filename' => 'm.wasm', 'size' => 8])->assertCreated()->json('data');
        $this->assertSame('ready', $upload['status']);

        $this->putChunk($upload['id'], 0, 'abcd')->assertOk()->assertJsonPath('uploaded_bytes', 4);
        $this->putChunk($upload['id'], 4, 'efgh')->assertOk()->assertJsonPath('uploaded_bytes', 8);
        $this->postJson("/api/images/uploads/{$upload['id']}/complete")->assertStatus(202)->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.image.kind', 'wasm');
    }
}
