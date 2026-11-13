<?php

namespace Tests\Feature\Runtimes;

use App\Models\ExecutorImage;
use App\Models\Project;
use App\Models\XaasConfig;
use App\Services\ProjectService;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\InvalidManifestException;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * A version's own starter and build files (versions[].template, versions[].build.files): an SDK
 * generation side by side with the one before it (wasm-rust 4.1 on Spin SDK 5, 4.2 on Spin SDK 8)
 * — new projects on the version get its starter, its builds get its build files, every other
 * version keeps the runtime's.
 */
class RuntimeVersionFilesTest extends TestCase
{
    use RefreshDatabase, RegistersRuntimes;

    private function manifest(array $override = []): array
    {
        return array_replace_recursive([
            'apiVersion' => 'runr.sh/v1',
            'kind' => 'Runtime',
            'id' => 'sdk',
            'name' => 'SDK',
            'extensions' => ['rs'],
            'handler' => ['file' => 'lib', 'function' => 'handle'],
            'versions' => [
                ['version' => '1.0', 'image' => 'ghcr.io/example/sdk:1.0', 'default' => true],
                [
                    'version' => '2.0', 'image' => 'ghcr.io/example/sdk:2.0',
                    'template' => ['files' => ['{file}' => "async fn {handler}() {}\n"]],
                    'build' => ['files' => ['Cargo.toml' => "sdk = \"2\"\n"]],
                ],
            ],
            'template' => ['files' => ['{file}' => "fn {handler}() {}\n"]],
            'build' => ['command' => 'cargo build', 'files' => ['Cargo.toml' => "sdk = \"1\"\n", 'spin.toml' => "v1\n"]],
            'run' => ['mode' => 'http', 'schedule' => 'native'],
        ], $override);
    }

    public function test_a_version_gives_its_own_starter_and_build_files_the_others_the_runtimes(): void
    {
        $m = app(ManifestValidator::class)->validate($this->manifest());

        $this->assertSame(['lib.rs' => "async fn run() {}\n"], $m->templateFiles('lib.rs', 'run', false, '2.0'));
        $this->assertSame(['lib.rs' => "fn run() {}\n"], $m->templateFiles('lib.rs', 'run', false, '1.0'));
        $this->assertSame(['lib.rs' => "fn run() {}\n"], $m->templateFiles('lib.rs', 'run', false), 'no version: the runtime\'s');
        $this->assertSame(['lib.rs' => "fn run() {}\n"], $m->templateFiles('lib.rs', 'run', false, '3.0'), 'a version it does not have');

        // the version's build files replace the runtime's (no spin.toml of 1.0 in a 2.0 build)
        $this->assertSame(['Cargo.toml' => "sdk = \"2\"\n"], $m->buildFiles('2.0'));
        $this->assertSame(['Cargo.toml' => "sdk = \"1\"\n", 'spin.toml' => "v1\n"], $m->buildFiles('1.0'));
        $this->assertSame($m->buildFiles('1.0'), $m->buildFiles());

        // normalized: only the version that has them carries them
        $this->assertArrayNotHasKey('template', $m->version('1.0'));
        $this->assertArrayNotHasKey('build', $m->version('1.0'));
        $this->assertSame(['files' => ['Cargo.toml' => "sdk = \"2\"\n"]], $m->version('2.0')['build']);
    }

    public function test_the_rules_of_a_versions_files(): void
    {
        $problems = function (array $manifest, bool $builtin = false): array {
            try {
                app(ManifestValidator::class)->validate($manifest, [], $builtin);

                return [];
            } catch (InvalidManifestException $e) {
                return array_column($e->problems, 'message', 'path');
            }
        };

        // build files of a version need the runtime's build (its command builds them)
        $noBuild = $this->manifest();
        unset($noBuild['build']);
        $this->assertSame(['/versions/1/build' => 'only applies to a runtime with a build (build.command)'], $problems($noBuild));

        // "@file" references only in catalogs, as the runtime's build files
        $ref = $this->manifest(['versions' => [1 => ['build' => ['files' => ['Cargo.toml' => '@2.0/Cargo.toml']]]]]);
        $this->assertSame(['/versions/1/build/files/Cargo.toml' => 'file references (@path) are only resolved in catalogs (the built-in catalog, runtime sources)'], $problems($ref));
        $this->assertSame([], $problems($ref, true));

        // the schema: files is required, nothing else is allowed
        $paths = fn (array $manifest) => implode(' ', array_keys($problems($manifest)));
        $this->assertStringContainsString('/versions/1/build', $paths($this->manifest(['versions' => [1 => ['build' => ['command' => 'make']]]])));
        $this->assertStringContainsString('/versions/1/template', $paths($this->manifest(['versions' => [1 => ['template' => ['pattern' => 'x']]]])));
    }

