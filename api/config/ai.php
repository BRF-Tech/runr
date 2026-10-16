<?php

use App\Support\Images\RegistryMove;

/*
|--------------------------------------------------------------------------
| AI: GPU support and Hugging Face models (docs/ai.md, docs/huggingface.md)
|--------------------------------------------------------------------------
| Images are pulled by the region nodes (through the region registry's mirror for Docker Hub
| names). Set them to a registry the regions can reach for air-gapped installations.
*/

return [

    'huggingface' => [
        // Hub API used by the console's model browser and for revision lookups; a private
        // mirror with the same API works too. HF_ENDPOINT is also given to model pods and
        // mirror Jobs (online downloads).
        'endpoint' => rtrim((string) env('AI_HF_ENDPOINT', 'https://huggingface.co'), '/'),
        // false: the console does not call the Hub (air-gapped consoles); models are uploaded
        // or imported, browsing answers 503
        'browse_enabled' => (bool) env('AI_HF_BROWSE', true),
        'timeout' => (int) env('AI_HF_TIMEOUT', 15),
        // Search / model info answers are cached this many seconds
        'cache_seconds' => (int) env('AI_HF_CACHE_SECONDS', 300),
    ],

    'images' => [
        'hf_serve' => RegistryMove::ref(env('AI_IMAGE_HF_SERVE', 'ghcr.io/brf-tech/runr/hf-serve:1.1.0')),
        'hf_serve_cuda' => RegistryMove::ref(env('AI_IMAGE_HF_SERVE_CUDA', 'ghcr.io/brf-tech/runr/hf-serve:1.1.0-cuda')),
        'vllm' => env('AI_IMAGE_VLLM', 'docker.io/vllm/vllm-openai:v0.6.6'),
        'tgi' => env('AI_IMAGE_TGI', 'ghcr.io/huggingface/text-generation-inference:3.0.1'),
        // TEI 1.6.1+ follows the Hub's relative redirects on resolve (1.5 fails: "relative URL without a base")
        'tei_cpu' => env('AI_IMAGE_TEI_CPU', 'ghcr.io/huggingface/text-embeddings-inference:cpu-1.9.4'),
        // {arch} from the GPU's compute capability: turing- | "" (Ampere 8.0) | 86- | 89- | hopper- |
        // 100- | 120- | 121- (Blackwell) | cuda- (any other: picks its kernels at start)
        'tei_gpu' => env('AI_IMAGE_TEI_GPU', 'ghcr.io/huggingface/text-embeddings-inference:{arch}1.9.4'),
        'oras' => env('AI_IMAGE_ORAS', 'ghcr.io/oras-project/oras:v1.2.0'),
        'busybox' => RegistryMove::ref(env('AI_IMAGE_BUSYBOX', 'ghcr.io/brf-tech/runr/reqs/busybox:latest')),
    ],

    'gpu' => [
        'namespace' => env('AI_GPU_NAMESPACE', 'runr-gpu'),
        // NVIDIA device plugin Helm chart (with GPU Feature Discovery + Node Feature Discovery)
        'device_plugin_repo' => env('AI_GPU_DEVICE_PLUGIN_REPO', 'https://nvidia.github.io/k8s-device-plugin'),
        'device_plugin_chart' => env('AI_GPU_DEVICE_PLUGIN_CHART_VERSION', '0.17.0'),
        // NVIDIA GPU Operator Helm chart (driver + container toolkit + device plugin on nodes without them)
        'operator_repo' => env('AI_GPU_OPERATOR_REPO', 'https://helm.ngc.nvidia.com/nvidia'),
        'operator_chart' => env('AI_GPU_OPERATOR_CHART_VERSION', 'v24.9.2'),
        'resource' => 'nvidia.com/gpu',
        'max_count' => 8,
        'max_time_slicing_replicas' => 16,
        // Default timeout (seconds) of GPU projects and model services: model loading and large
        // CUDA images take longer than a function cold start
        'default_timeout' => (int) env('AI_GPU_DEFAULT_TIMEOUT', 300),
        // Revision start-up deadline of GPU workloads and model services (image pull + model load)
        'progress_deadline' => env('AI_PROGRESS_DEADLINE', '1800s'),
        // GPU pods stay this long after their last request before they scale to zero
        'scale_to_zero_retention' => env('AI_GPU_SCALE_TO_ZERO_RETENTION', '5m'),
        // Regions without GPU nodes are re-checked every N minutes by gpu:refresh
        'refresh_idle_minutes' => (int) env('AI_GPU_REFRESH_IDLE_MINUTES', 30),
    ],

    'models' => [
        'pvc_name' => 'runr-models',
        'storage_size_gi' => (int) env('AI_MODEL_STORAGE_GI', 100),
        'storage_access_mode' => env('AI_MODEL_STORAGE_ACCESS_MODE', 'ReadWriteMany'),
        'storage_class' => env('AI_MODEL_STORAGE_CLASS', ''),
        // Uploads into air-gapped regions
        'upload_chunk_bytes' => (int) env('AI_UPLOAD_CHUNK_BYTES', 8 * 1024 * 1024),
        'upload_max_files' => 500,
        'upload_ttl_hours' => (int) env('AI_UPLOAD_TTL_HOURS', 24),
        // Mirror / import Jobs
        'mirror_deadline_seconds' => (int) env('AI_MIRROR_DEADLINE_SECONDS', 7200),
        // Playground requests through the Kubernetes API server
        'invoke_timeout' => (int) env('AI_INVOKE_TIMEOUT', 120),
        'invoke_max_bytes' => 4 * 1024 * 1024,
    ],
];
