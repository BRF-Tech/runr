<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Api\Controller;
use App\Jobs\Ai\InstallGpuSupportJob;
use App\Models\KnativeRegion;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Ai\Gpu\GpuInventory;
use App\Services\Ai\Gpu\GpuPolicy;
use App\Services\Ai\Models\ModelStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GPU support of a region (docs/ai.md#gpu-worker-nodes): inventory, install / uninstall of the
 * NVIDIA device plugin or GPU Operator, GPU settings (time-slicing, RuntimeClass, gVisor
 * nvproxy) and the region's model volume.
 *
 * @tags AI · GPU
 */
class GpuController extends Controller
{
    public function __construct(
        private GpuInventory $inventory,
        private GpuPolicy $policy,
    ) {}

    /** GPU nodes, allocation, install status, settings and sandbox support per project type. */
    public function show(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($request->boolean('refresh')) {
            $this->inventory->refresh($knativeRegion);
            $knativeRegion->refresh();

            // Read from the cluster just now: neither the response cache (it skips ?refresh)
            // nor a browser / proxy keeps it
            return response()->json($this->status($knativeRegion))->header('Cache-Control', 'no-store');
        }

        return response()->json($this->status($knativeRegion));
    }

    public function install(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $this->authorizeRegion($knativeRegion);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['device-plugin', 'operator'])],
            'time_slicing_replicas' => 'nullable|integer|min:1|max:'.(int) config('ai.gpu.max_time_slicing_replicas', 16),
            'runtime_class' => ['nullable', Rule::in(['auto', 'nvidia', 'none'])],
            'operator_driver' => 'nullable|boolean',
            'operator_toolkit' => 'nullable|boolean',
            'restrict_to_gpu_nodes' => 'nullable|boolean',
            'gpu_nodes' => 'nullable|array|max:500',
            'gpu_nodes.*' => ['string', 'max:253', 'regex:/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
        ]);

        if ($response = $this->refuseChange($knativeRegion)) {
            return $response;
        }

        // runtime_class is the choice (auto | nvidia | none), resolved against the node runtime at install
        $options = [
            'time_slicing_replicas' => $data['time_slicing_replicas'] ?? null,
            'runtime_class' => $data['runtime_class'] ?? 'auto',
        ];
        foreach (['operator_driver', 'operator_toolkit', 'restrict_to_gpu_nodes'] as $flag) {
            if (array_key_exists($flag, $data) && $data[$flag] !== null) {
                $options[$flag] = (bool) $data[$flag];
            }
        }
        if (array_key_exists('gpu_nodes', $data)) {
            $options['gpu_nodes'] = $data['gpu_nodes'];
        }

        $knativeRegion->update(['gpu_status' => 'installing', 'gpu_status_message' => "Installation queued...\n"]);
        InstallGpuSupportJob::dispatch($knativeRegion->id, 'install', $data['mode'], $options, (string) auth()->id());

        return response()->json([
            'message' => __('ai.gpu.install_queued', ['mode' => $data['mode']]),
            'gpu' => $this->status($knativeRegion->fresh()),
        ], 202);
    }

    public function uninstall(KnativeRegion $knativeRegion): JsonResponse
    {
        $this->authorizeRegion($knativeRegion);

        if ($response = $this->refuseChange($knativeRegion)) {
            return $response;
        }

        if (! in_array($knativeRegion->gpu_mode, ['device-plugin', 'operator'], true)) {
            return response()->json(['message' => __('ai.gpu.nothing_to_uninstall')], 422);
        }

        $knativeRegion->update(['gpu_status' => 'uninstalling', 'gpu_status_message' => "Removal queued...\n"]);
        InstallGpuSupportJob::dispatch($knativeRegion->id, 'uninstall', null, [], (string) auth()->id());

        return response()->json([
            'message' => __('ai.gpu.uninstall_queued'),
            'gpu' => $this->status($knativeRegion->fresh()),
        ], 202);
    }

    /** Time-slicing / RuntimeClass re-apply the installed chart (queued); gVisor nvproxy is RunR-side. */
    public function updateSettings(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $this->authorizeRegion($knativeRegion);

        $data = $request->validate([
            'time_slicing_replicas' => 'sometimes|nullable|integer|min:1|max:'.(int) config('ai.gpu.max_time_slicing_replicas', 16),
            'runtime_class' => ['sometimes', 'nullable', Rule::in(['auto', 'nvidia', 'none'])],
            'gvisor_nvproxy' => 'sometimes|boolean',
            'restrict_to_gpu_nodes' => 'sometimes|boolean',
            'gpu_nodes' => 'sometimes|nullable|array|max:500',
            'gpu_nodes.*' => ['string', 'max:253', 'regex:/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
        ]);

        $settings = GpuInstallService::settings($knativeRegion, array_intersect_key($data, array_flip(['gvisor_nvproxy'])));
        if (array_key_exists('runtime_class', $data) && ! in_array($knativeRegion->gpu_mode, ['device-plugin', 'operator'], true)) {
            // external GPU support: the RuntimeClass GPU pods use is set directly
            $settings['runtime_class_choice'] = $data['runtime_class'] ?? 'auto';
            $settings['runtime_class'] = ($data['runtime_class'] ?? null) === 'nvidia' ? 'nvidia' : null;
        }
        $knativeRegion->update(['gpu_settings' => $settings]);

        $chartChanges = array_intersect_key($data, array_flip(['time_slicing_replicas', 'runtime_class', 'restrict_to_gpu_nodes', 'gpu_nodes']));
        $queued = false;
        if ($chartChanges !== [] && in_array($knativeRegion->gpu_mode, ['device-plugin', 'operator'], true)) {
            if ($response = $this->refuseChange($knativeRegion)) {
                return $response;
            }
            $knativeRegion->update(['gpu_status' => 'installing', 'gpu_status_message' => "Applying GPU settings...\n"]);
            InstallGpuSupportJob::dispatch($knativeRegion->id, 'reconfigure', null, $chartChanges, (string) auth()->id());
            $queued = true;
        }

        return response()->json([
            'message' => $queued ? __('ai.gpu.settings_queued') : __('ai.gpu.settings_saved'),
            'gpu' => $this->status($knativeRegion->fresh()),
        ], $queued ? 202 : 200);
    }

    public function storage(KnativeRegion $knativeRegion, ModelStorage $storage): JsonResponse
    {
        return response()->json($storage->status($knativeRegion));
    }

    public function updateStorage(Request $request, KnativeRegion $knativeRegion, ModelStorage $storage): JsonResponse
    {
        $this->authorizeRegion($knativeRegion);

        $data = $request->validate([
            'storage_class' => ['sometimes', 'nullable', 'string', 'max:253', 'regex:/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/'],
            'size_gi' => 'sometimes|integer|min:1|max:65536',
            'access_mode' => ['sometimes', Rule::in(['ReadWriteMany', 'ReadWriteOnce'])],
        ]);

        $current = $storage->status($knativeRegion);
        if (in_array($current['status'], ['bound', 'pending'], true)) {
            // A claim exists: only a larger size applies (storage class and access mode are fixed)
            foreach (['storage_class', 'access_mode'] as $fixed) {
                if (array_key_exists($fixed, $data) && (string) $data[$fixed] !== (string) $current[$fixed]) {
                    return response()->json(['message' => __('ai.models.storage_fixed'), 'errors' => [$fixed => [__('ai.models.storage_fixed')]]], 422);
                }
            }
            if (isset($data['size_gi']) && $data['size_gi'] < $current['size_gi']) {
                return response()->json(['message' => __('ai.models.storage_shrink'), 'errors' => ['size_gi' => [__('ai.models.storage_shrink')]]], 422);
            }
        }

        $knativeRegion->update(['model_storage' => array_merge((array) ($knativeRegion->model_storage ?? []), array_map(fn ($v) => $v ?? '', $data))]);

        return response()->json($storage->status($knativeRegion->fresh()));
    }

    /** GpuStatus (routes/ai.php contract). */
    private function status(KnativeRegion $region): array
    {
        $stored = $this->inventory->stored($region);
        $settings = GpuInstallService::settings($region);

        $sandbox = [];
        foreach (['xaas', 'faas', 'caas'] as $type) {
            $sandbox[$type] = $this->policy->sandboxSupport($region, $type);
        }

        return [
            'mode' => $region->gpu_mode ?? 'none',
            'status' => $region->gpu_status ?? 'not_installed',
            'status_message' => $region->gpu_status_message,
            'settings' => [
                'time_slicing_replicas' => $settings['time_slicing_replicas'],
                'runtime_class' => $settings['runtime_class'],
                'runtime_class_choice' => $settings['runtime_class_choice'],
                'gvisor_nvproxy' => (bool) $settings['gvisor_nvproxy'],
                'operator_driver' => (bool) $settings['operator_driver'],
                'operator_toolkit' => (bool) $settings['operator_toolkit'],
                'restrict_to_gpu_nodes' => (bool) $settings['restrict_to_gpu_nodes'],
                'gpu_nodes' => $settings['gpu_nodes'],
            ],
            'summary' => $stored['summary'],
            'nodes' => $stored['nodes'],
            'allocation' => [
                'total' => (int) ($region->gpu_allocated['total'] ?? 0),
                'by_node' => (array) ($region->gpu_allocated['by_node'] ?? []),
            ],
            'sandbox' => $sandbox,
            'detected_at' => $stored['detected_at'],
        ];
    }

    private function authorizeRegion(KnativeRegion $region): void
    {
        abort_unless(Gate::allows('regions.gpu.manage', $region), 403, __('ai.gpu.forbidden'));
    }

    private function refuseChange(KnativeRegion $region): ?JsonResponse
    {
        if ($region->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }
        if (! $region->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }
        if (in_array($region->gpu_status, ['installing', 'uninstalling'], true)) {
            return response()->json(['message' => __('api.region.operation_in_progress')], 422);
        }

        return null;
    }
}
