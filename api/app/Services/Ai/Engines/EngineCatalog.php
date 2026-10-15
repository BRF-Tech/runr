<?php

namespace App\Services\Ai\Engines;

/**
 * Serving engines of model services and which tasks (Hugging Face pipeline tags) they serve.
 *
 * | engine       | devices  | API                                        |
 * |--------------|----------|--------------------------------------------|
 * | vllm         | gpu      | OpenAI-compatible (/v1/chat/completions …) |
 * | tgi          | gpu      | OpenAI-compatible Messages API + /generate  |
 * | tei          | cpu, gpu | /embed, /v1/embeddings, /rerank            |
 * | transformers | cpu, gpu | RunR hf-serve: /predict (+ OpenAI routes)  |
 * | diffusers    | gpu      | RunR hf-serve: /predict, /v1/images/…      |
 * | custom       | cpu, gpu | whatever the image serves                  |
 *
 * Defaults per task: text generation → vLLM on a GPU (TGI as the alternative), RunR's
 * transformers server on CPU for small models; embeddings → TEI (CPU or GPU); text-to-image →
 * diffusers on a GPU; every other transformers pipeline → RunR's transformers server.
 */
class EngineCatalog
{
    public const TRANSFORMERS_TASKS = [
        'text-classification', 'token-classification', 'summarization', 'translation',
        'text2text-generation', 'fill-mask', 'question-answering', 'zero-shot-classification',
        'automatic-speech-recognition', 'image-classification', 'object-detection',
        'feature-extraction', 'sentence-similarity', 'text-generation',
    ];

    public const ENGINES = [
        'vllm' => [
            'label' => 'vLLM',
            'devices' => ['gpu'],
            'api' => 'openai',
            'tasks' => ['text-generation'],
            'health' => '/health',
        ],
        'tgi' => [
            'label' => 'Text Generation Inference',
            'devices' => ['gpu'],
            'api' => 'openai',
            'tasks' => ['text-generation'],
            'health' => '/health',
        ],
        'tei' => [
            'label' => 'Text Embeddings Inference',
            'devices' => ['cpu', 'gpu'],
            'api' => 'tei',
            'tasks' => ['feature-extraction', 'sentence-similarity'],
            'health' => '/health',
        ],
        'transformers' => [
            'label' => 'Transformers (RunR hf-serve)',
            'devices' => ['cpu', 'gpu'],
            'api' => 'runr',
            'tasks' => self::TRANSFORMERS_TASKS,
            'health' => '/healthz',
        ],
        'diffusers' => [
            'label' => 'Diffusers (RunR hf-serve)',
            'devices' => ['gpu'],
            'api' => 'runr',
            'tasks' => ['text-to-image'],
            'health' => '/healthz',
        ],
        'custom' => [
            'label' => 'Custom image',
            'devices' => ['cpu', 'gpu'],
            'api' => 'custom',
            'tasks' => ['*'],
            'health' => null,
        ],
    ];

    /** Default engine per task and device (null = the task does not run there). */
    public const DEFAULTS = [
        'text-generation' => ['cpu' => 'transformers', 'gpu' => 'vllm'],
        'feature-extraction' => ['cpu' => 'tei', 'gpu' => 'tei'],
        'sentence-similarity' => ['cpu' => 'tei', 'gpu' => 'tei'],
        'text-to-image' => ['cpu' => null, 'gpu' => 'diffusers'],
    ];

    /** Text generation on CPU is offered up to this model size (larger models need a GPU). */
    public const CPU_TEXT_GENERATION_MAX_BYTES = 3 * 1024 ** 3;

    public static function exists(string $engine): bool
    {
        return isset(self::ENGINES[$engine]);
    }

    public static function definition(string $engine): array
    {
        return self::ENGINES[$engine] ?? self::ENGINES['custom'];
    }

    public static function api(string $engine): string
    {
        return self::definition($engine)['api'];
    }

    public static function healthPath(string $engine): ?string
    {
        return self::definition($engine)['health'];
    }

    /** The engine serves the task (custom serves anything). */
    public static function servesTask(string $engine, ?string $task): bool
    {
        $tasks = self::definition($engine)['tasks'];

        return in_array('*', $tasks, true) || ($task !== null && in_array($task, $tasks, true));
    }

    public static function supportsDevice(string $engine, string $device): bool
    {
        return in_array($device, self::definition($engine)['devices'], true);
    }

    /** OpenAI-compatible routes (RUNR_MODEL_<ALIAS>_OPENAI_BASE): vLLM, TGI, TEI embeddings, hf-serve's text generation / embeddings. */
    public static function hasOpenAiRoutes(string $engine, ?string $task): bool
    {
        return match ($engine) {
            'vllm', 'tgi', 'tei' => true,
            'transformers' => in_array($task, ['text-generation', 'feature-extraction', 'sentence-similarity'], true),
            'diffusers' => true,
            default => false,
        };
    }

