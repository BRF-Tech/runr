<?php

namespace App\Services\Ai\Models;

use App\Models\AiModelBinding;
use App\Models\AiModelSnapshot;
use App\Models\Project;
use App\Services\Ai\Engines\EngineCatalog;

/**
 * What a project gets from its model bindings at deploy (docs/huggingface.md#binding-models-to-functions):
 *
 *   RUNR_MODEL_<ALIAS>_URL          http://<service>.<namespace>.svc.cluster.local
 *   RUNR_MODEL_<ALIAS>_MODEL        served model name (the OpenAI `model` field)
 *   RUNR_MODEL_<ALIAS>_API          openai | tei | runr | custom
 *   RUNR_MODEL_<ALIAS>_TASK         pipeline tag
 *   RUNR_MODEL_<ALIAS>_OPENAI_BASE  <url>/v1 (engines with OpenAI-compatible routes)
 *   RUNR_MODEL_<ALIAS>_PATH         /models/<alias> — the model's offline snapshot, read-only
 *                                   (mount_weights, snapshot ready in the project's region)
 *   RUNR_MODELS                     the aliases, comma-separated
 */
class ModelBindingEnv
{
    public function __construct(private ModelStorage $storage) {}

    /**
     * Env vars of one binding.
     *
     * @return array<string, string>
     */
    public function envFor(AiModelBinding $binding, ?Project $project = null): array
    {
        $service = $binding->service;
        if (! $service) {
            return [];
        }

        $prefix = 'RUNR_MODEL_'.$binding->alias.'_';
        $task = $service->model?->task;
        $url = $service->internalUrl();

        $env = [
            $prefix.'URL' => $url,
            $prefix.'MODEL' => $service->served_name,
            $prefix.'API' => EngineCatalog::api($service->engine),
            $prefix.'TASK' => (string) ($task ?? ''),
        ];

        if (EngineCatalog::hasOpenAiRoutes($service->engine, $task)) {
            $env[$prefix.'OPENAI_BASE'] = $url.'/v1';
        }

        if ($binding->mount_weights && $this->weightsSnapshot($binding, $project)) {
            $env[$prefix.'PATH'] = $binding->mountPath();
        }

        return $env;
    }

    /**
     * Apply a project's bindings to a pod spec: env on the first container, the weight mounts
     * (read-only sub-paths of the region's model volume) where asked for.
     */
    public function apply(array $podSpec, Project $project): array
    {
        $bindings = $project->relationLoaded('modelBindings')
            ? $project->modelBindings
            : $project->modelBindings()->with('service.model', 'service.region')->get();

        if ($bindings->isEmpty()) {
            return $podSpec;
        }

        $container = $podSpec['containers'][0];
        $present = array_column($container['env'] ?? [], 'name');
        $aliases = [];
        $mounted = false;

        foreach ($bindings as $binding) {
            // Cluster-local URLs work inside the service's region only
            if (! $binding->service || $binding->service->knative_region_id !== $project->knative_region_id) {
                continue;
            }

            $aliases[] = $binding->alias;
            foreach ($this->envFor($binding, $project) as $name => $value) {
                if (! in_array($name, $present, true)) {
                    $container['env'][] = ['name' => $name, 'value' => $value];
                }
            }

            if ($binding->mount_weights && ($snapshot = $this->weightsSnapshot($binding, $project))) {
                $container['volumeMounts'][] = [
                    'name' => ModelStorage::VOLUME_NAME,
                    'mountPath' => $binding->mountPath(),
                    'subPath' => $snapshot->path,
                    'readOnly' => true,
                ];
                $mounted = true;
            }
        }

        if ($aliases !== [] && ! in_array('RUNR_MODELS', $present, true)) {
            $container['env'][] = ['name' => 'RUNR_MODELS', 'value' => implode(',', $aliases)];
        }

        $podSpec['containers'][0] = $container;

        if ($mounted) {
            $podSpec['volumes'][] = $this->storage->podVolume(true);
        }

        return $podSpec;
    }

    /** The ready snapshot the weights come from (the bound service's model, project's region). */
    public function weightsSnapshot(AiModelBinding $binding, ?Project $project = null): ?AiModelSnapshot
    {
        $service = $binding->service;
        $regionId = $project?->knative_region_id ?? $service?->knative_region_id;

        return $service?->model?->readySnapshotIn($regionId);
    }
}
