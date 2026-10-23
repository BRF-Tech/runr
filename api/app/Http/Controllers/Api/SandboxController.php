<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnativeRegion\InstallSandboxRequest;
use App\Http\Requests\KnativeRegion\UpdateSandboxMappingRequest;
use App\Http\Resources\KnativeRegionResource;
use App\Jobs\InstallSandboxJob;
use App\Jobs\ProbeKvmJob;
use App\Jobs\UninstallSandboxJob;
use App\Models\KnativeRegion;
use App\Services\Helpers\KataRuntime;
use App\Services\KubernetesClient;
use App\Services\SandboxInstallService;
use Illuminate\Http\JsonResponse;

/**
 * @tags Sandbox (Runtime Isolation)
 */
class SandboxController extends Controller
{
    /** /dev/kvm check before a Kata install, where nodes were not checked yet (a few seconds). */
    private const KVM_CHECK_SECONDS = 45;

    /**
     * Install a sandbox runtime (Kata or gVisor) on the region's cluster.
     */
    public function installSandbox(InstallSandboxRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }

        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }

        $runtime = $request->validated('runtime');
        $field = $runtime === 'kata' ? 'sandbox_kata_status' : 'sandbox_gvisor_status';

        if (in_array($knativeRegion->{$field}, ['installing', 'uninstalling'])) {
            return response()->json(['message' => __('api.region.operation_in_progress')], 422);
        }

        $hypervisors = $request->validated('hypervisors') ?? ['clh'];

        if ($runtime === 'kata') {
            // The console's check (kata-shims): hardware virtualization on the nodes (checked
            // now where it was not yet), e.g. fc needs a devmapper snapshotter
            try {
                $service = app(SandboxInstallService::class);
                $k8s = new KubernetesClient($knativeRegion->kubeconfig);
                $kvm = $service->ensureKvmProbed($k8s, false, self::KVM_CHECK_SECONDS);
                if ($kvm['state'] === 'no') {
                    $message = 'Kata Containers needs hardware virtualization (/dev/kvm), which none of the nodes has ('
                        .SandboxInstallService::describeKvmNodes($kvm).'). Use gVisor or runc on this region.';

                    return response()->json(['message' => $message, 'errors' => ['runtime' => [$message]], 'reason' => 'no_kvm'], 422);
                }
                if ($kvm['kvm_nodes'] === []) {
                    $message = '/dev/kvm could not be checked on the nodes ('.SandboxInstallService::describeKvmNodes($kvm).'). Try again.';

                    return response()->json(['message' => $message, 'errors' => ['runtime' => [$message]]], 422);
                }
                $problems = $service->unavailableHypervisors($k8s, $hypervisors);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Cannot check the cluster for Kata: '.$e->getMessage()], 422);
            }

            if ($problems) {
                $errors = [];
                foreach ($problems as $hv => $reason) {
                    $errors[] = "Kata hypervisor '{$hv}' cannot run on this cluster: {$reason}.";
                }

                return response()->json([
                    'message' => implode(' ', $errors),
                    'errors' => ['hypervisors' => $errors],
                    // hypervisor → why it cannot run here: an install of the others can follow
                    'unavailable' => $problems,
                ], 422);
            }
        }

        $options = [];
        $warning = null;
        if ($runtime === 'kata') {
            // A failed addition to a working install keeps it (SandboxInstallService::installKata())
            $options['previous_status'] = $knativeRegion->sandbox_kata_status;
            // kata-deploy restarts the container runtime on the nodes (fc: devmapper setup on top)
            $warning = SandboxInstallService::KATA_INSTALL_WARNING;
            if (array_filter($hypervisors, [KataRuntime::class, 'needsDevmapper'])) {
                // The pool on the region's nodes is never resized: the size it was set up with
                // stays (SandboxInstallService::devmapperPoolGb())
                $options['devmapper_pool_gb'] = (int) ($knativeRegion->sandbox_kata_devmapper_pool_gb
                    ?? $request->validated('devmapper_pool_gb') ?? KataRuntime::DEVMAPPER_POOL_DEFAULT_GB);
                $warning .= ' '.SandboxInstallService::KATA_FC_WARNING
                    ." Thin-pool data size: {$options['devmapper_pool_gb']} GiB (sparse).";
            }
        }

        $knativeRegion->update([
            $field => 'installing',
            "{$field}_message" => 'Installation queued...',
        ]);

        InstallSandboxJob::dispatch($knativeRegion, $runtime, $hypervisors, auth()->id(), $options);

        return response()->json(array_filter([
            'message' => "Sandbox runtime '{$runtime}' installation queued.",
            'warning' => $warning,
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]), 202);
    }

    /**
     * Uninstall a sandbox runtime from the region's cluster.
     */
    public function uninstallSandbox(InstallSandboxRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }

        $runtime = $request->validated('runtime');
        $field = $runtime === 'kata' ? 'sandbox_kata_status' : 'sandbox_gvisor_status';

        if (in_array($knativeRegion->{$field}, ['installing', 'uninstalling'])) {
            return response()->json(['message' => __('api.region.operation_in_progress')], 422);
        }

        $knativeRegion->update([
            $field => 'uninstalling',
            "{$field}_message" => 'Removal queued...',
        ]);

        UninstallSandboxJob::dispatch($knativeRegion, $runtime, auth()->id());

        return response()->json([
            'message' => "Sandbox runtime '{$runtime}' removal queued.",
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }

    /**
     * Get sandbox status for a region.
     */
    public function sandboxStatus(KnativeRegion $knativeRegion): JsonResponse
    {
        return response()->json([
            'kata' => [
                'status' => $knativeRegion->sandbox_kata_status ?? 'not_installed',
                'message' => $knativeRegion->sandbox_kata_status_message,
                'hypervisor' => $knativeRegion->sandbox_kata_hypervisor,
            ],
            'gvisor' => [
                'status' => $knativeRegion->sandbox_gvisor_status ?? 'not_installed',
                'message' => $knativeRegion->sandbox_gvisor_status_message,
            ],
            'mapping' => [
                'xaas' => $knativeRegion->sandbox_xaas_runtime ?? 'runc',
                'faas' => $knativeRegion->sandbox_faas_runtime ?? 'runc',
                'caas' => $knativeRegion->sandbox_caas_runtime ?? 'runc',
                'build' => $knativeRegion->sandbox_build_runtime ?? 'runc',
            ],
        ]);
    }

    /**
     * Detect which Kata hypervisor shims are compatible with the cluster.
     */
    public function kataShims(KnativeRegion $knativeRegion): JsonResponse
    {
        if (! $knativeRegion->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }

        $service = app(SandboxInstallService::class);
        $k8s = new KubernetesClient($knativeRegion->kubeconfig);
        $shims = $service->detectCompatibleShims($k8s);

        // Hardware virtualization: nodes RunR has not checked yet (new nodes) — or all of them on
        // ?refresh=1 — are checked in the background; the console reloads on sandbox.kvm.probed
        $kvm = $service->kvmStatus($k8s);
        $refresh = request()->boolean('refresh');
        $probing = false;
        if (($kvm['unprobed'] !== [] || $refresh) && ! $knativeRegion->is_demo) {
            ProbeKvmJob::dispatch($knativeRegion, $refresh);
            $probing = true;
        }

        return response()->json([
            'shims' => $shims,
            'kvm' => [
                'state' => $kvm['state'],
                'nodes' => $kvm['nodes'],
                'kvm_nodes' => $kvm['kvm_nodes'],
                'checked' => $kvm['checked'],
                'probing' => $probing,
            ],
            'warning' => SandboxInstallService::KATA_INSTALL_WARNING,
            'fc_warning' => SandboxInstallService::KATA_FC_WARNING,
            'devmapper' => [
                // the size the region's thin-pool was set up with (null: none yet), kept by later installs
                'pool_gb' => $knativeRegion->sandbox_kata_devmapper_pool_gb,
                'default_pool_gb' => KataRuntime::DEVMAPPER_POOL_DEFAULT_GB,
                'min_pool_gb' => KataRuntime::DEVMAPPER_POOL_MIN_GB,
                'max_pool_gb' => KataRuntime::DEVMAPPER_POOL_MAX_GB,
            ],
        ]);
    }

    /**
     * Update project-type → sandbox runtime mapping.
     */
    public function updateMapping(UpdateSandboxMappingRequest $request, KnativeRegion $knativeRegion): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }

        $knativeRegion->update($request->validated());

        return response()->json([
            'message' => 'Sandbox mapping updated.',
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }
}
