<?php

namespace App\Services\Runtimes;

use App\Models\KnativeRegion;
use App\Models\Runtime;
use App\Models\RuntimeSource;
use App\Services\KubernetesClient;
use App\Services\SupplyChain\ImageRef;
use App\Support\Images\OciRegistryClient;
use Illuminate\Support\Facades\Log;

/**
 * Private runtime images (docs/runtimes.md#private-sources): a runtime of a source with registry
 * credentials whose image is on that registry (registry_host, default the catalog's host) is
 * pulled with them everywhere RunR pulls a runtime image —
 *
 *  - function pods (Knative Service, CronJob) of code-mounted versions: imagePullSecrets
 *    (RuntimeContainerSpec);
 *  - function builds and runtime image builds: Kaniko's DOCKER_CONFIG for the FROM pulls, which
 *    then go to the registry directly instead of through the region registry's pull-through
 *    (FunctionBuildService, RuntimeImageBuildService); WebAssembly toolchain Jobs and sandboxes:
 *    imagePullSecrets;
 *  - the node pre-pull: crictl --creds from a Secret (ZotPrepullTrait); the registry prewarm leaves
 *    them out (its pull-through has no credentials).
 *
 * The Secret is RunR's (label runr.sh/runtime-source, kubernetes.io/dockerconfigjson), named after
 * the source, applied into the namespace before the workload that needs it — so new credentials
 * reach the next deploy or build. Its value never leaves the cluster's API: it is not part of the
 * deployment's manifests, logs or audit.
 */
class RuntimePullSecrets
{
    public const LABEL = 'runr.sh/runtime-source';

    /** @var array<string, RuntimeSource|false> */
    private array $sources = [];

    /** The Secret's name of a source. */
    public static function secretName(RuntimeSource $source): string
    {
        return 'runr-rtsrc-'.strtolower($source->id);
    }

    /**
     * The source whose credentials $image of the runtime needs, null when it is pulled anonymously.
     */
    public function forImage(?string $runtimeId, string $image): ?RuntimeSource
    {
        if ($runtimeId === null || $runtimeId === '' || trim($image) === '') {
            return null;
        }

        $source = $this->sourceOf($runtimeId);
        if ($source === null) {
            return null;
        }

        return OciRegistryClient::normalizeHost(self::host($image)) === OciRegistryClient::normalizeHost($this->registryHost($source))
            ? $source
            : null;
    }

    /** Whether any of $images of the runtime needs its source's credentials. */
    public function forImages(?string $runtimeId, array $images): ?RuntimeSource
    {
        foreach ($images as $image) {
            if (is_string($image) && ($source = $this->forImage($runtimeId, $image))) {
                return $source;
            }
        }

        return null;
    }

    /** The registry a source's credentials are for. */
    public function registryHost(RuntimeSource $source): string
    {
        if ($source->registry_host) {
            return $source->registry_host;
        }

        return $source->kind === RuntimeSource::KIND_OCI ? ImageRef::parse($source->reference())->registry : '';
    }

    /** "registry.acme.com/x/y:1" → "registry.acme.com" ("docker.io" for Docker Hub names). */
    public static function host(string $image): string
    {
        return ImageRef::parse($image)->registry;
    }

    /** The kubernetes.io/dockerconfigjson payload of a source: one auth for its registry. */
    public function dockerConfigJson(RuntimeSource $source): string
    {
        $host = $this->registryHost($source);
        $key = OciRegistryClient::normalizeHost($host) === 'registry-1.docker.io' ? 'https://index.docker.io/v1/' : $host;
        $username = (string) $source->credential('registry', 'username');
        $password = (string) $source->credential('registry', 'password');

        return json_encode(['auths' => [$key => [
            'username' => $username,
            'password' => $password,
            'auth' => base64_encode("{$username}:{$password}"),
        ]]], JSON_UNESCAPED_SLASHES);
    }

    public function secretManifest(RuntimeSource $source, string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'kubernetes.io/dockerconfigjson',
            'metadata' => [
                'name' => self::secretName($source),
                'namespace' => $namespace,
                'labels' => [self::LABEL => strtolower($source->id), 'app.kubernetes.io/managed-by' => 'runr'],
            ],
            'data' => ['.dockerconfigjson' => base64_encode($this->dockerConfigJson($source))],
        ];
    }

    /**
     * Apply the source's pull Secret into $namespace of the region (before the workload that pulls
     * with it). False when it could not be written (the pull then fails visibly).
     */
    public function ensure(KnativeRegion $region, string $namespace, RuntimeSource $source, ?KubernetesClient $k8s = null): bool
    {
        if (! $region->kubeconfig) {
            return false;
        }

        try {
            $k8s ??= new KubernetesClient($region->kubeconfig);
            $ok = $k8s->applyResource($this->secretManifest($source, $namespace), $namespace);
        } catch (\Throwable $e) {
            $ok = false;
            Log::warning('Runtime pull secret not applied', ['source' => $source->id, 'region' => $region->name, 'namespace' => $namespace, 'error' => $e->getMessage()]);
        }

        return $ok;
    }

    /**
     * $podSpec with the pull secret of the runtime's private $image (applied into the namespace
     * first); unchanged when the image needs none.
     */
    public function applyToPodSpec(array $podSpec, ?string $runtimeId, string $image, ?KnativeRegion $region, string $namespace): array
    {
        $source = $this->forImage($runtimeId, $image);
        if ($source === null) {
            return $podSpec;
        }

        if ($region) {
            $this->ensure($region, $namespace, $source);
        }
        $name = self::secretName($source);
        $existing = array_column((array) ($podSpec['imagePullSecrets'] ?? []), 'name');
        if (! in_array($name, $existing, true)) {
            $podSpec['imagePullSecrets'][] = ['name' => $name];
        }

        return $podSpec;
    }

    private function sourceOf(string $runtimeId): ?RuntimeSource
    {
        if (! array_key_exists($runtimeId, $this->sources)) {
            try {
                $sourceId = Runtime::query()->whereKey($runtimeId)->value('source_id');
                $source = $sourceId ? RuntimeSource::query()->find($sourceId) : null;
            } catch (\Throwable) {
                $source = null;
            }
            $this->sources[$runtimeId] = $source && $source->hasRegistryCredentials() ? $source : false;
        }

        return $this->sources[$runtimeId] ?: null;
    }
}
