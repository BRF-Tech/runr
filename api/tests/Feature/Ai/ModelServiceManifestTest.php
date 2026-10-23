<?php

namespace Tests\Feature\Ai;

use App\Models\AiModelBinding;
use App\Models\AiModelSnapshot;
use App\Models\AiModelUpload;
use App\Models\Project;
use App\Services\Ai\Models\ModelServiceManifests;
use App\Services\InternalNetwork\InternalNetworkManifests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Manifests of model services and model files: the engine container per engine (vLLM / TGI /
 * TEI / hf-serve / custom), online mode (Hub id + pinned commit, cache sub-path of the model
 * volume, HF_TOKEN from the Secret, init container), offline mode (read-only snapshot,
 * HF_HUB_OFFLINE / TRANSFORMERS_OFFLINE, default-deny egress NetworkPolicy), GPU model services,
 * the mirror / import / cleanup Jobs and the upload receiver.
 */
class ModelServiceManifestTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private function manifests(): ModelServiceManifests
    {
        return app(ModelServiceManifests::class);
    }

    private function env(array $container): array
    {
        $out = [];
        foreach ($container['env'] as $e) {
            $out[$e['name']] = $e['value'] ?? $e['valueFrom'];
        }

        return $out;
    }

    public function test_an_online_tei_service_downloads_into_the_model_cache(): void
    {
        $region = $this->gpuRegion();
        $model = $this->hubModel();
        $service = $this->modelService($model, $region, ['engine' => 'tei', 'device' => 'cpu', 'mode' => 'online']);

        $ksvc = $this->manifests()->knativeService($service, $model, null, $region, 'fn', 'runr-hf-token');

        $this->assertSame('serving.knative.dev/v1', $ksvc['apiVersion']);
        $this->assertSame($service->service_name, $ksvc['metadata']['name']);
        $this->assertSame('cluster-local', $ksvc['metadata']['labels']['networking.knative.dev/visibility']);
        $this->assertSame($service->id, $ksvc['spec']['template']['metadata']['labels']['runr.sh/model-service']);

        $spec = $ksvc['spec']['template']['spec'];
        $container = $spec['containers'][0];
        $env = $this->env($container);

        $this->assertSame('ghcr.io/huggingface/text-embeddings-inference:cpu-1.9.4', $container['image']);
        $this->assertSame([
            '--model-id', 'sentence-transformers/all-MiniLM-L6-v2', '--served-model-name', 'all-minilm-l6-v2', '--hostname', '0.0.0.0', '--port', '8080',
            '--revision', str_repeat('a', 40),
        ], $container['args'], 'the pinned commit, not the branch');
        $this->assertSame([['name' => 'http1', 'containerPort' => 8080]], $container['ports']);
        $this->assertSame('/health', $container['readinessProbe']['httpGet']['path']);

        $this->assertSame('/models/hf', $env['HF_HOME']);
        $this->assertSame('/models/hf/hub', $env['HUGGINGFACE_HUB_CACHE']);
        $this->assertArrayNotHasKey('HF_HUB_OFFLINE', $env);
        $this->assertSame(['secretKeyRef' => ['name' => 'runr-hf-token', 'key' => 'HF_TOKEN', 'optional' => true]], $env['HF_TOKEN']);

        $this->assertSame([['name' => 'runr-models', 'mountPath' => '/models/hf', 'subPath' => 'hf/default']], $container['volumeMounts']);
        $this->assertSame([['name' => 'runr-models', 'persistentVolumeClaim' => ['claimName' => 'runr-models']]], $spec['volumes']);
        $this->assertSame('models-init', $spec['initContainers'][0]['name'], 'makes the shared cache writable for every engine user');
        $this->assertSame('hf/default', $spec['initContainers'][0]['volumeMounts'][0]['subPath']);

        $this->assertSame('1800s', $ksvc['spec']['template']['metadata']['annotations']['serving.knative.dev/progress-deadline']);
    }

    /** The internal network (docs/internal-network.md): the projects bound to a model service may call it */
    public function test_the_bound_projects_may_call_the_service(): void
    {
        $region = $this->gpuRegion();
        $model = $this->hubModel();
        $service = $this->modelService($model, $region, ['engine' => 'tei', 'device' => 'cpu', 'mode' => 'online']);
        foreach (['chat', 'search'] as $name) {
            $project = Project::create(['name' => $name, 'service_type' => 'caas']);
            AiModelBinding::create(['project_id' => $project->id, 'ai_model_service_id' => $service->id, 'alias' => "embed-{$name}"]);
        }

        $ksvc = $this->manifests()->knativeService($service, $model, null, $region, 'fn', 'runr-hf-token');

        $this->assertSame('managed', $ksvc['metadata']['labels'][InternalNetworkManifests::LABEL_MANAGED]);
        $this->assertSame('model', $ksvc['metadata']['annotations'][InternalNetworkManifests::ANNOTATION_KIND]);
        $this->assertSame('chat.platform,search.platform', $ksvc['metadata']['annotations'][InternalNetworkManifests::ANNOTATION_CALLERS]);
        $this->assertSame("{$service->service_name}.platform", $ksvc['metadata']['annotations'][InternalNetworkManifests::ANNOTATION_NAME]);
        $this->assertSame('cluster-local', $ksvc['metadata']['labels']['networking.knative.dev/visibility'], 'never public');
    }

    public function test_an_offline_service_mounts_its_snapshot_read_only_without_network(): void
    {
        $region = $this->gpuRegion();
        $model = $this->hubModel();
        $service = $this->modelService($model, $region, ['engine' => 'transformers', 'mode' => 'offline']);
        $snapshot = AiModelSnapshot::create([
            'ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'source' => 'hub',
            'revision' => str_repeat('a', 40), 'path' => 'snapshots/default/all-minilm-l6-v2/'.str_repeat('a', 40), 'status' => 'ready',
        ]);

        $ksvc = $this->manifests()->knativeService($service, $model, $snapshot, $region, 'fn', 'runr-hf-token');
        $spec = $ksvc['spec']['template']['spec'];
        $container = $spec['containers'][0];
        $env = $this->env($container);

        $this->assertSame('ghcr.io/brf-tech/runr/hf-serve:1.1.1', $container['image']);
        $this->assertSame('/models/model', $env['RUNR_MODEL_PATH']);
        $this->assertSame('feature-extraction', $env['RUNR_TASK']);
        $this->assertSame('cpu', $env['RUNR_DEVICE']);
        $this->assertSame('1', $env['HF_HUB_OFFLINE']);
        $this->assertSame('1', $env['TRANSFORMERS_OFFLINE']);
        $this->assertArrayNotHasKey('HF_TOKEN', $env, 'offline services never get the token');
        $this->assertArrayNotHasKey('HF_HOME', $env);
        $this->assertSame([['name' => 'runr-models', 'mountPath' => '/models/model', 'subPath' => $snapshot->path, 'readOnly' => true]], $container['volumeMounts']);
        $this->assertSame([['name' => 'runr-models', 'persistentVolumeClaim' => ['claimName' => 'runr-models', 'readOnly' => true]]], $spec['volumes']);
        $this->assertArrayNotHasKey('initContainers', $spec);
        $this->assertSame('/healthz', $container['readinessProbe']['httpGet']['path']);

        $policy = $this->manifests()->networkPolicy($service, 'fn');
        $this->assertSame('NetworkPolicy', $policy['kind']);
        $this->assertSame($service->service_name.'-egress', $policy['metadata']['name']);
        $this->assertSame(['matchLabels' => ['runr.sh/model-service' => $service->id]], $policy['spec']['podSelector']);
        $this->assertSame(['Egress'], $policy['spec']['policyTypes']);
        $this->assertCount(1, $policy['spec']['egress'], 'DNS only');
        $this->assertSame([['protocol' => 'UDP', 'port' => 53], ['protocol' => 'TCP', 'port' => 53]], $policy['spec']['egress'][0]['ports']);
        $this->assertSame('kube-dns', $policy['spec']['egress'][0]['to'][0]['podSelector']['matchLabels']['k8s-app']);
    }

    public function test_a_gpu_vllm_service_is_scheduled_on_gpus(): void
    {
        $region = $this->gpuRegion([$this->gpuNode('gpu-a', 2)]);
        $region->update(['gpu_settings' => ['runtime_class' => 'nvidia']]);
        $model = $this->hubModel(['name' => 'qwen', 'hub_id' => 'Qwen/Qwen2.5-0.5B-Instruct', 'task' => 'text-generation', 'library' => 'transformers']);
        $service = $this->modelService($model, $region, [
            'engine' => 'vllm', 'device' => 'gpu', 'gpu' => ['count' => 2, 'type' => 'NVIDIA-L4'], 'served_name' => 'qwen',
            'args' => ['--max-model-len', '4096'], 'env_vars' => ['VLLM_LOGGING_LEVEL' => 'DEBUG'],
        ]);

        $ksvc = $this->manifests()->knativeService($service, $model, null, $region->fresh(), 'fn', null);
        $spec = $ksvc['spec']['template']['spec'];
        $container = $spec['containers'][0];
        $env = $this->env($container);

        $this->assertSame('docker.io/vllm/vllm-openai:v0.6.6', $container['image']);
        $this->assertSame([
            '--model', 'Qwen/Qwen2.5-0.5B-Instruct', '--served-model-name', 'qwen', '--host', '0.0.0.0', '--port', '8080',
            '--revision', str_repeat('a', 40), '--tensor-parallel-size', '2', '--max-model-len', '4096',
        ], $container['args'], 'user arguments are appended');
        $this->assertSame('DEBUG', $env['VLLM_LOGGING_LEVEL']);
        $this->assertArrayNotHasKey('HF_TOKEN', $env, 'no token configured');
        $this->assertSame('2', $container['resources']['limits']['nvidia.com/gpu']);
        $this->assertSame('NVIDIA-L4', $spec['nodeSelector']['nvidia.com/gpu.product']);
        $this->assertSame('nvidia', $spec['runtimeClassName']);
        $this->assertSame('5m', $ksvc['spec']['template']['metadata']['annotations']['autoscaling.knative.dev/scale-to-zero-pod-retention-period']);
    }

    public function test_tgi_tei_gpu_and_custom_engines(): void
    {
        $region = $this->gpuRegion();
        $llm = $this->hubModel(['name' => 'llm', 'hub_id' => 'acme/llm', 'task' => 'text-generation', 'library' => 'transformers']);

        $tgi = $this->manifests()->engineContainer(
            $this->modelService($llm, $region, ['engine' => 'tgi', 'device' => 'gpu', 'gpu' => ['count' => 1]]), $llm, null, $region, null,
        );
        $env = $this->env($tgi);
        $this->assertSame('ghcr.io/huggingface/text-generation-inference:3.0.1', $tgi['image']);
        $this->assertSame(['acme/llm', str_repeat('a', 40)], [$env['MODEL_ID'], $env['REVISION']]);
        $this->assertArrayNotHasKey('PORT', $env, 'Knative sets PORT to the container port');
        $this->assertArrayNotHasKey('NUM_SHARD', $env);
        $this->assertArrayNotHasKey('args', $tgi);

        // TEI GPU image matches the GPU's compute capability (8.9 → 89-)
        $emb = $this->hubModel(['name' => 'emb']);
        $tei = $this->manifests()->engineContainer($this->modelService($emb, $region, ['engine' => 'tei', 'device' => 'gpu', 'gpu' => ['count' => 1]]), $emb, null, $region, null);
        $this->assertSame('ghcr.io/huggingface/text-embeddings-inference:89-1.9.4', $tei['image']);

        $custom = $this->manifests()->engineContainer(
            $this->modelService($llm, $region, ['engine' => 'custom', 'image' => 'ghcr.io/acme/server:2', 'port' => 9000, 'args' => ['--serve'], 'health_path' => '/ready']),
            $llm, null, $region, null,
        );
        $this->assertSame('ghcr.io/acme/server:2', $custom['image']);
        $this->assertSame(['--serve'], $custom['args']);
        $this->assertSame([['name' => 'http1', 'containerPort' => 9000]], $custom['ports']);
        $this->assertSame('/ready', $custom['readinessProbe']['httpGet']['path']);
        $this->assertSame('acme/llm', $this->env($custom)['RUNR_MODEL_ID']);

        // an image override of a built-in engine
        $override = $this->manifests()->engineContainer(
            $this->modelService($llm, $region, ['engine' => 'transformers', 'image' => 'registry.example.com/hf-serve:pinned']), $llm, null, $region, null,
        );
        $this->assertSame('registry.example.com/hf-serve:pinned', $override['image']);
    }

    /**
     * Knative refuses a Service whose container sets PORT, K_SERVICE, K_CONFIGURATION or
     * K_REVISION ("PORT is a reserved environment variable"): TGI and hf-serve services set PORT,
     * so they never reached the cluster. Knative sets PORT to the container port itself.
     */
    public function test_no_engine_sets_a_variable_knative_reserves(): void
    {
        $region = $this->gpuRegion();
        $model = $this->hubModel(['name' => 'llm', 'hub_id' => 'acme/llm', 'task' => 'text-generation', 'library' => 'transformers']);

        foreach (['vllm' => 'gpu', 'tgi' => 'gpu', 'tei' => 'cpu', 'transformers' => 'cpu', 'diffusers' => 'gpu', 'custom' => 'cpu'] as $engine => $device) {
            $attributes = ['engine' => $engine, 'device' => $device, 'name' => "svc-{$engine}"]
                + ($device === 'gpu' ? ['gpu' => ['count' => 1]] : [])
                + ($engine === 'custom' ? ['image' => 'ghcr.io/acme/server:2', 'port' => 9000] : []);
            $ksvc = $this->manifests()->knativeService($this->modelService($model, $region, $attributes), $model, null, $region, 'fn', 'runr-hf-token');
            $container = $ksvc['spec']['template']['spec']['containers'][0];

            $this->assertSame([], array_values(array_intersect(array_keys($this->env($container)), ['PORT', 'K_SERVICE', 'K_CONFIGURATION', 'K_REVISION'])), $engine);
            $this->assertSame('http1', $container['ports'][0]['name'], $engine);
        }
    }

    public function test_the_mirror_job_downloads_the_pinned_revision(): void
    {
        $region = $this->gpuRegion();
        $model = $this->hubModel();
        $snapshot = AiModelSnapshot::create([
            'ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'source' => 'hub',
            'revision' => str_repeat('a', 40), 'path' => 'snapshots/default/all-minilm-l6-v2/aaaa', 'status' => 'pending',
        ]);

        $job = $this->manifests()->mirrorJob($snapshot, $model, $region, 'fn', 'runr-hf-token');
        $container = $job['spec']['template']['spec']['containers'][0];

        $this->assertSame('batch/v1', $job['apiVersion']);
        $this->assertSame('model-mirror-'.strtolower($snapshot->id), $job['metadata']['name']);
        $this->assertSame(1, $job['spec']['backoffLimit']);
        $this->assertSame(7200, $job['spec']['activeDeadlineSeconds']);
        $this->assertSame(['python', '-m', 'runr_hf_serve', 'mirror', '--repo', 'sentence-transformers/all-MiniLM-L6-v2', '--revision', str_repeat('a', 40), '--dest', '/snapshot'], $container['command']);
        $this->assertContains(['name' => 'runr-models', 'mountPath' => '/snapshot', 'subPath' => $snapshot->path], $container['volumeMounts']);
        $this->assertSame(0, $container['securityContext']['runAsUser']);
        $this->assertSame(['secretKeyRef' => ['name' => 'runr-hf-token', 'key' => 'HF_TOKEN', 'optional' => true]], $this->env($container)['HF_TOKEN']);
        $this->assertSame('Never', $job['spec']['template']['spec']['restartPolicy']);
    }

    public function test_imports_from_the_model_volume_and_an_oci_artifact(): void
    {
        $region = $this->gpuRegion(null, ['registry_mode' => 'internal', 'registry_address' => '10.43.0.5:5000', 'registry_status' => 'ready']);

        $pvcModel = $this->hubModel(['name' => 'local-bert', 'source' => 'pvc', 'hub_id' => null, 'source_ref' => 'bert/v1']);
        $snapshot = AiModelSnapshot::create(['ai_model_id' => $pvcModel->id, 'knative_region_id' => $region->id, 'source' => 'pvc', 'revision' => 'local', 'path' => 'snapshots/default/local-bert/local', 'status' => 'pending']);
        $job = $this->manifests()->importJob($snapshot, $pvcModel, $region, 'fn');
        $main = $job['spec']['template']['spec']['containers'][0];
        $this->assertContains(['name' => 'runr-models', 'mountPath' => '/source', 'subPath' => 'imports/default/bert/v1', 'readOnly' => true], $main['volumeMounts']);
        $this->assertStringContainsString('cp -a /source/. /snapshot/', $main['command'][2]);
        $this->assertStringContainsString('RUNR_SNAPSHOT', $main['command'][2]);

        $ociModel = $this->hubModel(['name' => 'oci-bert', 'source' => 'oci', 'hub_id' => null, 'source_ref' => '10.43.0.5:5000/models/bert:v1']);
        $snapshot = AiModelSnapshot::create(['ai_model_id' => $ociModel->id, 'knative_region_id' => $region->id, 'source' => 'oci', 'revision' => 'local', 'path' => 'snapshots/default/oci-bert/local', 'status' => 'pending']);
        $job = $this->manifests()->importJob($snapshot, $ociModel, $region, 'fn', 'runr-models-registry');
        $pull = $job['spec']['template']['spec']['initContainers'][0];
        $this->assertSame('ghcr.io/oras-project/oras:v1.2.0', $pull['image']);
        $this->assertSame(['pull', '10.43.0.5:5000/models/bert:v1', '--output', '/snapshot', '--plain-http', '--registry-config', '/auth/config.json'], $pull['args']);
        $this->assertContains(['name' => 'registry-auth', 'secret' => ['secretName' => 'runr-models-registry', 'items' => [['key' => '.dockerconfigjson', 'path' => 'config.json']]]], $job['spec']['template']['spec']['volumes']);
    }

    public function test_cleanup_job_and_upload_receiver(): void
    {
        $region = $this->gpuRegion(null, ['selected_workers' => ['gpu-a']]);
        $model = $this->hubModel(['source' => 'upload', 'hub_id' => null]);
        $snapshot = AiModelSnapshot::create(['ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'source' => 'upload', 'revision' => 'local', 'path' => 'snapshots/default/all-minilm-l6-v2/local', 'status' => 'running']);

        $cleanup = $this->manifests()->cleanupJob($snapshot, $region, 'fn');
        $c = $cleanup['spec']['template']['spec']['containers'][0];
        $this->assertSame(['sh', '-c', 'rm -rf -- "/parent/local"'], $c['command']);
        $this->assertSame('snapshots/default/all-minilm-l6-v2', $c['volumeMounts'][0]['subPath']);
        $this->assertSame(['runr.dev/dedicated' => 'projects'], $cleanup['spec']['template']['spec']['nodeSelector'], 'where the projects run');

        $upload = AiModelUpload::create(['ai_model_id' => $model->id, 'knative_region_id' => $region->id, 'files' => [['path' => 'model.safetensors', 'size' => 10]], 'chunk_size' => 8]);
        $pod = $this->manifests()->receiverPod($upload, $snapshot, $region, 'fn');
        $svc = $this->manifests()->receiverService($upload, 'fn');

        $this->assertSame('model-recv-'.strtolower($upload->id), $pod['metadata']['name']);
        $this->assertSame(['python', '-m', 'runr_hf_serve', 'receive', '--root', '/snapshot'], $pod['spec']['containers'][0]['command']);
        $this->assertSame($snapshot->path, $pod['spec']['containers'][0]['volumeMounts'][0]['subPath']);
        $this->assertSame(['runr.sh/model-upload' => $upload->id], $svc['spec']['selector']);
        $this->assertSame($pod['metadata']['name'], $svc['metadata']['name']);
    }

    public function test_engine_threads_follow_the_cpu_limit(): void
    {
        $region = $this->gpuRegion();
        $model = $this->hubModel(['task' => 'text-classification', 'library' => 'transformers']);
        $container = fn (array $attributes) => $this->manifests()->engineContainer(
            $this->modelService($model, $region, $attributes), $model, null, $region, null,
        );

        // hf-serve under a 1-CPU limit: one thread, not one per node CPU
        $one = $container(['engine' => 'transformers', 'cpu' => '1']);
        $this->assertSame('1', $this->env($one)['OMP_NUM_THREADS']);
        $this->assertSame('1', $this->env($one)['MKL_NUM_THREADS']);
        $this->assertSame('1', $one['resources']['limits']['cpu']);

        $this->assertSame('2', $this->env($container(['engine' => 'transformers', 'cpu' => '1500m']))['OMP_NUM_THREADS'], 'ceil(1.5)');
        $this->assertSame('1', $this->env($container(['engine' => 'tei', 'cpu' => '250m']))['OMP_NUM_THREADS'], 'at least one');
        $this->assertSame('4', $this->env($container(['engine' => 'tei', 'cpu' => '4']))['MKL_NUM_THREADS']);

        $own = $container(['engine' => 'transformers', 'cpu' => '2', 'env_vars' => ['OMP_NUM_THREADS' => '1']]);
        $this->assertSame('1', $this->env($own)['OMP_NUM_THREADS'], 'the service env wins');
        $this->assertSame('2', $this->env($own)['MKL_NUM_THREADS']);
        $this->assertCount(1, array_filter($own['env'], fn ($e) => $e['name'] === 'OMP_NUM_THREADS'));

        $this->assertSame(['OMP_NUM_THREADS' => '3', 'MKL_NUM_THREADS' => '3'], ModelServiceManifests::threadEnv('2.2'));
        $this->assertSame(['OMP_NUM_THREADS' => '1', 'MKL_NUM_THREADS' => '1'], ModelServiceManifests::threadEnv(''));
    }

    public function test_an_engine_that_crashes_leaves_its_log_tail_as_termination_message(): void
    {
        $region = $this->gpuRegion();
        $model = $this->hubModel();

        foreach (['tei', 'transformers'] as $engine) {
            $container = $this->manifests()->engineContainer($this->modelService($model, $region, ['engine' => $engine]), $model, null, $region, null);
            $this->assertSame('FallbackToLogsOnError', $container['terminationMessagePolicy'], $engine);
        }
    }
}
