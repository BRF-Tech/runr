<?php

namespace Tests\Feature\SupplyChain;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Models\Runtime;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\SupplyChain\AdmissionManifests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\FakesBuildCluster;
use Tests\TestCase;

/**
 * The build Jobs run in the project namespace, where an enforced admission policy checks every
 * container image: what RunR's builds run there — the Kaniko build (Kaniko, its init image) and
 * the WebAssembly builds (the toolchain image of every built-in and platform Wasm runtime, the
 * init image) — passes runr-system-images, so no build is refused by a region's admission policy
 * or its no-match policy.
 */
class BuildJobAdmissionTest extends TestCase
{
    use FakesBuildCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuildCluster();
        foreach (['wasm-rust', 'wasm-go', 'wasm-js', 'wasm-python'] as $i => $runtime) {
            ExecutorImage::create([
                'runtime' => $runtime, 'version' => '4.1', 'docker_image' => "ghcr.io/runrsh/exec-{$runtime}:4.1",
                'is_default' => true, 'is_active' => true, 'sort_order' => 100 + $i,
            ]);
        }
    }

    /** @return list<string> the images of the build Job's init and build containers */
    private function jobImages(): array
    {
        $spec = $this->created('jobs')['spec']['template']['spec'];

        return array_column(array_merge($spec['initContainers'] ?? [], $spec['containers']), 'image');
    }

    /** The glob of the region's system policy the image matches (policy-controller: ** any, * within a segment). */
    private function systemGlobFor(KnativeRegion $region, string $image): ?string
    {
        // what an enforced gate with CaaS images restricted looks like: no-match-policy deny
        $settings = RegionSupplyChainSetting::forRegion($region);
        $settings->forceFill(['admission_mode' => 'enforce', 'caas_policy' => 'allowlist']);
        $this->assertSame('deny', AdmissionManifests::noMatchPolicy($settings));

        foreach (AdmissionManifests::systemGlobs($region, $settings) as $glob) {
            $regex = '#^'.str_replace(['\*\*', '\*'], ['.*', '[^/]*'], preg_quote($glob, '#')).'$#';
            if (preg_match($regex, $image)) {
                return $glob;
            }
        }

        return null;
    }

    public function test_the_kaniko_build_and_every_built_in_wasm_build_pass_the_system_policy(): void
    {
        foreach (['python' => '3.14', 'wasm-rust' => '4.1', 'wasm-go' => '4.1', 'wasm-js' => '4.1', 'wasm-python' => '4.1'] as $runtime => $version) {
            $build = $this->buildVersion($runtime, $version, 'internal', ['main.txt' => 'x']);
            $this->submit($build);

            $images = $this->jobImages();
            $this->assertNotEmpty($images, $runtime);
            foreach ($images as $image) {
                $this->assertNotNull($this->systemGlobFor($build->project->knativeRegion, $image), "{$runtime}: {$image} matches no system glob");
            }
        }

        // the toolchain of a Wasm build is the runtime's own image (ghcr.io/runrsh/exec-wasm-*)
        $this->assertContains('ghcr.io/runrsh/exec-wasm-python:4.1', $images);
    }

    public function test_the_toolchain_of_a_platform_wasm_runtime_passes_the_system_policy(): void
    {
        // a platform runtime like wasm-rust, registered by a platform administrator with its
        // toolchain image from the platform's own registry
        $dir = base_path('../runtime/wasm-rust');
        $manifest = Yaml::parseFile("{$dir}/runtime.yaml");
        $manifest['id'] = 'acme-wasm';
        $manifest['name'] = 'Acme (Wasm)';
        $manifest['versions'] = [['version' => '2.0', 'image' => 'registry.acme.test/toolchains/wasm-rust:2.0', 'default' => true]];
        foreach ($manifest['build']['files'] as $file => $ref) {
            $manifest['build']['files'][$file] = (string) file_get_contents($dir.'/'.ltrim((string) $ref, '@'));
        }
        app(RuntimeRegistry::class)->register(Runtime::SOURCE_PLATFORM, Yaml::dump($manifest, 10, 2), [], null, null);
        app(RuntimeRegistry::class)->forget();

        $this->assertContains('registry.acme.test/toolchains/wasm-rust:2.0', app(RuntimeRegistry::class)->wasmBuildImages());
        $this->assertNotContains('registry.acme.test/toolchains/wasm-rust:2.0', app(RuntimeRegistry::class)->prepullImages(), 'a toolchain is not pre-pulled');

        $build = $this->buildVersion('acme-wasm', '2.0', 'internal', ['lib.rs' => 'fn x() {}']);
        $this->submit($build);

        $images = $this->jobImages();
        $this->assertContains('registry.acme.test/toolchains/wasm-rust:2.0', $images);
        foreach ($images as $image) {
            $this->assertNotNull($this->systemGlobFor($build->project->knativeRegion, $image), "{$image} matches no system glob");
        }
    }
}
