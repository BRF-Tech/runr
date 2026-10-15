<?php

namespace App\Services\Wasm;

use App\Models\KnativeRegion;
use App\Services\Runtimes\RuntimeManifest;

/**
 * Runtime plugins that run as WebAssembly: a built-in or platform runtime whose manifest sets
 * `runtimeClass` to the RuntimeClass of a shim RunR installs (wasmtime-spin-v2, wasmtime-v1).
 * Such a runtime is offered in a region only while the region's WebAssembly support is ready
 * with that shim — the runtime picker, project creation and deploys all ask here
 * (RuntimeRegistry).
 *
 * Bound scoped (WasmServiceProvider): regions are read once per request / job.
 */
class WasmRuntimes
{
    /** @var array<string, KnativeRegion|false> */
    private array $regions = [];

    public static function isWasm(?RuntimeManifest $manifest): bool
    {
        return $manifest !== null && WasmShim::forRuntimeClass($manifest->runtimeClass()) !== null;
    }

    public static function shimOf(?RuntimeManifest $manifest): ?WasmShim
    {
        return $manifest ? WasmShim::forRuntimeClass($manifest->runtimeClass()) : null;
    }

    /** Whether the region's WebAssembly support is ready with the shim. */
    public static function regionOffers(?KnativeRegion $region, WasmShim $shim): bool
    {
        return $region !== null
            && $region->sandbox_wasm_status === 'ready'
            && in_array($shim->id, (array) ($region->sandbox_wasm_shims ?? []), true);
    }

    /**
     * Why a Wasm runtime cannot be used in the region, null when it can (or the runtime is not a
     * Wasm runtime, or no region is given — e.g. Settings → Runtimes lists every runtime).
     */
    public function unavailableReason(?RuntimeManifest $manifest, ?string $regionId): ?string
    {
        $shim = self::shimOf($manifest);
        if ($shim === null || $regionId === null || $regionId === '') {
            return null;
        }

        $region = $this->region($regionId);
        if (self::regionOffers($region, $shim)) {
            return null;
        }

        return __('wasm::messages.runtime_not_in_region', [
            'name' => $manifest->name(),
            'shim' => $shim->name,
            'region' => $region !== null ? $region->name : $regionId,
        ]);
    }

    public function offeredIn(?RuntimeManifest $manifest, ?string $regionId): bool
    {
        return $this->unavailableReason($manifest, $regionId) === null;
    }

    private function region(string $id): ?KnativeRegion
    {
        if (! array_key_exists($id, $this->regions)) {
            // the container-bound model: tenant context reads the tenant's copy of the region
            $found = KnativeRegion::syncedQuery()->find($id);
            $this->regions[$id] = $found instanceof KnativeRegion ? $found : false;
        }

        return $this->regions[$id] ?: null;
    }
}
