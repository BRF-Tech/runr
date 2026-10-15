<?php

namespace Tests\Feature\Images;

use App\Http\Requests\Project\StoreProjectRequest;
use App\Models\ContainerConfig;
use App\Models\ImageSecurityReport;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\UploadedImage;
use App\Services\SupplyChain\SupplyChainPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesImageCluster;
use Tests\TestCase;

/**
 * Uploaded images (docs/images.md): the list of the current owner (region / kind filters, quota),
 * details, deletion with the manifest in the region registry — refused while a CaaS project or a
 * runtime version uses the image — and another owner's images staying invisible; CaaS projects
 * may run their own uploads only, and are scanned and signed like builds.
 */
class UploadedImageApiTest extends TestCase
{
    use ActsWithRoles, FakesImageCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->userWithRole('owner'), 'sanctum');
    }

    public function test_the_list_shows_the_owners_images_with_references_and_quota(): void
    {
        config(['images.quota_gb' => 2]);
        $eu = $this->imageRegion();
        $us = $this->imageRegion();
        $this->uploadedImage($eu, ['repository' => 'web', 'size_bytes' => 100]);
        $this->uploadedImage($us, ['repository' => 'web', 'size_bytes' => 200]);
        $this->uploadedImage($eu, ['repository' => 'q-interpreter', 'kind' => UploadedImage::KIND_WASM, 'source_format' => 'wasm', 'size_bytes' => 300]);
        $this->uploadedImage($eu, ['repository' => 'theirs', 'owner_key' => '6f1c1b5e-0000-4000-8000-000000000001']);

        $res = $this->getJson('/api/images')->assertOk();
        $this->assertCount(3, $res->json('data'));
        $this->assertSame(['limit_bytes' => 2 * 1024 ** 3, 'used_bytes' => 600, 'images' => 3], $res->json('quota'));

        $web = collect($res->json('data'))->firstWhere('knative_region_id', $us->id);
        $this->assertSame('web:1.0', $web['name']);
        $this->assertSame(self::IMG_ZOT.'/runr-uploads/platform/web@sha256:'.str_repeat('a', 64), $web['image_ref']);
        $this->assertSame(self::IMG_ZOT.'/runr-uploads/platform/web:1.0', $web['tag_ref']);

        $this->assertCount(2, $this->getJson("/api/images?knative_region_id={$eu->id}")->json('data'));
        $this->assertSame(['q-interpreter:1.0'], array_column($this->getJson('/api/images?kind=wasm')->json('data'), 'name'));
        $this->getJson('/api/images/quota')->assertOk()->assertJsonPath('used_bytes', 600);
    }

    public function test_another_owners_image_is_not_found(): void
    {
        $region = $this->imageRegion();
        $theirs = $this->uploadedImage($region, ['owner_key' => '6f1c1b5e-0000-4000-8000-000000000001']);

        $this->getJson("/api/images/{$theirs->id}")->assertNotFound();
        $this->deleteJson("/api/images/{$theirs->id}")->assertNotFound();
    }

    public function test_delete_removes_the_record_and_the_manifest(): void
    {
        $this->fakeImageCluster();
        $region = $this->imageRegion();
        $image = $this->uploadedImage($region);
        // another tag of the same digest keeps the manifest
        $twin = $this->uploadedImage($region, ['tag' => 'latest']);

        $this->deleteJson("/api/images/{$twin->id}")->assertOk()->assertJsonPath('registry_removed', true);
        $this->assertSame([], $this->imgCallsTo('DELETE', '/manifests/'), 'the digest is still tagged');

        $this->deleteJson("/api/images/{$image->id}")->assertOk()->assertJsonPath('deleted', true);
        $this->assertSame(0, UploadedImage::count());
        $this->assertCount(1, $this->imgCallsTo('DELETE', '/proxy/v2/runr-uploads/platform/app/manifests/sha256:'.str_repeat('a', 64)));
    }

    public function test_delete_is_refused_while_a_caas_project_or_a_runtime_uses_the_image(): void
    {
        $this->fakeImageCluster();
        $region = $this->imageRegion();
        $image = $this->uploadedImage($region);

        $project = Project::create(['name' => 'web-from-upload', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => $image->imageRef($region), 'trigger_type' => 'http']);

        $this->deleteJson("/api/images/{$image->id}")->assertStatus(409)
            ->assertJsonPath('code', 'image_in_use')
            ->assertJsonPath('usages.projects', ['web-from-upload']);

        ContainerConfig::query()->delete();
        Runtime::create([
            'id' => 'appimg', 'name' => 'App image', 'source' => Runtime::SOURCE_PLATFORM, 'is_enabled' => true,
            'manifest' => ['id' => 'appimg', 'versions' => [['version' => '1', 'upload' => 'app:1.0']]],
        ]);
        $this->deleteJson("/api/images/{$image->id}")->assertStatus(409)->assertJsonPath('usages.runtimes', ['appimg']);
        $this->assertSame(1, UploadedImage::count());
    }

    public function test_caas_projects_run_their_own_uploads_only(): void
    {
        $region = $this->imageRegion(['total_cpu_m' => 64000, 'total_memory_mb' => 262144]);
        $own = $this->uploadedImage($region);
        $theirs = $this->uploadedImage($region, ['owner_key' => '6f1c1b5e-0000-4000-8000-000000000001', 'repository' => 'secret']);

        $errors = function (string $image) use ($region): array {
            $request = StoreProjectRequest::create('/api/projects', 'POST', [
                'name' => 'caas-'.substr(md5($image), 0, 8), 'service_type' => 'caas', 'knative_region_id' => $region->id,
                'config' => ['image' => $image, 'trigger_type' => 'http'],
            ]);
            $request->setContainer($this->app);
            $validator = Validator::make($request->all(), $request->rules());
            $request->withValidator($validator);

            return $validator->errors()->toArray();
        };

        $this->assertArrayHasKey('config.image', $errors($theirs->imageRef($region)), "another organization's upload");
        $this->assertArrayHasKey('config.image', $errors(self::IMG_ZOT.'/runr-uploads/platform/ghost@sha256:'.str_repeat('9', 64)), 'no such upload');

        $this->assertArrayNotHasKey('config.image', $errors($own->imageRef($region)));
        $this->assertArrayNotHasKey('config.image', $errors($own->tagRef($region)));
        // any other registry stays as it was
        $this->assertArrayNotHasKey('config.image', $errors('docker.io/library/nginx:1.27'));
        $this->assertArrayNotHasKey('config.image', $errors(self::IMG_ZOT.'/runr-builds/web:v3'));
    }

    public function test_a_caas_version_of_an_upload_is_scanned_like_a_build(): void
    {
        Queue::fake();
        $region = $this->imageRegion();
        $image = $this->uploadedImage($region);
        $project = Project::create(['name' => 'caas-upload', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => $image->imageRef($region), 'trigger_type' => 'http']);
        $version = $project->versions()->create(['number' => 1, 'commit_id' => 'abc', 'created_by' => 'dev', 'config_snapshot' => ['image' => $image->imageRef($region)]]);

        $pipeline = app(SupplyChainPipeline::class);
        $this->assertSame([ImageSecurityReport::KIND_UPLOADED, $image->imageRef($region)], $pipeline->imageForVersion($project->fresh(), $version));

        // also where external CaaS images are not scanned: the upload is in RunR's registry
        $report = $pipeline->queueForVersion($version);
        $this->assertNotNull($report);
        $this->assertSame(ImageSecurityReport::KIND_UPLOADED, $report->image_kind);
        $this->assertContains('scan', $report->steps);
    }
}
