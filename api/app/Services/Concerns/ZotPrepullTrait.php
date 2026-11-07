<?php

namespace App\Services\Concerns;

use App\Services\KubernetesClient;
use App\Services\Manifests\PrepullManifests;
use App\Services\Runtimes\RuntimePullSecrets;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Support\Facades\Log;

trait ZotPrepullTrait
{
    /**
     * Pre-pull required images on all cluster nodes via a DaemonSet.
     * Combines static images from config, the universal wrapper and the images the functions of
     * the active versions of the built-in and platform runtimes run on — a build's run image, not
     * its SDK (RuntimeRegistry::prepullRunImages()).
     */
    public function prepullImages(KubernetesClient $k8s, string $ns, bool $workersOnly = false): void
    {
        $configImages = config('knative.prepull_images', []);
        $executorImages = app(RuntimeRegistry::class)->prepullRunImages();
        $images = array_values(array_unique(array_merge($configImages, [(string) config('executor.wrapper_image')], array_keys($executorImages))));

        if (empty($images)) {
            return;
        }

        Log::info('Pre-pulling images on cluster nodes', ['count' => count($images), 'workersOnly' => $workersOnly]);

        // Images of a private runtime source: its registry credentials from a Secret (crictl --creds)
        [$credentials, $secret] = $this->prepullCredentials($k8s, $ns, $executorImages);

        $manifest = PrepullManifests::daemonSet($images, $workersOnly, credentials: $credentials, credentialSecret: $secret);
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
            if ($secret !== null) {
                $k8s->deleteResource('v1', 'Secret', $secret, $ns);
            }
        } catch (\Throwable) {
        }

        if ($ready) {
            Log::info('All images pre-pulled successfully');
        } else {
            Log::warning('Image pre-pull did not complete within 300s');
        }
    }

    /**
     * The pre-pull Secret of the private runtime images among $images (image => its key) and its
     * name; none when every image is public.
     *
     * @param  array<string, string>  $images  image => the runtime it is of
     * @return array{0: array<string, string>, 1: string|null}
     */
    private function prepullCredentials(KubernetesClient $k8s, string $ns, array $images): array
    {
        $pullSecrets = app(RuntimePullSecrets::class);
        $credentials = [];
        $data = [];
        foreach ($images as $image => $runtime) {
            $image = (string) $image;
            $source = $pullSecrets->forImage($runtime, $image);
            if ($source === null) {
                continue;
            }
            $key = 's'.strtolower($source->id);
            $credentials[$image] = $key;
            $data[$key] = base64_encode($source->credential('registry', 'username').':'.$source->credential('registry', 'password'));
        }
        if ($data === []) {
            return [[], null];
        }

        $name = PrepullManifests::NAME.'-creds';
        $k8s->applyResource([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'Opaque',
            'metadata' => ['name' => $name, 'namespace' => $ns, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            'data' => $data,
        ], $ns);

        return [$credentials, $name];
    }
}