    /**
     * Engines for a task, best first: [{engine, devices, recommended, api}].
     *
     * @param  list<string>  $tags  Hub tags (text-embeddings-inference / text-generation-inference mark compatible models)
     */
    public static function enginesFor(?string $task, ?string $library = null, array $tags = []): array
    {
        $out = [];

        foreach (self::ENGINES as $engine => $def) {
            if ($engine === 'custom' || ! self::servesTask($engine, $task)) {
                continue;
            }
            if ($engine === 'diffusers' && $library !== null && $library !== 'diffusers') {
                continue;
            }
            if (in_array($engine, ['vllm', 'tgi', 'transformers'], true) && $library === 'diffusers') {
                continue;
            }

            $out[] = [
                'engine' => $engine,
                'devices' => $def['devices'],
                'recommended' => false,
                'api' => $def['api'],
            ];
        }

        $recommended = self::recommended($task, $library, $tags, 'gpu') ?? self::recommended($task, $library, $tags, 'cpu');
        foreach ($out as &$entry) {
            $entry['recommended'] = $entry['engine'] === $recommended;
        }
        unset($entry);

        usort($out, fn ($a, $b) => (int) $b['recommended'] <=> (int) $a['recommended']);

        $out[] = ['engine' => 'custom', 'devices' => ['cpu', 'gpu'], 'recommended' => $out === [], 'api' => 'custom'];

        return $out;
    }

    /** The engine RunR picks for a task on a device ("auto"), null when none serves it there. */
    public static function recommended(?string $task, ?string $library, array $tags, string $device): ?string
    {
        if ($task === null) {
            return null;
        }

        if (in_array($task, ['feature-extraction', 'sentence-similarity'], true)) {
            // TEI where the Hub marks the model compatible (or sentence-transformers models);
            // anything else through the transformers pipeline
            $tei = in_array('text-embeddings-inference', $tags, true) || $library === 'sentence-transformers';

            return $tei ? 'tei' : 'transformers';
        }

        if ($task === 'text-generation' && $device === 'gpu') {
            return 'vllm';
        }

        if ($task === 'text-to-image') {
            return $device === 'gpu' && ($library === null || $library === 'diffusers') ? 'diffusers' : null;
        }

        if (isset(self::DEFAULTS[$task])) {
            return self::DEFAULTS[$task][$device];
        }

        if (in_array($task, self::TRANSFORMERS_TASKS, true) && ($library === null || in_array($library, ['transformers', 'sentence-transformers'], true))) {
            return 'transformers';
        }

        return null;
    }

    /**
     * Suggested deployment of a model: engine, device and size.
     *
     * @param  array{task?: ?string, library?: ?string, tags?: array, size_bytes?: ?int}  $model
     * @return array{engine: ?string, device: string, cpu: string, memory_mb: int, gpu: ?array, suggest_min_scale: bool, warnings: list<string>}
     */
    public static function suggest(array $model, bool $regionHasGpu = true): array
    {
        $task = $model['task'] ?? null;
        $library = $model['library'] ?? null;
        $tags = $model['tags'] ?? [];
        $size = $model['size_bytes'] ?? null;
        $warnings = [];

        $cpuEngine = self::recommended($task, $library, $tags, 'cpu');
        $gpuEngine = self::recommended($task, $library, $tags, 'gpu');

        if ($task === 'text-generation' && $size !== null && $size > self::CPU_TEXT_GENERATION_MAX_BYTES) {
            $cpuEngine = null;
            $warnings[] = 'large_for_cpu';
        }

        $device = match (true) {
            $cpuEngine === null && $gpuEngine !== null => 'gpu',
            $task === 'text-generation' && $regionHasGpu && $gpuEngine !== null => 'gpu',
            default => 'cpu',
        };
        $engine = $device === 'gpu' ? $gpuEngine : $cpuEngine;

        if ($engine === null) {
            $warnings[] = 'unsupported_library';
        }
        if ($device === 'gpu' && ! $regionHasGpu) {
            $warnings[] = 'gpu_required';
        }
        if ($size === null) {
            $warnings[] = 'unknown_size';
        }

        $sizing = self::sizing($engine ?? 'custom', $device, $size);

        return [
            'engine' => $engine,
            'device' => $device,
            'cpu' => $sizing['cpu'],
            'memory_mb' => $sizing['memory_mb'],
            'gpu' => $sizing['gpu'],
            'suggest_min_scale' => self::suggestMinScale($device, $size),
            'warnings' => $warnings,
        ];
    }

