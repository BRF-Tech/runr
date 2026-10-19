<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnativeRegion\CheckNamespacePreviewRequest;
use App\Http\Requests\KnativeRegion\KubeconfigRequest;
use App\Http\Requests\KnativeRegion\StoreKnativeRegionRequest;
use App\Http\Requests\KnativeRegion\UpdateKnativeRegionRequest;
use App\Http\Requests\KnativeRegion\UpdateRegionMonitoringRequest;
use App\Http\Requests\KnativeRegion\UpdateRegionSettingsRequest;
use App\Http\Resources\KnativeRegionResource;
use App\Jobs\ApplyIngressTrustJob;
use App\Jobs\ApplyRequestTimeoutJob;
use App\Jobs\ConfigureRegionMonitoringJob;
use App\Jobs\InstallRegionRegistryJob;
use App\Jobs\RedeployRegionAgentJob;
use App\Models\KnativeRegion;
use App\Services\Helpers\IngressTrust;
use App\Services\Helpers\MonitoringEndpointAccess;
use App\Services\Helpers\RequestTimeout;
use App\Services\KnativeNodeService;
use App\Services\KnativeRedisService;
use App\Services\KnativeStatusService;
use App\Services\KubernetesClient;
use App\Services\RegionStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Knative Regions
 */
class KnativeRegionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $regions = KnativeRegion::orderBy('name')->get();

        return KnativeRegionResource::collection($regions);
    }

    /**
     * Single region, same shape as an index item.
     */
    public function show(KnativeRegion $knativeRegion): KnativeRegionResource
    {
        return new KnativeRegionResource($knativeRegion);
    }

    public function store(StoreKnativeRegionRequest $request, KnativeStatusService $statusService, KnativeNodeService $nodeService): JsonResponse
    {
        $validated = $request->validated();
        $validated['kubeconfig'] = KubernetesClient::adaptKubeconfig($validated['kubeconfig']);

        // Validate kubeconfig connectivity before creating region
        $this->checkedServerVersion($validated['kubeconfig']);

        // Region name = project namespace (same value)
        $projectNs = $validated['name'];
        $validated['project_namespace'] = $projectNs;

        // Check project namespace doesn't already exist on the cluster
        try {
            $exists = $statusService->checkNamespaceExistsByKubeconfig($validated['kubeconfig'], $projectNs);
            if ($exists) {
                throw ValidationException::withMessages([
                    'name' => __('api.region.namespace_exists', ['namespace' => $projectNs]),
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'name' => __('api.region.namespace_check_failed', ['message' => $e->getMessage()]),
            ]);
        }

        // Extract nodes before creating region (not a fillable field)
        $nodeNames = $validated['nodes'] ?? [];
        unset($validated['nodes']);

        // Redis is installed after the namespace exists (queueInitialInstall below)
        $redis = [
            'mode' => $validated['redis_mode'] ?? null,
            'address' => $validated['redis_address'] ?? null,
            'password' => $validated['redis_password'] ?? null,
        ];
        unset($validated['redis_mode'], $validated['redis_address'], $validated['redis_password']);

        // An externally supplied Prometheus/Loki URL means installServing() (whenever it
        // eventually runs) must not install RunR's own stack for that component — mark it
        // unmanaged now, before install, so the flag survives independent of install timing.
        $validated = array_merge($validated, MonitoringEndpointAccess::regionAttributes($validated));

        $region = KnativeRegion::create($validated);

        // Create namespace on cluster
        try {
            $statusService->createProjectNamespace($region, $projectNs);
        } catch (\Throwable $e) {
            // Namespace creation failed — delete region and return error
            $region->delete();
            throw ValidationException::withMessages([
                'name' => __('api.region.namespace_create_failed', ['message' => $e->getMessage()]),
            ]);
        }

        // Apply worker selection if nodes provided
        if (! empty($nodeNames)) {
            try {
                $nodeService->applyWorkerSelection($region, $nodeNames);
            } catch (\Throwable $e) {
                // Workers failed but region+namespace are created — log but don't fail
                Log::warning('Worker selection failed during store', [
                    'region' => $region->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        app(KnativeRedisService::class)
            ->queueInitialInstall($region, $redis['mode'], $redis['address'], $redis['password'], auth()->id());

        // A registry given with the region is set up like POST …/registry (stored, then tested
        // and installed in the background; registry_status follows)
        if (! empty($validated['registry_mode'])) {
            $region->update(['registry_status' => 'installing', 'registry_status_message' => 'Registry setup queued...']);
            InstallRegionRegistryJob::dispatch($region, self::registryConfig($validated), auth()->id());
        }

        return response()->json(new KnativeRegionResource($region->fresh()), 201);
    }

    /**
     * The registry fields of POST /knative-regions (registry_*) in the shape of the region setup's
     * (RegionRegistrySetup).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function registryConfig(array $validated): array
    {
        $storageType = $validated['registry_storage_type'] ?? null;

        return [
            'registry_mode' => $validated['registry_mode'],
            'storage_type' => $storageType,
            'pvc_config' => $storageType === 'pvc' ? $validated['registry_storage_config'] : null,
            's3_config' => $storageType === 's3' ? $validated['registry_storage_config'] : null,
            'external_address' => $validated['registry_address'] ?? null,
            'external_username' => $validated['registry_username'] ?? null,
            'external_password' => $validated['registry_password'] ?? null,
            'registry_namespace' => $validated['registry_namespace'] ?? 'runr-registry',
        ];
    }

    /**
     * Validate kubeconfig connectivity (without creating a region).
     */
    public function validateKubeconfig(KubeconfigRequest $request): JsonResponse
    {
        $kubeconfig = KubernetesClient::adaptKubeconfig($request->validated()['kubeconfig']);
        $version = $this->checkedServerVersion($kubeconfig);

        $adapted = $kubeconfig !== $request->validated()['kubeconfig'];

        return response()->json([
            'valid' => true,
            'version' => trim(($version['major'] ?? '').'.'.($version['minor'] ?? '')),
            'kubeconfig' => $adapted ? $kubeconfig : null,
        ]);
    }

    /**
     * Preview worker nodes using raw kubeconfig (no region required).
     */
    public function previewNodes(KubeconfigRequest $request, KnativeNodeService $nodeService): JsonResponse
    {
        $kubeconfig = KubernetesClient::adaptKubeconfig($request->validated()['kubeconfig']);

        try {
            $nodes = $nodeService->listWorkerNodesByKubeconfig($kubeconfig);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => __('api.region.connect_failed', ['message' => $e->getMessage()]),
            ], 400);
        }

        return response()->json(['nodes' => $nodes]);
    }

    /**
     * List StorageClasses from cluster using raw kubeconfig.
     */
    public function previewStorageClasses(KubeconfigRequest $request): JsonResponse
    {
        $kubeconfig = KubernetesClient::adaptKubeconfig($request->validated()['kubeconfig']);

        try {
            $k8s = new KubernetesClient($kubeconfig);
            $list = $k8s->listClusterResources('storage.k8s.io/v1', 'StorageClass');
            $classes = collect($list['items'] ?? [])->map(fn ($sc) => [
                'name' => $sc['metadata']['name'],
                'is_default' => ! empty($sc['metadata']['annotations']['storageclass.kubernetes.io/is-default-class']),
            ])->values()->all();
        } catch (\Throwable $e) {
            return response()->json(['message' => __('api.region.storage_classes_failed', ['message' => $e->getMessage()])], 400);
        }

        return response()->json(['storageClasses' => $classes]);
    }

    /**
     * Check if a namespace exists using raw kubeconfig (no region required).
     */
    public function checkNamespacePreview(CheckNamespacePreviewRequest $request, KnativeStatusService $statusService): JsonResponse
    {
        $validated = $request->validated();

        $validated['kubeconfig'] = KubernetesClient::adaptKubeconfig($validated['kubeconfig']);

        try {
            $exists = $statusService->checkNamespaceExistsByKubeconfig($validated['kubeconfig'], $validated['name']);

            return response()->json(['exists' => $exists]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Cannot check namespace: '.$e->getMessage()], 500);
        }
    }

    public function update(UpdateKnativeRegionRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $data = $request->validated();

        // A replaced kubeconfig (credential rotation) is checked like a new region's: adapted for
        // a local cluster, reachable, a supported Kubernetes version — and the same cluster:
        // moving a region to another cluster is a migration (POST …/migrate)
        if (array_key_exists('kubeconfig', $data)) {
            $data['kubeconfig'] = KubernetesClient::adaptKubeconfig($data['kubeconfig']);
            $this->checkedServerVersion($data['kubeconfig']);
            $this->assertSameCluster($knativeRegion, $data['kubeconfig']);
        }

        // Backward compat: is_active → region_status
        if (isset($data['is_active']) && ! isset($data['region_status'])) {
            $data['region_status'] = $data['is_active'] ? 'active' : 'inactive';
            unset($data['is_active']);
        }

        // region_status change → Traefik sync
        if (isset($data['region_status'])) {
            app(RegionStatusService::class)->changeStatus($knativeRegion, $data['region_status']);
            unset($data['region_status']);
        }

        if (! empty($data)) {
            $knativeRegion->update($data);
        }

        return response()->json(new KnativeRegionResource($knativeRegion->fresh()));
    }

    /**
     * Connect with the kubeconfig and check the cluster's Kubernetes version against the minimum
     * (knative.min_k8s_minor); a 422 on kubeconfig otherwise.
     *
     * @return array<string, mixed> the /version answer
     */
    private function checkedServerVersion(string $kubeconfig): array
    {
        try {
            $k8s = new KubernetesClient($kubeconfig);
            $version = $k8s->getServerVersion();
            if (! $version) {
                throw new \Exception('Cannot retrieve server version');
            }

            $minor = (int) rtrim($version['minor'] ?? '', '+');
            $minRequired = config('knative.min_k8s_minor', 30);
            if ($minor < $minRequired) {
                throw ValidationException::withMessages([
                    'kubeconfig' => __('api.region.k8s_version_required', ['required' => $minRequired, 'detected' => $minor]),
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'kubeconfig' => __('api.region.connect_failed', ['message' => $e->getMessage()]),
            ]);
        }

        return $version;
    }

    /**
     * The new kubeconfig reaches the region's cluster: the kube-system namespace UIDs match.
     * When the current kubeconfig no longer answers (expired credentials — the reason for the
     * replacement) there is nothing to compare, and the new one is taken.
     */
    private function assertSameCluster(KnativeRegion $region, string $kubeconfig): void
    {
        if (! $region->kubeconfig || $region->is_demo) {
            return;
        }

        $uid = static function (string $config): ?string {
            try {
                return (new KubernetesClient($config))->getClusterResource('v1', 'Namespace', 'kube-system')['metadata']['uid'] ?? null;
            } catch (\Throwable) {
                return null;
            }
        };

        $current = $uid($region->kubeconfig);
        $new = $current ? $uid($kubeconfig) : null;
        if ($current && $new && ! hash_equals($current, $new)) {
            throw ValidationException::withMessages(['kubeconfig' => __('api.region.kubeconfig_other_cluster')]);
        }
    }

    public function downloadKubeconfig(KnativeRegion $knativeRegion): StreamedResponse|JsonResponse
    {
        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_not_found')], 404);
        }

        $filename = $knativeRegion->name.'-kubeconfig.yaml';

        return response()->streamDownload(function () use ($knativeRegion) {
            echo $knativeRegion->kubeconfig;
        }, $filename, [
            'Content-Type' => 'application/x-yaml',
        ]);
    }

    public function destroy(KnativeRegion $knativeRegion): JsonResponse
    {
        $knativeRegion->delete();

        return response()->json(null, 204);
    }

    /**
     * The region's Prometheus and Loki after its creation: a URL points the region at an existing
     * in-cluster instance (RunR's own release keeps running — regions on the same cluster may use
     * it), null switches back to RunR's own, which is installed when the region's Serving is
     * ready (else with the Serving install). loki_org_id is the tenant of an existing Loki.
     */
    public function updateMonitoring(UpdateRegionMonitoringRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }

        $validated = $request->validated();
        $changes = [];
        $managed = [];
        $externalPrometheus = false;

        foreach (['prometheus', 'loki'] as $component) {
            if (! array_key_exists("{$component}_url", $validated)) {
                continue;
            }

            $url = $validated["{$component}_url"];
            $isManaged = $component === 'prometheus' ? $knativeRegion->isPrometheusManaged() : $knativeRegion->isLokiManaged();

            if ($url) {
                $changes += MonitoringEndpointAccess::regionAttributes(["{$component}_url" => $url]);
                $externalPrometheus = $externalPrometheus || $component === 'prometheus';
            } elseif (! $isManaged) {
                $changes["{$component}_managed"] = true;
                $changes["{$component}_url"] = null;
                $managed[] = $component;
            }
        }

        if (array_key_exists('loki_org_id', $validated)) {
            $changes['loki_org_id'] = $validated['loki_org_id'];
        }

        if ($changes) {
            $knativeRegion->update($changes);
        }

        $fresh = $knativeRegion->fresh();
        if (($managed || $externalPrometheus) && $fresh->has_serving && $fresh->kubeconfig) {
            ConfigureRegionMonitoringJob::dispatch($fresh, $managed, $externalPrometheus);
        }

        return response()->json([
            'message' => __('api.region.monitoring_updated'),
            'region' => new KnativeRegionResource($fresh),
        ]);
    }

    /**
     * Update Knative settings for a region.
     */
    public function updateSettings(UpdateRegionSettingsRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists(IngressTrust::SETTING, $validated)) {
            $validated[IngressTrust::SETTING] = IngressTrust::normalize($validated[IngressTrust::SETTING]);
        }

        $trustBefore = IngressTrust::forRegion($knativeRegion);
        $timeoutBefore = RequestTimeout::forRegion($knativeRegion);
        $agentUrlBefore = $knativeRegion->effective_settings['agent_ws_url'] ?? null;
        $current = $knativeRegion->knative_settings ?? [];
        $merged = array_merge($current, $validated);

        $knativeRegion->update(['knative_settings' => $merged]);
        $fresh = $knativeRegion->fresh();

        // The region Traefik picks a changed ingress_trusted_ips up right away (Helm upgrade in
        // the background, the same step region:reconcile runs)
        if (IngressTrust::forRegion($fresh) !== $trustBefore) {
            ApplyIngressTrustJob::dispatch($fresh);
        }

        // Traefik's timeouts and Knative's maximum revision timeout follow request_timeout
        if (RequestTimeout::forRegion($fresh) !== $timeoutBefore) {
            ApplyRequestTimeoutJob::dispatch($fresh);
        }

        // The runr-agent connects to a changed agent_ws_url once it is deployed again
        if (($fresh->effective_settings['agent_ws_url'] ?? null) !== $agentUrlBefore) {
            RedeployRegionAgentJob::dispatch($fresh);
        }

        return response()->json([
            'message' => 'Settings updated.',
            'settings' => $knativeRegion->fresh()->effective_settings,
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }
}
