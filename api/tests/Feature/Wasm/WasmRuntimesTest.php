<?php

namespace Tests\Feature\Wasm;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Wasm\WasmRuntimes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The four WebAssembly runtime plugins (runtime/wasm-*): valid built-in manifests on the Spin
 * shim's RuntimeClass, their starter templates, and where they are offered — the catalog with
 * ?region=, project creation and deploys only in regions whose WebAssembly support is ready.
 */
class WasmRuntimesTest extends TestCase
{
    use RefreshDatabase;

    private const WASM = ['wasm-rust', 'wasm-go', 'wasm-js', 'wasm-python'];

    private function registry(): RuntimeRegistry
    {
        return app(RuntimeRegistry::class);
    }

    /** Built-in rows + their versions, as executor:sync makes them (no Docker Hub here). */
    private function syncCatalog(): void
    {
        Http::fake(fn () => Http::response(['message' => 'not found'], 404));
        $this->artisan('executor:sync')->assertExitCode(0);
        $this->registry()->forget();
    }

    private function user(): User
    {
        return User::factory()->create(['username' => 'u'.bin2hex(random_bytes(3))]);
    }

    public function test_the_manifests_are_valid_built_ins_on_the_spin_runtime_class(): void
    {
        foreach (self::WASM as $id) {
            $m = $this->registry()->require($id);

            $this->assertSame('wasmtime-spin-v2', $m->runtimeClass(), $id);
            $this->assertTrue(WasmRuntimes::isWasm($m), $id);
            $this->assertSame('spin', WasmRuntimes::shimOf($m)->id, $id);
            $this->assertSame('http', $m->runMode(), $id);
            $this->assertSame('native', $m->scheduleMode(), $id);
            $this->assertFalse($m->usesWrapper(), "{$id}: the image is a Spin artifact, no wrapper baked in");
            $this->assertTrue($m->isCompiled(), $id);
            $this->assertSame(['http', 'schedule', 'queue'], $m->triggers(), $id);
            $this->assertSame(['SPIN_HTTP_LISTEN_ADDR' => '0.0.0.0:8080'], $m->runEnv(), $id);
            $this->assertSame(['cpu' => '100m', 'memory_mb' => 128], $m->resources(), $id);
            $this->assertStringContainsString('spin build', $m->build()['command'], $id);
            $this->assertArrayHasKey('spin.toml', $m->build()['files'], $id);
            $this->assertStringContainsString('route = "/..."', $m->build()['files']['spin.toml'], "{$id}: @spin.toml inlined");
            $this->assertStringStartsWith('data:image/svg+xml;base64,', $m->icon(), $id);

            $version = $m->versions()[0];
            $this->assertSame(['4.1', "ghcr.io/brf-tech/exec-{$id}:4.1", true], [$version['version'], $version['image'], $version['default']], $id);
        }
    }

    public function test_the_starter_templates_pass_their_handler_check_and_answer_every_trigger(): void
    {
        $expect = [
            'wasm-rust' => ['lib.rs', 'from Rust, Wasm'],
            'wasm-go' => ['main.go', 'from Go, Wasm'],
            'wasm-js' => ['index.js', 'from JavaScript, Wasm'],
            'wasm-python' => ['app.py', 'from Python, Wasm'],
        ];

        foreach ($expect as $id => [$file, $marker]) {
            $m = $this->registry()->require($id);
            $files = $m->templateFiles($m->defaultFilename(), $m->handlerFunction(), false);

            $this->assertSame($file, $m->defaultFilename(), $id);
            $code = $files[$file];
            $this->assertMatchesRegularExpression($m->handlerPattern(), $code, $id);
            foreach (['dev.runr.schedule.tick', 'dev.runr.queue.message', 'ce-type', $marker] as $needle) {
                $this->assertStringContainsString($needle, $code, "{$id}: {$needle}");
            }
            // the example of the language is the starter code
            $example = base_path('../examples/wasm/'.str_replace('wasm-', '', $id)."/{$file}");
            if (is_file($example)) {
                $this->assertSame(trim(str_replace("\r\n", "\n", file_get_contents($example))), trim($code), "{$id}: examples/wasm");
            }
        }
    }

