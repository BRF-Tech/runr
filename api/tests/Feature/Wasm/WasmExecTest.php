<?php

namespace Tests\Feature\Wasm;

use App\Models\ExecutorImage;
use App\Models\Runtime;
use App\Models\UploadedImage;
use App\Models\Version;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Wasm\WasmRuntimes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\FakesBuildCluster;
use Tests\TestCase;

/**
 * The wasm-exec runtime and WAGI runtimes (docs/wasm.md#wasm-exec): the built-in manifest (Spin
 * shim, WAGI section), the build Job — the wasm-exec toolchain image, `runr-wasm-exec build`, the
 * generated WAGI spin.toml (or the project's own), and an uploaded module instead of a compile:
 * a project's wasm.upload, or the version image of a platform runtime with an `upload:` version
 * (Q on Wasm, examples/wasm/q-exec) built in its own build.image.
 */
class WasmExecTest extends TestCase
{
    use FakesBuildCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuildCluster();
        ExecutorImage::create([
            'runtime' => 'wasm-exec', 'version' => '4.1', 'docker_image' => 'ghcr.io/runrsh/exec-wasm-exec:4.1',
            'is_default' => true, 'is_active' => true, 'sort_order' => 110,
        ]);
    }

    private function container(): array
    {
        return $this->created('jobs')['spec']['template']['spec']['containers'][0];
    }

    private function env(): array
    {
        return collect($this->container()['env'])->pluck('value', 'name')->all();
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

    private function wasmModule(Version $version, string $repository = 'hello-c', string $kind = UploadedImage::KIND_WASM): UploadedImage
    {
        return UploadedImage::create([
            'owner_key' => UploadedImage::PLATFORM_OWNER, 'knative_region_id' => $version->project->knative_region_id,
            'repository' => $repository, 'tag' => '1.0', 'registry_repository' => "runr-uploads/platform/{$repository}",
            'digest' => 'sha256:'.str_repeat('d', 64), 'media_type' => 'application/vnd.oci.image.manifest.v1+json',
            'kind' => $kind, 'source_format' => 'wasm', 'size_bytes' => 100,
        ]);
    }

    public function test_the_built_in_manifest_runs_on_the_spin_shim_with_wagi(): void
    {
        $m = app(RuntimeRegistry::class)->require('wasm-exec');

        $this->assertSame('wasmtime-spin-v2', $m->runtimeClass());
        $this->assertTrue(WasmRuntimes::isWasm($m));
        $this->assertSame(['argv' => '${SCRIPT_NAME} ${ARGS}', 'files' => true, 'environment' => []], $m->wagi());
        $this->assertSame('runr-wasm-exec build', $m->build()['command']);
        $this->assertSame(['http', 'schedule', 'queue'], $m->triggers());
        $this->assertSame('main.c', $m->defaultFilename());
        $this->assertSame('ghcr.io/runrsh/exec-wasm-exec:4.1', $m->version('4.1')['image']);
        $this->assertStringContainsString('HTTP_CE_TYPE', $m->templateFiles('main.c', 'main', false)['main.c']);
    }

    public function test_a_c_project_builds_with_the_toolchain_and_a_generated_wagi_spin_toml(): void
    {
        $version = $this->buildVersion('wasm-exec', '4.1', 'internal', ['main.c' => 'int main(void) { return 0; }']);
        $this->submit($version);

        $c = $this->container();
        $this->assertSame('ghcr.io/runrsh/exec-wasm-exec:4.1', $c['image']);
        $this->assertStringContainsString('runr-wasm-exec build', $c['command'][2]);
        $this->assertStringContainsString('spin registry push --insecure', $c['command'][2]);
        $this->assertArrayNotHasKey('RUNR_WASM_MODULE', $this->env(), 'compiled, nothing fetched');

        $files = $this->sourceFiles();
        $this->assertSame('int main(void) { return 0; }', $files['main.c']);
        $toml = $files['spin.toml'];
        $this->assertStringContainsString('executor = { type = "wagi", argv = "${SCRIPT_NAME} ${ARGS}" }', $toml);
        $this->assertStringContainsString('source = "main.wasm"', $toml);
        $this->assertStringContainsString('files = ["main.c"]', $toml);
        $this->assertStringContainsString('RUNR_RUNTIME = "wasm-exec"', $toml);
        $this->assertStringContainsString('RUNR_RUNTIME_VERSION = "4.1"', $toml);
    }

    public function test_the_projects_own_spin_toml_wins(): void
    {
        $own = "spin_manifest_version = 2\n# mine\n";
        $this->submit($this->buildVersion('wasm-exec', '4.1', 'internal', ['main.zig' => 'pub fn main() void {}', 'spin.toml' => $own]));

        $this->assertSame($own, $this->sourceFiles()['spin.toml']);
    }

    public function test_wasm_upload_names_an_uploaded_module_instead_of_a_compile(): void
    {
        $version = $this->buildVersion('wasm-exec', '4.1', 'internal', ['wasm.upload' => "# the interpreter\nhello-c:1.0\n", 'main.q' => 'print "x"']);
        $this->wasmModule($version);
        $this->submit($version);

        $this->assertSame(self::ZOT.'/runr-uploads/platform/hello-c@sha256:'.str_repeat('d', 64), $this->env()['RUNR_WASM_MODULE']);
        $toml = $this->sourceFiles()['spin.toml'];
        $this->assertStringContainsString('files = ["main.q"]', $toml, 'wasm.upload itself is not mapped');
    }

    public function test_wasm_upload_must_name_a_module_of_the_region(): void
    {
        $missing = $this->buildVersion('wasm-exec', '4.1', 'internal', ['wasm.upload' => "nope:1.0\n"]);
        try {
            $this->submit($missing);
            $this->fail('unknown module');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('nope:1.0', $e->getMessage());
        }

        $image = $this->buildVersion('wasm-exec', '4.1', 'internal', ['wasm.upload' => "web:1.0\n"]);
        $this->wasmModule($image, 'web', UploadedImage::KIND_IMAGE);
        try {
            $this->submit($image);
            $this->fail('a container image is not a module');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('WebAssembly module', $e->getMessage());
        }
    }

    public function test_a_platform_runtime_on_an_uploaded_interpreter_builds_in_its_toolchain_image(): void
    {
        $manifest = Yaml::parseFile(base_path('../examples/wasm/q-exec/runtime.yaml'));
        $parsed = app(ManifestValidator::class)->validate($manifest);
        Runtime::create([
            'id' => 'q-wasm', 'name' => 'Q (Wasm)', 'source' => Runtime::SOURCE_PLATFORM, 'is_enabled' => true,
            'manifest' => $parsed->toArray(), 'manifest_source' => Yaml::dump($manifest), 'digest' => $parsed->digest(),
        ]);
        app(RuntimeRegistry::class)->syncVersions(Runtime::findOrFail('q-wasm'), $parsed);
        app(RuntimeRegistry::class)->forget();

        $version = $this->buildVersion('q-wasm', '1.0', 'internal', ['main.q' => 'print "Hello"']);
        $this->wasmModule($version, 'q-interpreter');
        $this->submit($version);

        $c = $this->container();
        $this->assertSame('ghcr.io/runrsh/exec-wasm-exec:4.1', $c['image'], 'build.image, not the uploaded module');
        $this->assertSame(self::ZOT.'/runr-uploads/platform/q-interpreter@sha256:'.str_repeat('d', 64), $this->env()['RUNR_WASM_MODULE']);
        $toml = $this->sourceFiles()['spin.toml'];
        $this->assertStringContainsString('executor = { type = "wagi", argv = "q -cgi /main.q" }', $toml);
        $this->assertStringContainsString('files = ["main.q"]', $toml);
    }
}
