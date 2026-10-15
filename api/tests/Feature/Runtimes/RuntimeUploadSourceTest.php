<?php

namespace Tests\Feature\Runtimes;

use App\Models\ExecutorImage;
use App\Models\Runtime;
use App\Models\UploadedImage;
use App\Services\Runtimes\InvalidManifestException;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesImageCluster;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * The `upload` version source of runtime manifests (docs/runtimes.md, docs/images.md): validation,
 * the uploaded image required in every region the runtime is offered in, owner scoping (platform
 * runtimes: platform uploads; custom runtimes: their organization's), resolution per region to the
 * digest reference, and the API's version entries.
 */
class RuntimeUploadSourceTest extends TestCase
{
    use ActsWithRoles, FakesImageCluster, RefreshDatabase, RegistersRuntimes;

    private function uploadManifest(string $upload = 'bash-airgap:5.2', array $override = []): string
    {
        return $this->bashManifest(array_replace_recursive([
            'id' => 'bash-airgap',
            'name' => 'Bash (air-gapped)',
            'versions' => [['version' => '5.2', 'image' => null, 'upload' => $upload, 'default' => true]],
        ], $override));
    }

    /** @return list<array{path: string, message: string}> */
    private function problems(callable $call): array
    {
        try {
            $call();
        } catch (InvalidManifestException $e) {
            return $e->problems;
        }

        return [];
    }

    public function test_the_manifest_takes_an_upload_as_the_version_source(): void
    {
        $manifest = app(ManifestValidator::class)->parse($this->uploadManifest());
        $v = $manifest->version('5.2');

        $this->assertSame('bash-airgap:5.2', $v['upload']);
        $this->assertNull($v['image']);
        $this->assertCount(1, $manifest->uploadVersions());

        $both = $this->problems(fn () => app(ManifestValidator::class)->parse($this->uploadManifest('x:1', ['versions' => [['image' => 'docker.io/library/bash:5.2']]])));
        $this->assertSame([['path' => '/versions/0', 'message' => 'needs exactly one of image, dockerfile, git or upload']], $both);

        $bad = $this->problems(fn () => app(ManifestValidator::class)->parse($this->uploadManifest('Not A Ref')));
        $this->assertSame('/versions/0/upload', $bad[0]['path'] ?? null);

        $digest = app(ManifestValidator::class)->parse($this->uploadManifest('bash-airgap@sha256:'.str_repeat('ab', 32)));
        $this->assertSame('bash-airgap@sha256:'.str_repeat('ab', 32), $digest->version('5.2')['upload']);
    }

    public function test_registration_needs_the_upload_in_every_region_of_the_runtime(): void
    {
        $eu = $this->imageRegion(['name' => 'eu-1']);
        $tr = $this->imageRegion(['name' => 'tr-1']);

        $none = $this->problems(fn () => $this->registerRuntime($this->uploadManifest()));
        $this->assertSame('/versions/0/upload', $none[0]['path']);
        $this->assertStringContainsString('not an uploaded image', $none[0]['message']);

        $this->uploadedImage($eu, ['repository' => 'bash-airgap', 'tag' => '5.2']);
        $missing = $this->problems(fn () => $this->registerRuntime($this->uploadManifest()));
        $this->assertStringContainsString('tr-1', $missing[0]['message']);
        $this->assertStringNotContainsString('eu-1', $missing[0]['message']);

        // offered in eu-1 only: fine
        $runtime = $this->registerRuntime($this->uploadManifest(), [], Runtime::SOURCE_PLATFORM, [$eu->id]);
        $this->assertSame('bash-airgap', $runtime->id);
        $this->assertSame('', ExecutorImage::where('runtime', 'bash-airgap')->value('docker_image'));

        // widening it to every region checks again
        $widen = $this->problems(fn () => app(RuntimeRegistry::class)->update($runtime, $this->uploadManifest(), null, ['regions' => null]));
        $this->assertStringContainsString('tr-1', $widen[0]['message'] ?? '');
    }

    public function test_the_version_image_is_the_upload_of_the_region_by_digest(): void
    {
        $eu = $this->imageRegion(['name' => 'eu-1']);
        $tr = $this->imageRegion(['name' => 'tr-1']);
        $this->uploadedImage($eu, ['repository' => 'bash-airgap', 'tag' => '5.2', 'digest' => 'sha256:'.str_repeat('e', 64)]);
        $this->uploadedImage($tr, ['repository' => 'bash-airgap', 'tag' => '5.2', 'digest' => 'sha256:'.str_repeat('f', 64)]);
        $this->registerRuntime($this->uploadManifest());

        $registry = app(RuntimeRegistry::class);
        $manifest = $registry->require('bash-airgap');
        $executor = ExecutorImage::where('runtime', 'bash-airgap')->firstOrFail();

        $this->assertSame(self::IMG_ZOT.'/runr-uploads/platform/bash-airgap@sha256:'.str_repeat('e', 64), $registry->versionImage($manifest, $executor, $eu));
        $this->assertSame(self::IMG_ZOT.'/runr-uploads/platform/bash-airgap@sha256:'.str_repeat('f', 64), $registry->versionImage($manifest, $executor, $tr));

        // gone from a region: the deploy names it
        UploadedImage::query()->where('knative_region_id', $tr->id)->delete();
        try {
            $registry->versionImage($manifest, $executor, $tr);
            $this->fail('no upload in tr-1');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tr-1', $e->getMessage());
            $this->assertStringContainsString('bash-airgap:5.2', $e->getMessage());
        }
    }

    public function test_custom_runtimes_reference_their_own_organizations_uploads_only(): void
    {
        $eu = $this->imageRegion(['name' => 'eu-1']);
        $this->uploadedImage($eu, ['repository' => 'bash-airgap', 'tag' => '5.2']); // a platform upload
        $tenant = '6f1c1b5e-0000-4000-8000-000000000001';

        $this->assertSame($tenant, RuntimeRegistry::uploadOwner(new Runtime(['source' => Runtime::SOURCE_CUSTOM, 'tenant_id' => $tenant])));
        $this->assertSame(UploadedImage::PLATFORM_OWNER, RuntimeRegistry::uploadOwner(new Runtime(['source' => Runtime::SOURCE_PLATFORM])));

        $manifest = app(ManifestValidator::class)->parse($this->uploadManifest());
        $denied = $this->problems(fn () => app(RuntimeRegistry::class)->checkUploads($manifest, Runtime::SOURCE_CUSTOM, null, $tenant));
        $this->assertSame('/versions/0/upload', $denied[0]['path'] ?? null, "the platform's upload is not the organization's");

        $this->uploadedImage($eu, ['owner_key' => $tenant, 'repository' => 'bash-airgap', 'tag' => '5.2']);
        $this->assertSame([], $this->problems(fn () => app(RuntimeRegistry::class)->checkUploads($manifest, Runtime::SOURCE_CUSTOM, null, $tenant)));
    }

    public function test_the_api_lists_upload_versions(): void
    {
        $eu = $this->imageRegion(['name' => 'eu-1']);
        $this->uploadedImage($eu, ['repository' => 'bash-airgap', 'tag' => '5.2']);
        $this->registerRuntime($this->uploadManifest());

        $this->actingAs($this->userWithRole('owner'), 'sanctum');
        $version = $this->getJson('/api/runtimes/bash-airgap')->assertOk()->json('data.versions.0');
        $this->assertSame(['5.2', 'bash-airgap:5.2', false, null], [$version['version'], $version['upload'], $version['built'], $version['image']]);
    }

    public function test_wasm_runtimes_with_uploads_name_their_toolchain_and_wagi_needs_spin(): void
    {
        $validator = app(ManifestValidator::class);
        $wasm = $this->uploadManifest('q-interpreter:1.0', [
            'run' => ['mode' => 'http', 'input' => null, 'command' => null, 'schedule' => 'native'],
            'runtimeClass' => 'wasmtime-spin-v2',
            'build' => ['command' => 'runr-wasm-exec build'],
        ]);
        $problems = $this->problems(fn () => $validator->parse($wasm));
        $this->assertSame('/build/image', $problems[0]['path'] ?? null);

        $withImage = $this->uploadManifest('q-interpreter:1.0', [
            'run' => ['mode' => 'http', 'input' => null, 'command' => null, 'schedule' => 'native'],
            'runtimeClass' => 'wasmtime-spin-v2',
            'build' => ['command' => 'runr-wasm-exec build', 'image' => 'ghcr.io/brf-tech/exec-wasm-exec:4.1'],
            'wagi' => ['argv' => 'q -cgi /{file}', 'files' => true],
        ]);
        $parsed = $validator->parse($withImage);
        $this->assertSame(['argv' => 'q -cgi /{file}', 'files' => true, 'environment' => []], $parsed->wagi());

        $noSpin = $this->problems(fn () => $validator->parse($this->bashManifest(['wagi' => ['files' => true]])));
        $this->assertSame('/wagi', $noSpin[0]['path'] ?? null);
    }
}