    /**
     * Default size of a model service: CPU, memory and — on a GPU — the GPU count and a hint of
     * the GPU memory the weights need (weights plus room for activations / KV cache). Guidance
     * from the model size, not a measurement.
     *
     * @return array{cpu: string, memory_mb: int, gpu: ?array{count: int, memory_min_hint_mb: int}}
     */
    public static function sizing(string $engine, string $device, ?int $sizeBytes): array
    {
        $sizeMb = $sizeBytes !== null ? (int) ceil($sizeBytes / (1024 * 1024)) : 512;
        $round = fn (float $mb) => (int) (ceil($mb / 256) * 256);
        $clamp = fn (int $v, int $min, int $max) => max($min, min($max, $v));

        if ($device === 'gpu') {
            return [
                'cpu' => '2',
                'memory_mb' => $clamp($round($sizeMb * 1.3 + 2048), 4096, 65536),
                'gpu' => [
                    'count' => 1,
                    'memory_min_hint_mb' => $round($sizeMb * 1.2 + (in_array($engine, ['vllm', 'tgi', 'diffusers'], true) ? 1024 : 512)),
                ],
            ];
        }

        $memory = $engine === 'tei'
            ? $clamp($round($sizeMb * 2 + 512), 1024, 16384)
            : $clamp($round($sizeMb * 2.5 + 768), 1024, 32768);

        return [
            'cpu' => $sizeMb > 1500 ? '2' : '1',
            'memory_mb' => $memory,
            'gpu' => null,
        ];
    }

    /** Big models and GPU services start slowly from zero: suggest keeping one instance warm. */
    public static function suggestMinScale(string $device, ?int $sizeBytes): bool
    {
        return $device === 'gpu' || ($sizeBytes !== null && $sizeBytes > 2 * 1024 ** 3);
    }

    /**
     * Playground form of a service: what the UI asks for and which path it calls.
     *
     * @return array{kind: string, path: string}
     */
    public static function playground(string $engine, ?string $task): array
    {
        if (in_array($engine, ['vllm', 'tgi'], true)) {
            return ['kind' => 'chat', 'path' => '/v1/chat/completions'];
        }
        if ($engine === 'tei') {
            return ['kind' => 'embeddings', 'path' => '/v1/embeddings'];
        }
        if ($engine === 'custom') {
            return ['kind' => 'generic', 'path' => '/'];
        }

        return match ($task) {
            'text-generation' => ['kind' => 'chat', 'path' => '/v1/chat/completions'],
            'feature-extraction', 'sentence-similarity' => ['kind' => 'embeddings', 'path' => '/v1/embeddings'],
            'text-to-image' => ['kind' => 'text-to-image', 'path' => '/v1/images/generations'],
            'text-classification', 'zero-shot-classification' => ['kind' => 'classification', 'path' => '/predict'],
            'token-classification' => ['kind' => 'token-classification', 'path' => '/predict'],
            'summarization', 'text2text-generation' => ['kind' => 'summarization', 'path' => '/predict'],
            'translation' => ['kind' => 'translation', 'path' => '/predict'],
            'fill-mask' => ['kind' => 'fill-mask', 'path' => '/predict'],
            'question-answering' => ['kind' => 'question-answering', 'path' => '/predict'],
            'automatic-speech-recognition' => ['kind' => 'asr', 'path' => '/predict'],
            'image-classification', 'object-detection' => ['kind' => 'image-classification', 'path' => '/predict'],
            default => ['kind' => 'generic', 'path' => '/predict'],
        };
    }

    /** Paths the playground may call on a service (invoke endpoint allow-list). */
    public static function invokePaths(string $engine): array
    {
        return match ($engine) {
            'vllm' => ['/v1/chat/completions', '/v1/completions', '/v1/embeddings', '/v1/models', '/health'],
            'tgi' => ['/v1/chat/completions', '/v1/completions', '/generate', '/info', '/health'],
            'tei' => ['/embed', '/v1/embeddings', '/rerank', '/predict', '/info', '/health'],
            'transformers', 'diffusers' => ['/predict', '/v1/embeddings', '/v1/chat/completions', '/v1/completions', '/v1/images/generations', '/v1/models', '/info', '/healthz'],
            default => ['*'],
        };
    }

    /** Catalog for the UI: engines and, per task, the engines and defaults. */
    public static function catalog(): array
    {
        $tasks = array_values(array_unique(array_merge(self::TRANSFORMERS_TASKS, array_keys(self::DEFAULTS))));
        sort($tasks);

        return [
            'engines' => array_map(fn ($def) => [
                'label' => $def['label'],
                'devices' => $def['devices'],
                'api' => $def['api'],
                'tasks' => $def['tasks'],
            ], self::ENGINES),
            'tasks' => array_map(fn (string $task) => [
                'task' => $task,
                'engines' => array_column(self::enginesFor($task), 'engine'),
                'default' => [
                    'cpu' => self::recommended($task, null, [], 'cpu'),
                    'gpu' => self::recommended($task, null, [], 'gpu'),
                ],
            ], $tasks),
        ];
    }

    /**
     * TEI GPU image tag prefix from the GPU's compute capability: turing-, "" (Ampere 8.0), 86-,
     * 89-, hopper-, Blackwell 100- / 120- / 121-; cuda- (the image that picks its kernels when it
     * starts) for any other or an unknown GPU.
     */
    public static function teiArch(?string $computeCapability): string
    {
        return match ($computeCapability) {
            '7.5' => 'turing-',
            '8.0' => '',
            '8.6' => '86-',
            '8.9' => '89-',
            '9.0' => 'hopper-',
            '10.0' => '100-',
            '12.0' => '120-',
            '12.1' => '121-',
            default => 'cuda-',
        };
    }
}
