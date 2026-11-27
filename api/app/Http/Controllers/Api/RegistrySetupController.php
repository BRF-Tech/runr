<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RegistrySetup\RegistrySetupRequest;
use App\Http\Requests\RegistrySetup\TestExternalRegistryRequest;
use App\Http\Requests\RegistrySetup\TestPvcRequest;
use App\Http\Requests\RegistrySetup\TestS3Request;
use App\Jobs\InstallRegistryAndCreateRegionJob;
use App\Services\KnativeStatusService;
use App\Services\Kubeconfigs\TenantKubeconfigs;
use App\Services\KubernetesClient;
use App\Services\Sandboxes\Exec\KubectlBinary;
use App\Services\ZotRegistryService;
use App\Support\RequestTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegistrySetupController extends Controller
{
    /**
     * Start creating a region together with its registry (queued)
     *
     * Registry setup + region creation (async): the kubeconfig and the namespace are checked first,
     * the rest runs in the background; poll the returned setup token.
     */
    public function start(RegistrySetupRequest $request, KnativeStatusService $statusService): JsonResponse
    {
        $validated = $request->validated();
        // an organization's own region: the policy's rebuild is stored, the job connects through
        // the checked address (TenantKubeconfigs)
        $restricted = TenantKubeconfigs::restrictsNewRegions();
        [$validated['kubeconfig'], $connect] = app(TenantKubeconfigs::class)->prepare($validated['kubeconfig'], $restricted);

        // Sync validations: kubeconfig connectivity + namespace check; the cluster's version is
        // recorded on the region (it picks kubectl's minor for exec, KubectlBinary)
        $validated['kubernetes_version'] = KubectlBinary::versionFrom($this->validateKubeconfig($connect, $restricted));
        $this->checkNamespace($statusService, $connect, $validated['name'], $restricted);

        $token = Str::uuid()->toString();
        $key = "registry_setup:{$token}";

        Cache::put($key, [
            'status' => 'pending',
            'phase' => 'queued',
            'message' => '',
            'region_id' => null,
            'address' => null,
        ], now()->addMinutes(30));

        InstallRegistryAndCreateRegionJob::dispatch($token, $validated, auth()->id());

        return response()->json(['setup_token' => $token, 'setup_id' => InstallRegistryAndCreateRegionJob::setupId($token)], 202);
    }

    /**
     * Get the state of a registry setup
     *
     * Poll setup status with the setup token (404 once the token has expired).
     */
    public function status(string $token): JsonResponse
    {
        $data = Cache::get("registry_setup:{$token}");

        if (! $data) {
            return response()->json(['message' => __('api.registry.token_expired')], 404);
        }
        // a setup in an organization's context creates its own region: its users read the short
        // reason of a connection's error
        if (isset($data['message']) && is_string($data['message'])) {
            $data['message'] = TenantKubeconfigs::textFor($data['message'], TenantKubeconfigs::restrictsNewRegions());
        }

        return response()->json($data);
    }

    /**
     * Retry a failed registry setup with the same or changed settings
     *
     * Retry a failed setup with (optionally updated) config.
     */
    public function retry(string $token, RegistrySetupRequest $request, KnativeStatusService $statusService): JsonResponse
    {
        $key = "registry_setup:{$token}";
        $existing = Cache::get($key);

        if (! $existing || $existing['status'] !== 'error') {
            return response()->json(['message' => __('api.registry.cannot_retry')], 422);
        }

        $validated = $request->validated();
        $restricted = TenantKubeconfigs::restrictsNewRegions();
        [$validated['kubeconfig'], $connect] = app(TenantKubeconfigs::class)->prepare($validated['kubeconfig'], $restricted);
        $validated['kubernetes_version'] = KubectlBinary::versionFrom($this->validateKubeconfig($connect, $restricted));
        $this->checkNamespace($statusService, $connect, $validated['name'], $restricted);

        Cache::put($key, [
            'status' => 'pending',
            'phase' => 'queued',
            'message' => '',
            'region_id' => null,
            'address' => null,
        ], now()->addMinutes(30));

        InstallRegistryAndCreateRegionJob::dispatch($token, $validated, auth()->id());

        return response()->json(['setup_token' => $token, 'setup_id' => InstallRegistryAndCreateRegionJob::setupId($token)], 202);
    }

    /**
     * Test that the cluster can provision the registry's storage volume
     *
     * Test PVC provisioning (standalone): a test claim of the storage class and size must bind.
     */
    public function testPvc(TestPvcRequest $request, ZotRegistryService $zot): JsonResponse
    {
        $v = $request->validated();
        $ns = $v['namespace'] ?? 'runr-registry';
        // as long as the claim may take to bind, past the API's 30 s request limit
        RequestTime::allow((int) config('knative.registry_pvc_test_seconds', 180));
        $result = $zot->testPvcBound($this->connectWith($v['kubeconfig']), $v['storageClass'], $v['size'], $ns);

        return response()->json($result);
    }

    /**
     * Test the registry's S3 storage connection from inside the cluster
     *
     * Test S3 connectivity (standalone).
     */
    public function testS3(TestS3Request $request, ZotRegistryService $zot): JsonResponse
    {
        $v = $request->validated();
        $ns = $v['namespace'] ?? 'runr-registry';
        $result = $zot->testS3FromPod($this->connectWith($v['kubeconfig']), $v['s3_config'], $ns);

        return response()->json($result);
    }

    /**
     * Test the connection to an external registry from inside the cluster
     *
     * Test external registry connectivity (standalone).
     */
    public function testExternal(TestExternalRegistryRequest $request, ZotRegistryService $zot): JsonResponse
    {
        $v = $request->validated();
        $ns = $v['namespace'] ?? 'runr-registry';
        $result = $zot->testExternalRegistry($this->connectWith($v['kubeconfig']), $v['url'], $v['username'] ?? null, $v['password'] ?? null, $ns);

        return response()->json($result);
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /** The kubeconfig of a test to connect with: as sent, an organization's region's pinned copy. */
    private function connectWith(string $kubeconfig): string
    {
        return TenantKubeconfigs::restrictsNewRegions() ? app(TenantKubeconfigs::class)->prepare($kubeconfig, true)[1] : $kubeconfig;
    }

    /**
     * The cluster answers and runs a Kubernetes version regions support. $restricted (an
     * organization's own region): its users see a short reason of a failure
     * (TenantKubeconfigs::connectionError()).
     *
     * @return array<string, mixed> its /version answer
     */
    private function validateKubeconfig(string $kubeconfig, bool $restricted = false): array
    {
        $details = TenantKubeconfigs::showsDetails($restricted);
        try {
            $response = (new KubernetesClient($kubeconfig))->getPathResponse('version');
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'kubeconfig' => __('api.region.connect_failed', ['message' => TenantKubeconfigs::connectionError($e, $details)]),
            ]);
        }

        $version = $response->successful() ? $response->json() : null;
        if (! is_array($version)) {
            throw ValidationException::withMessages([
                'kubeconfig' => __('api.region.connect_failed', ['message' => $details ? 'Cannot retrieve server version' : TenantKubeconfigs::responseError($response)]),
            ]);
        }

        $minor = (int) rtrim($version['minor'] ?? '', '+');
        $minRequired = config('knative.min_k8s_minor', 30);
        if ($minor < $minRequired) {
            throw ValidationException::withMessages([
                'kubeconfig' => __('api.region.k8s_version_required', ['required' => $minRequired, 'detected' => $minor]),
            ]);
        }

        return $version;
    }

    private function checkNamespace(KnativeStatusService $statusService, string $kubeconfig, string $name, bool $restricted = false): void
    {
        try {
            $exists = $statusService->checkNamespaceExistsByKubeconfig($kubeconfig, $name);
            if ($exists) {
                throw ValidationException::withMessages([
                    'name' => __('api.region.namespace_exists', ['namespace' => $name]),
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'name' => __('api.region.namespace_check_failed', ['message' => TenantKubeconfigs::connectionError($e, TenantKubeconfigs::showsDetails($restricted))]),
            ]);
        }
    }
}
