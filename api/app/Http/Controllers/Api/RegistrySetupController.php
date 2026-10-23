<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RegistrySetup\RegistrySetupRequest;
use App\Http\Requests\RegistrySetup\TestExternalRegistryRequest;
use App\Http\Requests\RegistrySetup\TestPvcRequest;
use App\Http\Requests\RegistrySetup\TestS3Request;
use App\Jobs\InstallRegistryAndCreateRegionJob;
use App\Services\KnativeStatusService;
use App\Services\KubernetesClient;
use App\Services\ZotRegistryService;
use App\Support\RequestTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegistrySetupController extends Controller
{
    /**
     * Start registry setup + region creation (async).
     */
    public function start(RegistrySetupRequest $request, KnativeStatusService $statusService): JsonResponse
    {
        $validated = $request->validated();
        $validated['kubeconfig'] = KubernetesClient::adaptKubeconfig($validated['kubeconfig']);

        // Sync validations: kubeconfig connectivity + namespace check
        $this->validateKubeconfig($validated['kubeconfig']);
        $this->checkNamespace($statusService, $validated['kubeconfig'], $validated['name']);

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
     * Poll setup status.
     */
    public function status(string $token): JsonResponse
    {
        $data = Cache::get("registry_setup:{$token}");

        if (! $data) {
            return response()->json(['message' => __('api.registry.token_expired')], 404);
        }

        return response()->json($data);
    }

    /**
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
        $validated['kubeconfig'] = KubernetesClient::adaptKubeconfig($validated['kubeconfig']);
        $this->validateKubeconfig($validated['kubeconfig']);
        $this->checkNamespace($statusService, $validated['kubeconfig'], $validated['name']);

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
     * Test PVC provisioning (standalone).
     */
    public function testPvc(TestPvcRequest $request, ZotRegistryService $zot): JsonResponse
    {
        $v = $request->validated();
        $ns = $v['namespace'] ?? 'runr-registry';
        // as long as the claim may take to bind, past the API's 30 s request limit
        RequestTime::allow((int) config('knative.registry_pvc_test_seconds', 180));
        $result = $zot->testPvcBound($v['kubeconfig'], $v['storageClass'], $v['size'], $ns);

        return response()->json($result);
    }

    /**
     * Test S3 connectivity (standalone).
     */
    public function testS3(TestS3Request $request, ZotRegistryService $zot): JsonResponse
    {
        $v = $request->validated();
        $ns = $v['namespace'] ?? 'runr-registry';
        $result = $zot->testS3FromPod($v['kubeconfig'], $v['s3_config'], $ns);

        return response()->json($result);
    }

    /**
     * Test external registry connectivity (standalone).
     */
    public function testExternal(TestExternalRegistryRequest $request, ZotRegistryService $zot): JsonResponse
    {
        $v = $request->validated();
        $ns = $v['namespace'] ?? 'runr-registry';
        $result = $zot->testExternalRegistry($v['kubeconfig'], $v['url'], $v['username'] ?? null, $v['password'] ?? null, $ns);

        return response()->json($result);
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function validateKubeconfig(string $kubeconfig): void
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
    }

    private function checkNamespace(KnativeStatusService $statusService, string $kubeconfig, string $name): void
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
                'name' => __('api.region.namespace_check_failed', ['message' => $e->getMessage()]),
            ]);
        }
    }
}
