<?php

namespace App\Services\Concerns;

use App\Services\KubernetesClient;
use App\Services\Manifests\PrepullManifests;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Support\Facades\Log;

trait ZotPrepullTrait
{
    /**
     * Pre-pull required images on all cluster nodes via a DaemonSet.
     * Combines static images from config, the universal wrapper and the active versions of the
     * built-in and platform runtimes (RuntimeRegistry::prepullImages()).
     */
    public function prepullImages(KubernetesClient $k8s, string $ns, bool $workersOnly = false): void
    {
        $configImages = config('knative.prepull_images', []);
        $executorImages = app(RuntimeRegistry::class)->prepullImages();
        $images = array_values(array_unique(array_merge($configImages, [(string) config('executor.wrapper_image')], $executorImages)));

        if (empty($images)) {
            return;
        }

        Log::info('Pre-pulling images on cluster nodes', ['count' => count($images), 'workersOnly' => $workersOnly]);

        $manifest = PrepullManifests::daemonSet($images, $workersOnly);
        $k8s->applyResource($manifest, $ns);

        $ready = $this->waitFor(function () use ($k8s, $ns) {
            $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', 'runr-image-prepull', $ns);
            if (! $ds) {
                return false;
            }
            $desired = $ds['status']['desiredNumberScheduled'] ?? 0;
            $current = $ds['status']['numberReady'] ?? 0;

            return $desired > 0 && $current >= $desired;
        }, 300);

        try {
            $k8s->deleteResource('apps/v1', 'DaemonSet', 'runr-image-prepull', $ns);
        } catch (\Throwable) {
        }

        if ($ready) {
            Log::info('All images pre-pulled successfully');
        } else {
            Log::warning('Image pre-pull did not complete within 300s');
        }
    }
}