    public function test_the_catalog_reader_inlines_a_versions_build_files(): void
    {
        $dir = sys_get_temp_dir().'/runr-version-files-'.uniqid();
        File::ensureDirectoryExists("{$dir}/sdk/2.0");
        $manifest = $this->manifest(['versions' => [1 => ['build' => ['files' => ['Cargo.toml' => '@2.0/Cargo.toml']]]]]);
        file_put_contents("{$dir}/sdk/runtime.yaml", $this->yaml($manifest));
        file_put_contents("{$dir}/sdk/2.0/Cargo.toml", "sdk = \"2\"\r\nedition = \"2024\"\r\n");

        try {
            $read = app(BuiltinCatalog::class)->readSources($dir);
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertSame("sdk = \"2\"\nedition = \"2024\"\n", $read[0]['versions'][1]['build']['files']['Cargo.toml']);
        $this->assertSame("sdk = \"1\"\n", $read[0]['build']['files']['Cargo.toml']);
    }

    public function test_a_new_project_gets_the_starter_of_its_runtime_version(): void
    {
        $service = app(ProjectService::class);
        $project = function (?string $version) {
            $p = Project::create(['name' => 'rust-'.($version ?? 'default'), 'service_type' => 'xaas', 'runtime' => 'wasm-rust', 'runtime_version' => $version]);
            XaasConfig::create(['project_id' => $p->id, 'filename' => 'lib.rs', 'handler' => 'handle', 'trigger_type' => 'http']);

            return $p->fresh('xaasConfig');
        };

        $this->assertStringContainsString('#[http_service]', $service->getDefaultCode($project('4.2')));
        $this->assertStringContainsString('async fn handle(req: Request)', $service->getDefaultCode($project('4.2')));
        $this->assertStringContainsString('#[http_component]', $service->getDefaultCode($project('4.1')));
        // without a version: the default's — the executor rows' (executor:sync), else the manifest's
        $this->assertStringContainsString('#[http_service]', $service->getDefaultCode($project(null)), 'the manifest\'s default (4.2)');
        ExecutorImage::create(['runtime' => 'wasm-rust', 'version' => '4.1', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-wasm-rust:4.1', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $this->assertStringContainsString('#[http_component]', $service->getDefaultCode($project(null)), 'the platform\'s default version');
    }

    public function test_the_built_in_wasm_versions_side_by_side(): void
    {
        $registry = app(RuntimeRegistry::class);

        $rust = $registry->require('wasm-rust');
        $this->assertSame(['4.1', '4.2'], array_column($rust->versions(), 'version'));
        $this->assertSame('4.2', $rust->defaultVersion(), 'new projects: Spin Rust SDK 8');
        $this->assertStringContainsString('spin-sdk = "8.0.0"', $rust->buildFiles('4.2')['Cargo.toml']);
        $this->assertStringContainsString('spin-sdk = "5.2.0"', $rust->buildFiles('4.1')['Cargo.toml']);
        // the handler check takes either generation's macro
        foreach (['4.1', '4.2'] as $v) {
            $this->assertMatchesRegularExpression($rust->handlerPattern(), $rust->templateFiles('lib.rs', 'handle', false, $v)['lib.rs'], $v);
        }

        $js = $registry->require('wasm-js');
        $this->assertSame('4.2', $js->defaultVersion());
        $this->assertSame($js->templateFiles('index.js', 'handler', false, '4.1'), $js->templateFiles('index.js', 'handler', false, '4.2'));
        $this->assertStringContainsString('"esbuild": "^0.28.2"', $js->buildFiles('4.2')['package.json']);
        $this->assertStringContainsString('"esbuild": "^0.25.8"', $js->buildFiles('4.1')['package.json']);

        // the runtimes without a second generation stay as they were: wasm-go's waits for WASIp3 in
        // a Go release (componentize-go needs it)
        foreach (['wasm-go', 'wasm-python', 'wasm-exec'] as $id) {
            $this->assertSame(['4.1'], array_column($registry->require($id)->versions(), 'version'), $id);
            $this->assertSame('4.1', $registry->require($id)->defaultVersion(), $id);
        }
        $this->assertStringContainsString('spin-go-sdk/v2/http', $registry->require('wasm-go')->templateFiles('main.go', 'handler', false)['main.go']);
    }
}
