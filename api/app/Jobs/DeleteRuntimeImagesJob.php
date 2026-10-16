<?php

namespace App\Jobs;

use App\Models\KnativeRegion;
use App\Models\Runtime;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegistryImageLifecycleService;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A deleted runtime's images in the registries of the regions it was built in
 * (RegistryImageLifecycleService::deleteRuntimeImages, runr-runtimes/<id>). Dispatched by the
 * delete with the regions of the runtime's builds; runs in the context the runtime was deleted in
 * (an organization's custom runtime: its regions).
 */
class DeleteRuntimeImagesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  list<string>  $regionIds
     */
    public function __construct(
        public string $runtimeId,
        public array $regionIds,
    ) {}

    public function handle(RegistryImageLifecycleService $registry): void
    {
        // Registered again under the same id meanwhile: the repository is the new runtime's
        if (Runtime::query()->whereKey($this->runtimeId)->exists()) {
            return;
        }

        $regions = KnativeRegion::whereIn('id', $this->regionIds)->get()->all();
        $missing = array_diff($this->regionIds, array_map(fn (KnativeRegion $r) => (string) $r->id, $regions));
        if ($missing !== [] && RuntimeRegistry::currentTenantId() === null) {
            // A platform runtime built in an organization's own region: that row is in the organization's schema
            foreach (app(RegionReconciler::class)->targets() as $target) {
                if (in_array((string) $target->region->id, $missing, true)) {
                    $regions[] = $target->region;
                }
            }
        }

        foreach ($regions as $region) {
            try {
                $registry->deleteRuntimeImages($region, $this->runtimeId);
            } catch (\Throwable $e) {
                Log::warning('Runtime images could not be deleted', ['runtime' => $this->runtimeId, 'region' => $region->name, 'error' => $e->getMessage()]);
            }
        }
    }
}
