<?php

namespace App\Http\Controllers\Api;

use App\Events\PlatformEvent;
use App\Http\Requests\KnativeRegion\CheckNamespaceRequest;
use App\Http\Requests\KnativeRegion\InstallOperatorRequest;
use App\Http\Requests\KnativeRegion\InstallServingRequest;
use App\Http\Resources\KnativeRegionResource;
use App\Jobs\InstallKnativeJob;
use App\Jobs\UninstallKnativeJob;
use App\Models\KnativeRegion;
use App\Services\Concerns\KnativeValidationTrait;
use App\Services\KnativeStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Knative Install
 */
class KnativeInstallController extends Controller
{
    use KnativeValidationTrait;

    /**
     * Check Knative status on the cluster.
     */
    public function knativeStatus(Request $request, KnativeRegion $knativeRegion, KnativeStatusService $statusService): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return $this->statusResponse($knativeRegion->knative_status ?? 'ready', $knativeRegion->knative_status_message ?? 'Demo region', $knativeRegion);
        }
        if ($err = $this->validateHasKubeconfig($knativeRegion)) {
            return $err;
        }

        $force = $request->boolean('force');
        if (! $force && in_array($knativeRegion->knative_status, ['installing', 'uninstalling', 'error'])) {
            return $this->statusResponse($knativeRegion->knative_status, $knativeRegion->knative_status_message ?? '', $knativeRegion);
        }

        $status = $statusService->checkStatus($knativeRegion);

        if ($status['status'] === 'error') {
            $knativeRegion->update(['kubeconfig' => null, 'knative_status' => 'error', 'knative_status_message' => $status['message'], 'knative_status_checked_at' => now()]);

            return response()->json(['status' => 'error', 'message' => $status['message'], 'kubeconfig_cleared' => true, 'region' => new KnativeRegionResource($knativeRegion->fresh())], 400);
        }

        $oldStatus = $knativeRegion->knative_status;
        $knativeRegion->update(['knative_status' => $status['status'], 'knative_status_message' => $status['message'], 'knative_status_checked_at' => now()]);
        if ($oldStatus !== $status['status']) {
            $this->broadcastRegion($knativeRegion->fresh());
        }

        return $this->statusResponse($status['status'], $status['message'], $knativeRegion->fresh(), $status['details'] ?? []);
    }

    private function statusResponse(string $status, string $message, KnativeRegion $region, array $details = []): JsonResponse
    {
        return response()->json(['status' => $status, 'message' => $message, 'details' => $details, 'region' => new KnativeRegionResource($region)]);
    }

    private function broadcastRegion(KnativeRegion $region): void
    {
        event(new PlatformEvent('region.updated', ['region' => (new KnativeRegionResource($region))->resolve()]));
    }

    /**
     * Install Knative via Helm (async job).
     */
    public function installKnative(KnativeRegion $knativeRegion): JsonResponse
    {
        if ($err = $this->validateRegion($knativeRegion)) {
            return $err;
        }

        if ($knativeRegion->knative_status === 'installing') {
            return response()->json(['message' => __('api.region.install_in_progress')], 409);
        }

        $knativeRegion->update([
            'knative_status' => 'installing',
            'knative_status_message' => 'Installation queued...',
        ]);

        InstallKnativeJob::dispatch($knativeRegion, 'all', auth()->id());

        return response()->json([
            'message' => 'Installation started.',
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }

    /**
     * Uninstall Knative from the cluster (async job).
     * The region Traefik stays while it serves non-Knative Ingresses (e.g. the RunR console);
     * `remove_traefik: true` removes it anyway.
     */
    public function uninstallKnative(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($err = $this->validateRegion($knativeRegion)) {
            return $err;
        }

        if ($knativeRegion->knative_status === 'uninstalling') {
            return response()->json(['message' => __('api.region.uninstall_in_progress')], 409);
        }

        $knativeRegion->update([
            'knative_status' => 'uninstalling',
            'knative_status_message' => 'Uninstall queued...',
        ]);

        UninstallKnativeJob::dispatch($knativeRegion, 'all', auth()->id(), $request->boolean('remove_traefik'));

        return response()->json([
            'message' => 'Uninstall started.',
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }

    // ── Component-based install endpoints ────────────────────────────────────

    /**
     * Install Knative Operator.
     */
    public function installOperator(InstallOperatorRequest $request, KnativeRegion $knativeRegion, KnativeStatusService $statusService): JsonResponse
    {
        if ($err = $this->validateRegion($knativeRegion)) {
            return $err;
        }
        if ($err = $this->validateComponentNotInstalling($knativeRegion, 'operator')) {
            return $err;
        }

        $validated = $request->validated();
        $namespace = $validated['namespace'] ?? 'knative-operator';

        // Multi-region: if namespace exists, check if operator is already running → adopt
        if ($knativeRegion->knative_operator_status !== 'ready') {
            try {
                if ($statusService->checkNamespaceExists($knativeRegion, $namespace)) {
                    $detected = $statusService->detectAndAdoptComponents($knativeRegion, 'operator');
                    if (! empty($detected['operator']['adopted'])) {
                        return response()->json([
                            'message' => 'Existing operator adopted.',
                            'adopted' => true,
                            'region' => new KnativeRegionResource($knativeRegion->fresh()),
                        ]);
                    }
                }
            } catch (\Throwable) {
            }
        } else {
            return response()->json([
                'message' => 'Operator is already installed.',
                'region' => new KnativeRegionResource($knativeRegion),
            ]);
        }

        $knativeRegion->update([
            'knative_operator_status' => 'installing',
            'knative_operator_status_message' => 'Installation queued...',
            'knative_operator_namespace' => $namespace,
        ]);

        InstallKnativeJob::dispatch($knativeRegion, 'operator', auth()->id());

        return response()->json([
            'message' => 'Operator installation started.',
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }

    /**
     * Install Knative Serving.
     */
    public function installServing(InstallServingRequest $request, KnativeRegion $knativeRegion, KnativeStatusService $statusService): JsonResponse
    {
        if ($err = $this->validateRegion($knativeRegion)) {
            return $err;
        }

        if (($knativeRegion->knative_operator_status ?? 'not_installed') !== 'ready') {
            return response()->json(['message' => __('api.region.operator_required')], 422);
        }
        if ($err = $this->validateComponentNotInstalling($knativeRegion, 'serving')) {
            return $err;
        }

        $validated = $request->validated();
        $namespace = $validated['namespace'] ?? 'knative-serving';
        $version = $validated['version'] ?? null;

        // Multi-region: if namespace exists, check if serving is already running → adopt
        if ($knativeRegion->knative_serving_status !== 'ready') {
            try {
                if ($statusService->checkNamespaceExists($knativeRegion, $namespace)) {
                    $detected = $statusService->detectAndAdoptComponents($knativeRegion, 'serving');
                    if (! empty($detected['serving']['adopted'])) {
                        return response()->json([
                            'message' => 'Existing serving adopted.',
                            'adopted' => true,
                            'region' => new KnativeRegionResource($knativeRegion->fresh()),
                        ]);
                    }
                }
            } catch (\Throwable) {
            }
        } else {
            return response()->json([
                'message' => 'Serving is already installed.',
                'region' => new KnativeRegionResource($knativeRegion),
            ]);
        }

        $knativeRegion->update([
            'knative_serving_status' => 'installing',
            'knative_serving_status_message' => 'Installation queued...',
            'knative_serving_namespace' => $namespace,
            'knative_serving_ingress_class' => 'traefik',
            'knative_serving_version' => $version,
        ]);

        InstallKnativeJob::dispatch($knativeRegion, 'serving', auth()->id());

        return response()->json([
            'message' => 'Serving installation started.',
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }

    /**
     * Detect existing Knative components on the cluster and adopt them.
     */
    public function detectComponents(KnativeRegion $knativeRegion, KnativeStatusService $statusService): JsonResponse
    {
        if ($err = $this->validateHasKubeconfig($knativeRegion)) {
            return $err;
        }

        try {
            $result = $statusService->detectAndAdoptComponents($knativeRegion);
            $fresh = $knativeRegion->fresh();

            if (! empty($result['operator']['adopted']) || ! empty($result['serving']['adopted'])) {
                $this->broadcastRegion($fresh);
            }

            return response()->json([
                ...$result,
                'region' => new KnativeRegionResource($fresh),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('api.region.detection_failed', ['message' => $e->getMessage()])], 500);
        }
    }

    // ── Query endpoints ──────────────────────────────────────────────────────

    /**
     * Check if a namespace exists on the cluster.
     */
    public function checkNamespace(CheckNamespaceRequest $request, KnativeRegion $knativeRegion, KnativeStatusService $statusService): JsonResponse
    {
        if ($err = $this->validateHasKubeconfig($knativeRegion)) {
            return $err;
        }

        $name = $request->validated()['name'];

        try {
            $exists = $statusService->checkNamespaceExists($knativeRegion, $name);

            return response()->json(['exists' => $exists]);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('api.region.namespace_check_failed', ['message' => $e->getMessage()])], 500);
        }
    }

    /**
     * Get compatible Knative Operator chart versions.
     */
    public function compatibleVersions(KnativeRegion $knativeRegion, KnativeStatusService $statusService): JsonResponse
    {
        if ($err = $this->validateHasKubeconfig($knativeRegion)) {
            return $err;
        }

        try {
            $versions = $statusService->listCompatibleVersions($knativeRegion);

            return response()->json(['versions' => $versions]);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('api.region.versions_fetch_failed', ['message' => $e->getMessage()])], 500);
        }
    }

    /**
     * Get component statuses for a region.
     */
    public function componentStatus(KnativeRegion $knativeRegion, KnativeStatusService $statusService): JsonResponse
    {
        $status = $statusService->getComponentStatus($knativeRegion);

        return response()->json([
            ...$status,
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }
}
