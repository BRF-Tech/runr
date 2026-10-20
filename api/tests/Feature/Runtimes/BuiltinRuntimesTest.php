<?php

namespace Tests\Feature\Runtimes;

use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\XaasConfig;
use App\Services\ProjectService;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The built-in runtimes are plugins of the same mechanism, and existing projects
 * (projects.runtime = python / nodejs / go / php / tensorflow / pytorch) keep exactly what they
 * had before the manifests: the same starter code, dependency files, handler checks,
 * extensions and Go wrapper sources. The migration gives every built-in a runtimes row.
 */
class BuiltinRuntimesTest extends TestCase
{
    use RefreshDatabase;

    /** Starter templates as the services hard-coded them before the runtime manifests */
    private const PREVIOUS_TEMPLATES = [
        'python' => "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"Hello, World!\"}",
        'nodejs' => "exports.handler = async (event, context) => {\n    return { statusCode: 200, body: 'Hello, World!' };\n};",
        'go' => "package main\n\n// Handler is the entry point for this function.\nfunc Handler(event interface{}, context interface{}) (interface{}, error) {\n\treturn map[string]interface{}{\n\t\t\"statusCode\": 200,\n\t\t\"body\":       \"Hello, World!\",\n\t}, nil\n}",
        'php' => "<?php\n\nfunction handler(\$event, \$context): array\n{\n    return ['statusCode' => 200, 'body' => 'Hello, World!'];\n}",
        'tensorflow' => "import tensorflow as tf\n\ndef handler(event, context):\n    \"\"\"TensorFlow inference handler\"\"\"\n    tensor = tf.constant([[1.0, 2.0], [3.0, 4.0]])\n    result = tf.reduce_mean(tensor).numpy()\n    return {\"statusCode\": 200, \"body\": f\"TensorFlow {tf.__version__} — mean: {result}\"}",
        'pytorch' => "import torch\n\ndef handler(event, context):\n    \"\"\"PyTorch inference handler\"\"\"\n    tensor = torch.tensor([[1.0, 2.0], [3.0, 4.0]])\n    result = tensor.mean().item()\n    return {\"statusCode\": 200, \"body\": f\"PyTorch {torch.__version__} — mean: {result}\"}",
    ];

    /** Dependency file + default content of new FaaS projects, as before */
    private const PREVIOUS_DEPENDENCIES = [
        'python' => ['requirements.txt', "redis>=5.0\n"],
        'nodejs' => ['package.json', '{"dependencies":{"ioredis":"^5"}}'],
        'php' => ['composer.json', '{"require":{"predis/predis":"^2.0"}}'],
        'tensorflow' => ['requirements.txt', ''],
        'pytorch' => ['requirements.txt', ''],
        'go' => [null, null],
    ];

    /** CodeController's handler checks, as before */
    private const PREVIOUS_PATTERNS = [
        'python' => '/def\s+handler\s*\(/',
        'nodejs' => '/(?:exports\.handler|module\.exports\.handler|function\s+handler|const\s+handler)/',
        'go' => '/func\s+Handler\s*\(/',
        'php' => '/function\s+handler\s*\(/',
    ];

    public function test_the_bundled_catalog_matches_runtime_manifests(): void
    {
        $dir = BuiltinCatalog::sourceDir();
        if (! $dir) {
            $this->markTestSkipped('the catalog sources (runtimes/ of BRF-Tech/runr-runtimes) are not here: set RUNR_RUNTIME_CATALOG');
        }

        $this->assertSame(
            file_get_contents(BuiltinCatalog::bundlePath()),
            app(BuiltinCatalog::class)->bundleJson($dir),
            'resources/runtimes/builtin.json is out of date: run php artisan runtimes:bundle',
        );
    }

    public function test_the_bundle_alone_serves_the_same_catalog(): void
    {
        config(['executor.catalog_path' => sys_get_temp_dir().'/runr-no-catalog-'.uniqid()]);
        BuiltinCatalog::flush();

        try {
            $fromBundle = array_map(fn ($m) => $m->toArray(), app(BuiltinCatalog::class)->all());
        } finally {
            config(['executor.catalog_path' => null]);
            BuiltinCatalog::flush();
        }

        $this->assertSame(['python', 'nodejs', 'go', 'php', 'tensorflow', 'pytorch', 'wasm-exec', 'wasm-go', 'wasm-js', 'wasm-python', 'wasm-rust'], array_keys($fromBundle));
        if (BuiltinCatalog::sourceDir()) {
            $this->assertEquals(array_map(fn ($m) => $m->toArray(), app(BuiltinCatalog::class)->all()), $fromBundle);
        }
    }

    public function test_the_migration_gives_every_built_in_a_row(): void
    {
        $rows = Runtime::orderBy('id')->get();

        $this->assertSame(['go', 'nodejs', 'php', 'python', 'pytorch', 'tensorflow'], $rows->pluck('id')->all());
        $this->assertTrue($rows->every(fn (Runtime $r) => $r->source === Runtime::SOURCE_BUILTIN && $r->is_enabled && $r->manifest === null && $r->regions === null));
    }

