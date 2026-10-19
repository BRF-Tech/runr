<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Sets up a region's image registry on its cluster — the same steps whether the region is being
 * created (InstallRegistryAndCreateRegionJob, the console wizard) or already exists
 * (InstallRegionRegistryJob, POST /knative-regions/{id}/registry):
 *
 *  - internal: a Zot already serving in the registry namespace is kept; else the storage is
 *    tested (a PVC of the StorageClass binds, or the bucket answers from a pod), the dedicated
 *    workers are labelled (the node mirror DaemonSet selects them) and Zot is installed;
 *  - external: the registry is tested from a pod in the cluster.
 *
 * $config: registry_mode, storage_type, pvc_config, s3_config, external_address,
 * external_username, external_password, registry_namespace (the RegistrySetupRequest fields).
 */
class RegionRegistrySetup
{
    public function __construct(
        private readonly ZotRegistryService $zot,
        private readonly KnativeNodeService $nodes,
    ) {}

    /**
     * @param  list<string>  $workerNodes  the dedicated workers (Zot and its mirror run there)
     * @param  Closure(string $phase): void  $phase  progress (pvc_test, s3_test, registry_test, zot_install)
     * @return array{ok: bool, phase: ?string, message: ?string, address: ?string}
     */
    public function run(string $kubeconfig, array $config, array $workerNodes, Closure $phase): array
    {
        $mode = $config['registry_mode'];
        $ns = $config['registry_namespace'] ?? 'runr-registry';

        if ($mode === 'external') {
            $phase('registry_test');
            $result = $this->zot->testExternalRegistry(
                $kubeconfig,
                $config['external_address'],
                $config['external_username'] ?? null,
                $config['external_password'] ?? null,
                $ns,
            );

            return $result['success']
                ? self::ok($config['external_address'])
                : self::failed('registry_test', $result['message']);
        }

        $existing = $this->zot->detectExisting($kubeconfig, $ns);
        if ($existing) {
            Log::info('Existing Zot registry detected, skipping install', ['address' => $existing['address']]);

            return self::ok($existing['address']);
        }

        $storageType = $config['storage_type'];
        if ($storageType === 'pvc') {
            $phase('pvc_test');
            $pvc = $config['pvc_config'];
            $result = $this->zot->testPvcBound($kubeconfig, $pvc['storageClass'], $pvc['size'], $ns);
            if (! $result['success']) {
                return self::failed('pvc_test', $result['message']);
            }
        }

        if ($storageType === 's3') {
            $phase('s3_test');
            $result = $this->zot->testS3FromPod($kubeconfig, $config['s3_config'], $ns);
            if (! $result['success']) {
                return self::failed('s3_test', $result['message']);
            }
        }

        // Label worker nodes BEFORE Zot (its node mirror DaemonSet selects them)
        if ($workerNodes !== []) {
            $this->nodes->labelWorkerNodesByKubeconfig($kubeconfig, $workerNodes);
        }

        $phase('zot_install');
        $installed = $this->zot->install($kubeconfig, [
            'namespace' => $ns,
            'storage_type' => $storageType,
            'storage_config' => $storageType === 'pvc' ? $config['pvc_config'] : $config['s3_config'],
            'workers_only' => $workerNodes !== [],
        ]);

        return self::ok($installed['address']);
    }

    /**
     * The region's registry columns for a set-up registry.
     *
     * @return array<string, mixed>
     */
    public static function regionAttributes(array $config, ?string $address): array
    {
        $mode = $config['registry_mode'];
        $attributes = [
            'registry_mode' => $mode,
            'registry_address' => $address,
            'registry_status' => 'ready',
            'registry_status_message' => null,
            'registry_namespace' => $config['registry_namespace'] ?? 'runr-registry',
            'registry_storage_type' => null,
            'registry_storage_config' => null,
            'registry_username' => null,
            'registry_password' => null,
        ];

        if ($mode === 'internal') {
            $attributes['registry_storage_type'] = $config['storage_type'];
            $attributes['registry_storage_config'] = $config['storage_type'] === 'pvc' ? $config['pvc_config'] : $config['s3_config'];
        }

        if ($mode === 'external') {
            $attributes['registry_username'] = $config['external_username'] ?? null;
            $attributes['registry_password'] = $config['external_password'] ?? null;
        }

        return $attributes;
    }

    /** @return array{ok: true, phase: null, message: null, address: ?string} */
    private static function ok(?string $address): array
    {
        return ['ok' => true, 'phase' => null, 'message' => null, 'address' => $address];
    }

    /** @return array{ok: false, phase: string, message: string, address: null} */
    private static function failed(string $phase, string $message): array
    {
        return ['ok' => false, 'phase' => $phase, 'message' => $message, 'address' => null];
    }
}
