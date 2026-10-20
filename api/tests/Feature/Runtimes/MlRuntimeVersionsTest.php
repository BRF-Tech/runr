<?php

namespace Tests\Feature\Runtimes;

use App\Models\ExecutorImage;
use App\Services\Runtimes\RuntimeRegistry;
use Database\Seeders\MlRuntimeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ML runtimes (PyTorch, TensorFlow) on the versions RunR publishes: the upgrade retires the
 * versions the seeders used to list (never published), the seeders list the published ones, and
 * their images, several GB each, are not pre-pulled onto every node while they stay platform
 * images for the admission policy.
 */
class MlRuntimeVersionsTest extends TestCase
{
    use RefreshDatabase;

    private function image(string $runtime, string $version, bool $default = false, bool $active = true): ExecutorImage
    {
        return ExecutorImage::create([
            'runtime' => $runtime, 'version' => $version, 'docker_image' => "ghcr.io/brf-tech/runr/exec-{$runtime}:{$version}",
            'is_default' => $default, 'is_active' => $active, 'gpu_required' => str_ends_with($version, '-gpu'),
            'category' => in_array($runtime, ['pytorch', 'tensorflow'], true) ? 'ml' : 'general',
        ]);
    }

    public function test_the_upgrade_retires_the_ml_versions_that_were_never_published(): void
    {
        foreach (['2.2', '2.2-gpu', '2.3-gpu'] as $version) {
            $this->image('pytorch', $version);
        }
        $this->image('pytorch', '2.3', default: true);
        $this->image('tensorflow', '2.16', default: true);
        $this->image('tensorflow', '2.15-gpu');
        $kept = [$this->image('pytorch', '2.14'), $this->image('tensorflow', '2.21-gpu'), $this->image('python', '3.12', default: true)];

        (require base_path('database/migrations/2026_10_16_200001_retire_unpublished_ml_runtime_versions.php'))->up();

        $retired = ExecutorImage::whereIn('version', ['2.2', '2.2-gpu', '2.3', '2.3-gpu', '2.15-gpu', '2.16'])->get();
        $this->assertCount(6, $retired);
        foreach ($retired as $image) {
            $this->assertFalse($image->is_active, "{$image->runtime} {$image->version} still active");
            $this->assertFalse($image->is_default, "{$image->runtime} {$image->version} still a default");
        }
        foreach ($kept as $image) {
            $this->assertSame($image->only(['is_active', 'is_default']), $image->fresh()->only(['is_active', 'is_default']), "{$image->runtime} {$image->version} changed");
        }

        // idempotent
        (require base_path('database/migrations/2026_10_16_200001_retire_unpublished_ml_runtime_versions.php'))->up();
        $this->assertSame(0, ExecutorImage::whereIn('version', ['2.2', '2.3', '2.16'])->where('is_active', true)->count());
    }

    public function test_the_seeders_list_the_published_versions(): void
    {
        $this->seed(MlRuntimeSeeder::class);

        $rows = ExecutorImage::orderBy('runtime')->orderBy('sort_order')->get()
            ->map(fn (ExecutorImage $i) => [$i->runtime, $i->version, $i->docker_image, $i->is_default, $i->gpu_required, $i->category])->all();
        $this->assertSame([
            ['pytorch', '2.14', 'ghcr.io/brf-tech/runr/exec-pytorch:2.14', true, false, 'ml'],
            ['pytorch', '2.14-gpu', 'ghcr.io/brf-tech/runr/exec-pytorch:2.14-gpu', false, true, 'ml'],
            ['tensorflow', '2.21', 'ghcr.io/brf-tech/runr/exec-tensorflow:2.21', true, false, 'ml'],
            ['tensorflow', '2.21-gpu', 'ghcr.io/brf-tech/runr/exec-tensorflow:2.21-gpu', false, true, 'ml'],
        ], $rows);

        // These are the tags BRF-Tech/runr-runtimes publishes (`ci.sh ml-runtimes --list` there: the
        // framework pins' major.minor). The pins left this repository with the images; that
        // repository's test/ml_runtimes_test.sh checks the tags against them.
    }

    public function test_ml_runtime_images_are_not_pre_pulled_but_stay_platform_images(): void
    {
        $this->image('python', '3.12', default: true);
        $this->image('pytorch', '2.14', default: true);
        $this->image('pytorch', '2.14-gpu');
        $this->image('tensorflow', '2.21', default: true);
        $this->image('pytorch', '2.3', active: false);
        $registry = app(RuntimeRegistry::class);

        $this->assertSame(['ghcr.io/brf-tech/runr/exec-python:3.12'], $registry->prepullImages());
        $this->assertEqualsCanonicalizing([
            'ghcr.io/brf-tech/runr/exec-python:3.12', 'ghcr.io/brf-tech/runr/exec-pytorch:2.14',
            'ghcr.io/brf-tech/runr/exec-pytorch:2.14-gpu', 'ghcr.io/brf-tech/runr/exec-tensorflow:2.21',
        ], $registry->runtimeImages());
        $this->assertSame([], array_values(array_filter(
            $registry->buildBaseImages(),
            fn (string $image) => str_contains($image, 'exec-pytorch') || str_contains($image, 'exec-tensorflow'),
        )), 'the region registry pre-warm leaves the ML runtimes out');
    }
}
