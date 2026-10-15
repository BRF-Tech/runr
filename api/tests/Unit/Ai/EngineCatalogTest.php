<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Engines\EngineCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Serving engine per task and device (vLLM / TGI for text generation on GPUs, TEI for
 * embeddings, RunR's transformers server for the other pipelines and small text generation on
 * CPU, diffusers for text-to-image), size-based defaults, playground forms and invoke paths.
 */
class EngineCatalogTest extends TestCase
{
    public function test_text_generation_picks_vllm_on_gpu_and_transformers_on_cpu(): void
    {
        $this->assertSame('vllm', EngineCatalog::recommended('text-generation', 'transformers', [], 'gpu'));
        $this->assertSame('transformers', EngineCatalog::recommended('text-generation', 'transformers', [], 'cpu'));

        $engines = array_column(EngineCatalog::enginesFor('text-generation', 'transformers'), null, 'engine');
        $this->assertTrue($engines['vllm']['recommended']);
        $this->assertArrayHasKey('tgi', $engines, 'TGI as the alternative');
        $this->assertSame(['gpu'], $engines['tgi']['devices']);
        $this->assertSame('openai', $engines['tgi']['api']);
        $this->assertArrayHasKey('custom', $engines);
    }

    public function test_embeddings_use_tei_for_compatible_models(): void
    {
        $this->assertSame('tei', EngineCatalog::recommended('feature-extraction', 'sentence-transformers', [], 'cpu'));
        $this->assertSame('tei', EngineCatalog::recommended('sentence-similarity', 'transformers', ['text-embeddings-inference'], 'gpu'));
        $this->assertSame('transformers', EngineCatalog::recommended('feature-extraction', 'transformers', [], 'cpu'), 'not marked TEI-compatible');
    }

    public function test_text_to_image_needs_a_gpu_and_diffusers(): void
    {
        $this->assertSame('diffusers', EngineCatalog::recommended('text-to-image', 'diffusers', [], 'gpu'));
        $this->assertNull(EngineCatalog::recommended('text-to-image', 'diffusers', [], 'cpu'));

        $engines = array_column(EngineCatalog::enginesFor('text-to-image', 'diffusers'), 'engine');
        $this->assertContains('diffusers', $engines);
        $this->assertNotContains('vllm', $engines);
    }

    public function test_other_pipelines_run_on_the_transformers_server(): void
    {
        foreach (['text-classification', 'token-classification', 'summarization', 'automatic-speech-recognition', 'image-classification', 'fill-mask'] as $task) {
            $this->assertSame('transformers', EngineCatalog::recommended($task, 'transformers', [], 'cpu'), $task);
            $this->assertSame('transformers', EngineCatalog::recommended($task, null, [], 'gpu'), $task);
        }

        $this->assertNull(EngineCatalog::recommended('text-classification', 'flair', [], 'cpu'), 'other libraries: custom image');
        $this->assertNull(EngineCatalog::recommended(null, null, [], 'cpu'));
    }

    public function test_engine_task_and_device_support(): void
    {
        $this->assertTrue(EngineCatalog::servesTask('custom', 'anything'));
        $this->assertFalse(EngineCatalog::servesTask('tei', 'text-generation'));
        $this->assertTrue(EngineCatalog::supportsDevice('tei', 'cpu'));
        $this->assertFalse(EngineCatalog::supportsDevice('vllm', 'cpu'));
        $this->assertSame('/health', EngineCatalog::healthPath('vllm'));
        $this->assertSame('/healthz', EngineCatalog::healthPath('transformers'));
        $this->assertNull(EngineCatalog::healthPath('custom'));
    }

    public function test_suggestion_moves_large_text_generation_models_to_a_gpu(): void
    {
        $small = EngineCatalog::suggest(['task' => 'text-generation', 'library' => 'transformers', 'size_bytes' => 500 * 1024 ** 2], false);
        $this->assertSame('cpu', $small['device'], 'a region without GPUs keeps small models on CPU');
        $this->assertSame('transformers', $small['engine']);

        $large = EngineCatalog::suggest(['task' => 'text-generation', 'library' => 'transformers', 'size_bytes' => 14 * 1024 ** 3], true);
        $this->assertSame('gpu', $large['device']);
        $this->assertSame('vllm', $large['engine']);
        $this->assertContains('large_for_cpu', $large['warnings']);
        $this->assertTrue($large['suggest_min_scale']);
        $this->assertSame(1, $large['gpu']['count']);

        $noGpu = EngineCatalog::suggest(['task' => 'text-generation', 'library' => 'transformers', 'size_bytes' => 14 * 1024 ** 3], false);
        $this->assertContains('gpu_required', $noGpu['warnings']);
    }

