<?php

namespace Tests\Feature\Wasm;

use App\Models\ExecutorImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBuildCluster;
use Tests\TestCase;

/**
 * The build of a WebAssembly function version for each language (WasmBuildService through
 * FunctionBuildService): a toolchain Job with the Kaniko Job's name and labels, the runtime's
 * build files under the project's own, `spin build`, and `spin registry push` of the Spin OCI
 * artifact to runr-builds/<project>:v<N> in the region registry.
 */
class WasmBuildTest extends TestCase
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

    private function job(): array
    {
        return $this->created('jobs');
    }

    private function container(): array
    {
        return $this->job()['spec']['template']['spec']['containers'][0];
    }

    private function script(): string
    {
        return $this->container()['command'][2];
    }

    /** @return array<string, string> file → content of the build's source ConfigMap */
    private function sourceFiles(): array
    {
        $files = [];
        foreach ($this->created('configmaps')['data'] as $key => $content) {
            $files[substr($key, strlen('code---'))] = $content;
        }

        return $files;
    }

    public function test_each_language_builds_with_its_toolchain_image_and_pushes_a_spin_artifact(): void
    {
        $cases = [
            'wasm-rust' => ['lib.rs', 'fn x() {}', ['Cargo.toml', 'spin.toml'], 'ln -sfn "$CARGO_TARGET_DIR" target'],
            'wasm-go' => ['main.go', 'package main', ['go.mod', 'spin.toml'], 'cp /opt/runr/go/go.sum .'],
            'wasm-js' => ['index.js', "addEventListener('fetch', () => {})", ['package.json', 'build.mjs', 'spin.toml'], 'ln -sfn /opt/runr/js/node_modules node_modules'],
            'wasm-python' => ['app.py', 'class HttpHandler(http.Handler): pass', ['spin.toml'], 'mkdir -p /tmp/runr-deps'],
        ];

        foreach ($cases as $runtime => [$file, $code, $buildFiles, $step]) {
            $version = $this->buildVersion($runtime, '4.1', 'internal', [$file => $code]);
            $this->submit($version);
            $slug = $version->project->slug;

            $job = $this->job();
            $this->assertSame("build-{$slug}-v1", $job['metadata']['name'], $runtime);
            $this->assertSame('wasm', $job['metadata']['labels']['runr.dev/build-kind'], $runtime);
            $this->assertSame('true', $job['metadata']['labels']['runr/build'], $runtime);
            $this->assertSame(300, $job['spec']['activeDeadlineSeconds'], $runtime);

            $c = $this->container();
            $this->assertSame('build', $c['name'], $runtime);
            $this->assertSame("ghcr.io/runrsh/exec-{$runtime}:4.1", $c['image'], $runtime);
            $env = array_column($c['env'], 'value', 'name');
            $this->assertSame(self::ZOT."/runr-builds/{$slug}:v1", $env['RUNR_BUILD_IMAGE'], $runtime);
            $this->assertSame('/tmp/home', $env['HOME'], $runtime);

            $script = $this->script();
            $this->assertStringContainsString('cd /workspace/code', $script, $runtime);
            $this->assertStringContainsString($step, $script, $runtime);
            $this->assertStringContainsString('spin build', $script, $runtime);
            $this->assertStringEndsWith("spin registry push --insecure --cache-dir /tmp/spin-cache \"\$RUNR_BUILD_IMAGE\"\necho \"runr: pushed \$RUNR_BUILD_IMAGE\"", $script, $runtime);
            $this->assertLessThan(strpos($script, 'spin registry push'), strpos($script, 'spin build'), $runtime);

            $files = $this->sourceFiles();
            $this->assertSame($code, $files[$file], $runtime);
            foreach ($buildFiles as $name) {
                $this->assertArrayHasKey($name, $files, "{$runtime}: {$name}");
            }
            $this->assertArrayNotHasKey('Dockerfile', $files, "{$runtime}: no Kaniko build");
            $this->assertStringContainsString("cp \"/src/code---{$file}\" \"/workspace/code/{$file}\"", $job['spec']['template']['spec']['initContainers'][0]['command'][2], $runtime);

            $version->refresh();
            $this->assertSame('building', $version->build_status, $runtime);
            $this->assertSame(self::ZOT."/runr-builds/{$slug}:v1", $version->build_image, $runtime);
        }
    }

    public function test_a_projects_own_spin_toml_replaces_the_default(): void
    {
        $own = "spin_manifest_version = 2\n[application]\nname = \"mine\"\n";
        $this->submit($this->buildVersion('wasm-rust', '4.1', 'internal', ['lib.rs' => 'fn x() {}', 'spin.toml' => $own]));

        $files = $this->sourceFiles();
        $this->assertSame($own, $files['spin.toml']);
        $this->assertStringContainsString('spin-sdk', $files['Cargo.toml'], 'the default Cargo.toml still comes along');
    }

    public function test_python_installs_requirements_only_when_the_project_has_them(): void
    {
        $this->submit($this->buildVersion('wasm-python', '4.1', 'internal', ['app.py' => 'x']));
        $this->assertStringNotContainsString('pip install', $this->script());

        $this->submit($this->buildVersion('wasm-python', '4.1', 'internal', ['app.py' => 'x', 'requirements.txt' => "humanize\n"]));
        $script = $this->script();
        $this->assertStringContainsString('pip install --no-cache-dir --target /tmp/runr-deps -r requirements.txt', $script);
        $this->assertLessThan(strpos($script, 'spin build'), strpos($script, 'pip install'));
    }

    public function test_the_build_job_runs_under_the_regions_build_sandbox(): void
    {
        $version = $this->buildVersion('wasm-go', '4.1', 'internal', ['main.go' => 'package main']);
        $version->project->knativeRegion->update(['sandbox_build_runtime' => 'gvisor']);

        $this->submit($version->fresh());

        $this->assertSame('gvisor', $this->job()['spec']['template']['spec']['runtimeClassName']);
    }
}