    public function test_the_runtime_directories_carry_what_the_manifests_reference(): void
    {
        $dir = BuiltinCatalog::sourceDir();
        if (! $dir) {
            $this->markTestSkipped('runtime/ is not part of this checkout');
        }

        foreach (self::WASM as $id) {
            $this->assertFileExists("{$dir}/{$id}/Dockerfile");
            $raw = Yaml::parseFile("{$dir}/{$id}/runtime.yaml");
            foreach ($raw['build']['files'] as $name => $ref) {
                $this->assertFileExists("{$dir}/{$id}/".ltrim($ref, '@'), "{$id}: {$name}");
            }
            // the build image ships the Spin CLI of the runtime the shim embeds
            $this->assertStringContainsString('ARG SPIN_VERSION=4.1.0', file_get_contents("{$dir}/{$id}/Dockerfile"), $id);
        }
        // the same manifests validate as built-ins from their source (next to wasm-exec, the
        // executor of uploaded .wasm modules: docs/images.md)
        $this->assertEqualsCanonicalizing(
            [...self::WASM, 'wasm-exec'],
            array_values(array_map(fn ($m) => $m['id'], array_filter(app(BuiltinCatalog::class)->readSources($dir), fn ($m) => str_starts_with($m['id'], 'wasm-')))),
        );
        $this->assertInstanceOf(ManifestValidator::class, app(ManifestValidator::class));
    }

    public function test_executor_sync_creates_the_static_versions_of_the_wasm_built_ins(): void
    {
        $this->syncCatalog();

        foreach (self::WASM as $id) {
            $image = ExecutorImage::where('runtime', $id)->sole();
            $this->assertSame(['4.1', "ghcr.io/brf-tech/exec-{$id}:4.1", true, true], [$image->version, $image->docker_image, $image->is_default, $image->is_active], $id);
        }
        // their images are build toolchains: never pre-pulled onto the nodes
        $this->assertSame([], array_filter($this->registry()->prepullImages(), fn ($i) => str_contains($i, 'exec-wasm-')));
    }

    public function test_the_catalog_offers_wasm_runtimes_only_in_regions_with_webassembly_support(): void
    {
        $this->syncCatalog();
        $wasm = KnativeRegion::create(['name' => 'wasm', 'is_active' => true, 'sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']]);
        $plain = KnativeRegion::create(['name' => 'plain', 'is_active' => true]);
        $busy = KnativeRegion::create(['name' => 'busy', 'is_active' => true, 'sandbox_wasm_status' => 'installing', 'sandbox_wasm_shims' => ['spin']]);
        $user = $this->user();

        $ids = fn (?KnativeRegion $r) => array_column(
            $this->actingAs($user, 'sanctum')->getJson('/api/runtimes'.($r ? "?region={$r->id}" : ''))->assertOk()->json('data'),
            'wasm',
            'id',
        );

        $inWasm = $ids($wasm);
        foreach (self::WASM as $id) {
            $this->assertSame('spin', $inWasm[$id] ?? null, "{$id} in a region with WebAssembly");
        }
        $this->assertNull($inWasm['python']);
        $this->assertSame([], array_intersect(self::WASM, array_keys($ids($plain))));
        $this->assertSame([], array_intersect(self::WASM, array_keys($ids($busy))));
        // without a region (Settings → Runtimes) every runtime is listed
        $this->assertEqualsCanonicalizing(self::WASM, array_values(array_intersect(array_keys($ids(null)), self::WASM)));
    }

    public function test_creating_and_deploying_a_wasm_project_needs_the_region_support(): void
    {
        $this->syncCatalog();
        $plain = KnativeRegion::create(['name' => 'plain', 'is_active' => true]);
        $wasm = KnativeRegion::create(['name' => 'wasm', 'is_active' => true, 'sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']]);
        $only = KnativeRegion::create(['name' => 'wasmtime-only', 'is_active' => true, 'sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['wasmtime']]);

        $reason = $this->registry()->unavailableReason('wasm-rust', $plain->id, 'http');
        $this->assertStringContainsString('needs WebAssembly support with the Spin shim in region plain', $reason);
        $this->assertNotNull($this->registry()->unavailableReason('wasm-rust', $only->id));
        $this->assertNull($this->registry()->unavailableReason('wasm-rust', $wasm->id, 'queue'));
        $this->assertNull($this->registry()->unavailableReason('python', $plain->id));

        $this->assertStringContainsString('WebAssembly', $this->registry()->deployBlockedReason('wasm-js', $plain->id));
        $this->assertNull($this->registry()->deployBlockedReason('wasm-js', $wasm->id));
    }

    public function test_the_catalog_entry_shows_the_shim_and_the_turkish_reason(): void
    {
        $this->syncCatalog();
        $plain = KnativeRegion::create(['name' => 'plain', 'is_active' => true]);

        $entry = collect($this->actingAs($this->user(), 'sanctum')->getJson('/api/runtimes')->json('data'))->firstWhere('id', 'wasm-python');
        $this->assertSame(['spin', 'wasmtime-spin-v2', 'Python (Wasm)'], [$entry['wasm'], $entry['runtime_class'], $entry['name']]);

        app()->setLocale('tr');
        $this->assertStringContainsString('WebAssembly desteği ister', app(WasmRuntimes::class)->unavailableReason($this->registry()->require('wasm-python'), $plain->id));
    }
}