    public function test_suggestion_warnings_for_unknown_engines_and_sizes(): void
    {
        $unknown = EngineCatalog::suggest(['task' => 'graph-ml', 'library' => 'dgl']);
        $this->assertNull($unknown['engine']);
        $this->assertContains('unsupported_library', $unknown['warnings']);
        $this->assertContains('unknown_size', $unknown['warnings']);
    }

    public function test_sizing_follows_the_model_size_within_bounds(): void
    {
        $tiny = EngineCatalog::sizing('transformers', 'cpu', 10 * 1024 ** 2);
        $this->assertSame('1', $tiny['cpu']);
        $this->assertSame(1024, $tiny['memory_mb'], 'lower bound');
        $this->assertNull($tiny['gpu']);

        $big = EngineCatalog::sizing('transformers', 'cpu', 4 * 1024 ** 3);
        $this->assertSame('2', $big['cpu']);
        $this->assertGreaterThan(8192, $big['memory_mb']);
        $this->assertSame(0, $big['memory_mb'] % 256);

        $gpu = EngineCatalog::sizing('vllm', 'gpu', 8 * 1024 ** 3);
        $this->assertSame('2', $gpu['cpu']);
        $this->assertSame(1, $gpu['gpu']['count']);
        $this->assertGreaterThan(8192, $gpu['gpu']['memory_min_hint_mb'], 'weights plus headroom');
        $this->assertLessThanOrEqual(65536, EngineCatalog::sizing('vllm', 'gpu', 500 * 1024 ** 3)['memory_mb'], 'upper bound');

        $tei = EngineCatalog::sizing('tei', 'cpu', null);
        $this->assertGreaterThanOrEqual(1024, $tei['memory_mb']);
    }

    public function test_playground_forms_and_invoke_paths(): void
    {
        $this->assertSame(['kind' => 'chat', 'path' => '/v1/chat/completions'], EngineCatalog::playground('vllm', 'text-generation'));
        $this->assertSame('embeddings', EngineCatalog::playground('tei', 'feature-extraction')['kind']);
        $this->assertSame('asr', EngineCatalog::playground('transformers', 'automatic-speech-recognition')['kind']);
        $this->assertSame('text-to-image', EngineCatalog::playground('diffusers', 'text-to-image')['kind']);
        $this->assertSame('token-classification', EngineCatalog::playground('transformers', 'token-classification')['kind']);
        $this->assertSame('generic', EngineCatalog::playground('custom', null)['kind']);

        $this->assertContains('/v1/embeddings', EngineCatalog::invokePaths('tei'));
        $this->assertNotContains('/v1/images/generations', EngineCatalog::invokePaths('vllm'));
        $this->assertSame(['*'], EngineCatalog::invokePaths('custom'));
    }

    public function test_openai_routes_and_tei_image_architecture(): void
    {
        $this->assertTrue(EngineCatalog::hasOpenAiRoutes('vllm', 'text-generation'));
        $this->assertTrue(EngineCatalog::hasOpenAiRoutes('transformers', 'feature-extraction'));
        $this->assertFalse(EngineCatalog::hasOpenAiRoutes('transformers', 'token-classification'));
        $this->assertFalse(EngineCatalog::hasOpenAiRoutes('custom', null));

        $this->assertSame('89-', EngineCatalog::teiArch('8.9'));
        $this->assertSame('turing-', EngineCatalog::teiArch('7.5'));
        $this->assertSame('', EngineCatalog::teiArch('8.0'));
        $this->assertSame('hopper-', EngineCatalog::teiArch('9.0'));
        $this->assertSame('86-', EngineCatalog::teiArch(null));
    }

    public function test_catalog_lists_engines_and_task_defaults(): void
    {
        $catalog = EngineCatalog::catalog();

        $this->assertSame(['gpu'], $catalog['engines']['vllm']['devices']);
        $tasks = array_column($catalog['tasks'], null, 'task');
        $this->assertSame(['cpu' => 'transformers', 'gpu' => 'vllm'], $tasks['text-generation']['default']);
        $this->assertSame(['cpu' => null, 'gpu' => 'diffusers'], $tasks['text-to-image']['default']);
        $this->assertContains('tei', $tasks['feature-extraction']['engines']);
    }
}
