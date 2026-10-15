<?php

namespace App\Services\Ai\Models;

use App\Jobs\Ai\PrepullModelImagesJob;
use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Services\Ai\Gpu\GpuInventory;
use App\Services\Ai\Gpu\GpuRequest;
use App\Services\Ai\Gpu\GpuScheduling;
use App\Services\Manifests\PrepullManifests;
use App\Services\ZotRegistryService;
use Illuminate\Support\Facades\Cache;

/**
 * Pre-pulls model service images onto the nodes they run on, so the first start of a service
 * waits for its model, not for its image (the hf-serve image is about half a gigabyte; pulling it
 * was most of a first answer's 227 s). The mechanism of the region's pre-pull at registry setup
 * (PrepullManifests: crictl pull on every node through nsenter): PrepullModelImagesJob applies a
 * DaemonSet, watches it and removes it once every node has the images, or after the deadline.
 *
 *  - model storage set up for a region (PUT model-storage): the CPU hf-serve image — CPU model
 *    services, mirror Jobs and upload receivers — on the nodes projects run on;
 *  - a model service created or deployed: its engine image (hf-serve, hf-serve CUDA, TEI, vLLM,
 *    TGI) and the cache init image, on GPU nodes for a GPU service. Custom engines are not
 *    pre-pulled.
 *
 * Images are the references the pods use (through the region registry's mirror). The DaemonSet
 * runs in the region's registry namespace (internal registry), else in the AI namespace
 * (ai.gpu.namespace). One pre-pull per region and image set runs at a time.
 */
class ModelImagePrepull
{
    public function __construct(private ModelServiceManifests $manifests) {}

    /** Model storage set up: the CPU hf-serve image where projects run. Null: nothing started. */
    public function forStorage(KnativeRegion $region): ?string
    {
        return $this->start($region, [(string) config('ai.images.hf_serve')], $this->cpuPlacement($region));
    }

    /** A model service created or deployed: its engine image where it can run. Null: nothing started. */
    public function forService(AiModelService $service): ?string
    {
        $service->loadMissing('model', 'region');
        $region = $service->region;
        if (! $region || $service->engine === 'custom') {
            return null;
        }

        $image = $this->manifests->engineContainer($service, $service->model, null, $region, null)['image'];
        $images = $service->isOffline() ? [$image] : [$image, (string) config('ai.images.busybox')];

        $placement = $service->usesGpu()
            ? $this->gpuPlacement($region, GpuRequest::forService($service))
            : $this->cpuPlacement($region);

        return $this->start($region, $images, $placement);
    }

    /** The namespace of the pre-pull DaemonSets of a region. */
    public static function namespace(KnativeRegion $region): string
    {
        return $region->registry_mode === 'internal'
            ? ($region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS)
            : (string) config('ai.gpu.namespace', 'runr-gpu');
    }

    /**
     * The DaemonSet of a pre-pull (pure).
     *
     * @param  list<string>  $images  references as the pods use them
     */
    public static function daemonSet(KnativeRegion $region, string $name, array $images, array $placement): array
    {
        return PrepullManifests::daemonSet(
            $images,
            name: $name,
            helperImage: $region->registryImageRef((string) config('ai.images.busybox')),
            placement: $placement,
            tuneSysctl: false,
            labels: ['app.kubernetes.io/component' => 'model-prepull'],
        );
    }

    /** @param  list<string>  $images */
    private function start(KnativeRegion $region, array $images, array $placement): ?string
    {
        if ($region->is_demo || ! $region->is_active || ! $region->kubeconfig) {
            return null;
        }

        $images = array_values(array_unique(array_map(fn (string $image) => $region->registryImageRef($image), array_filter($images))));
        if ($images === []) {
            return null;
        }

        $name = 'runr-model-prepull-'.substr(sha1(implode(',', $images).json_encode($placement)), 0, 10);

        // A pre-pull of the same images on the same nodes is running or done recently
        if (! Cache::add(self::cacheKey($region, $name), true, PrepullModelImagesJob::DEADLINE_SECONDS)) {
            return null;
        }

        PrepullModelImagesJob::dispatch($region->id, $name, $images, $placement);

        return $name;
    }

    /** A pre-pull that could not start may be tried again at once. */
    public static function forget(KnativeRegion $region, string $name): void
    {
        Cache::forget(self::cacheKey($region, $name));
    }

    private static function cacheKey(KnativeRegion $region, string $name): string
    {
        return 'runr:model-prepull:'.$region->id.':'.$name;
    }

    /** Where CPU model services run: the dedicated project workers, else every schedulable node. */
    private function cpuPlacement(KnativeRegion $region): array
    {
        if (empty($region->selected_workers)) {
            return [];
        }

        return [
            'nodeSelector' => ['runr.dev/dedicated' => 'projects'],
            'tolerations' => [['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule']],
        ];
    }

    /** GPU nodes (GPU Feature Discovery labels), of the requested GPU type, with their usual taints tolerated. */
    private function gpuPlacement(KnativeRegion $region, ?GpuRequest $request): array
    {
        $placement = $this->cpuPlacement($region);

        $placement['affinity']['nodeAffinity']['requiredDuringSchedulingIgnoredDuringExecution']['nodeSelectorTerms'] = [
            ['matchExpressions' => [['key' => GpuInventory::LABEL_COUNT, 'operator' => 'Exists']]],
            ['matchExpressions' => [['key' => GpuInventory::LABEL_PRESENT, 'operator' => 'In', 'values' => ['true']]]],
        ];
        if ($request?->type !== null) {
            $placement['nodeSelector'] = array_merge($placement['nodeSelector'] ?? [], [GpuInventory::LABEL_PRODUCT => $request->type]);
        }
        $placement['tolerations'] = array_merge($placement['tolerations'] ?? [], GpuScheduling::TOLERATIONS);

        return $placement;
    }
}