    public function test_built_in_templates_and_dependencies_are_unchanged(): void
    {
        $registry = app(RuntimeRegistry::class);

        foreach (self::PREVIOUS_TEMPLATES as $runtime => $template) {
            $m = $registry->require($runtime);
            $this->assertSame($template, $m->templateFiles($m->defaultFilename(), 'handler', false)[$m->defaultFilename()], $runtime);

            [$depFile, $depContent] = self::PREVIOUS_DEPENDENCIES[$runtime];
            $this->assertSame($depFile, $m->dependencyFile(), $runtime);
            if ($depFile) {
                $this->assertSame($depContent, $m->templateFiles('main.x', 'handler', true)[$depFile], $runtime);
            }
        }

        $this->assertSame(['py', 'js', 'go', 'php', 'py', 'py'], array_map(
            fn ($id) => $registry->require($id)->extension(),
            ['python', 'nodejs', 'go', 'php', 'tensorflow', 'pytorch'],
        ));
    }

    public function test_built_in_handler_checks_are_unchanged(): void
    {
        $registry = app(RuntimeRegistry::class);

        foreach (self::PREVIOUS_PATTERNS as $runtime => $previous) {
            $pattern = $registry->require($runtime)->handlerPattern('anything');
            foreach ([self::PREVIOUS_TEMPLATES[$runtime], 'def other(x): pass', 'const handler = () => 1', 'func Handler(e interface{})'] as $code) {
                $this->assertSame(preg_match($previous, $code), preg_match($pattern, $code), "{$runtime}: {$code}");
            }
        }

        $this->assertNull($registry->require('tensorflow')->handlerPattern());
        $this->assertNull($registry->require('pytorch')->handlerPattern());
    }

    public function test_go_builds_with_the_wrapper_sources_of_the_runtime_directory(): void
    {
        $files = app(RuntimeRegistry::class)->require('go')->build()['files']['runr_wrapper_.go'];
        $dir = BuiltinCatalog::sourceDir();

        $this->assertStringContainsString('result, err := Handler(event, context)', $files['http']);
        $this->assertStringContainsString('event := map[string]interface{}{"trigger": "schedule"}', $files['schedule']);
        if ($dir) {
            $this->assertSame(str_replace("\r\n", "\n", file_get_contents("{$dir}/go/wrapper_http.go")), $files['http']);
            $this->assertSame(str_replace("\r\n", "\n", file_get_contents("{$dir}/go/wrapper_schedule.go")), $files['schedule']);
        }
    }

    public function test_existing_projects_get_the_same_default_code(): void
    {
        $region = KnativeRegion::create(['name' => 'op', 'is_active' => true, 'is_demo' => true]);
        $service = app(ProjectService::class);

        // FaaS python: main.py + requirements.txt, as before
        $faas = Project::create(['name' => 'legacy-faas', 'service_type' => 'faas', 'runtime' => 'python']);
        $faas->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $faas->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);
        $this->assertSame(['main.py' => self::PREVIOUS_TEMPLATES['python'], 'requirements.txt' => "redis>=5.0\n"], $service->getDefaultFiles($faas->fresh('faasConfig')));

        // XaaS go with a custom handler name: func Run
        $xaas = Project::create(['name' => 'legacy-go', 'service_type' => 'xaas', 'runtime' => 'go']);
        $xaas->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        XaasConfig::create(['project_id' => $xaas->id, 'filename' => 'main.go', 'handler' => 'run', 'trigger_type' => 'http']);
        $this->assertStringContainsString('func Run(event interface{}', $service->getDefaultCode($xaas->fresh('xaasConfig')));

        // An unknown runtime keeps the generic placeholder
        $unknown = Project::create(['name' => 'legacy-unknown', 'service_type' => 'xaas', 'runtime' => 'cobol']);
        XaasConfig::create(['project_id' => $unknown->id, 'filename' => 'main.cob', 'handler' => 'handler', 'trigger_type' => 'http']);
        $this->assertSame('# Hello, World!'.PHP_EOL, $service->getDefaultCode($unknown->fresh('xaasConfig')));
    }

    public function test_compiled_and_build_rules_of_the_built_ins(): void
    {
        $registry = app(RuntimeRegistry::class);

        $this->assertTrue($registry->require('go')->isCompiled());
        foreach (['python', 'nodejs', 'php', 'tensorflow', 'pytorch'] as $id) {
            $m = $registry->require($id);
            $this->assertFalse($m->isCompiled(), $id);
            $this->assertSame('http', $m->runMode(), $id);
            $this->assertSame('native', $m->scheduleMode(), $id);
            $this->assertFalse($m->usesWrapper(), $id);
        }

        $this->assertFalse($registry->require('python')->needsBuild(['main.py' => 'x']));
        $this->assertTrue($registry->require('python')->needsBuild(['main.py' => 'x', 'requirements.txt' => '']));
    }

    public function test_executor_runtimes_limits_the_offered_built_ins(): void
    {
        config(['executor.runtimes' => ['python', 'go']]);

        $this->assertSame(['python', 'go'], app(RuntimeRegistry::class)->builtinIds());
        $this->assertSame(['python', 'go'], app(RuntimeRegistry::class)->visible()->pluck('id')->all());
        $this->assertNotNull(app(RuntimeRegistry::class)->unavailableReason('php'));
        $this->assertNull(app(RuntimeRegistry::class)->unavailableReason('python'));
    }
}
