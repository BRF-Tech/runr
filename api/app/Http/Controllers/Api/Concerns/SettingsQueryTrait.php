<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Resources\KnativeRegionResource;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\OrgTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

trait SettingsQueryTrait
{
    protected function regionQuery(): Builder
    {
        return KnativeRegion::query();
    }

    protected function activeExecutorImages(): Collection
    {
        // Versions of the runtimes this context can use (other tenants' custom runtimes stay out)
        return ExecutorImage::where('is_active', true)
            ->whereIn('runtime', app(RuntimeRegistry::class)->visible()->pluck('id'))
            ->orderBy('runtime')
            ->orderBy('sort_order')
            ->get(['id', 'runtime', 'version', 'is_default', 'sort_order', 'eol_at']);
    }

    protected function buildSettingsData(): array
    {
        $regionQuery = $this->regionQuery();

        $reachable = (clone $regionQuery)->where('is_active', true)
            ->where(fn ($q) => $q->where('cluster_status', 'online')->orWhere('is_demo', true));

        return [
            'base_domain' => Setting::get('base_domain', ''),
            'registration_enabled' => Setting::get('registration_enabled', 'true') === 'true',
            'total_cpu_m' => (int) (clone $reachable)->sum('total_cpu_m'),
            'total_memory_mb' => (int) (clone $reachable)->sum('total_memory_mb'),
            'regions' => KnativeRegionResource::collection(
                (clone $regionQuery)->orderBy('name')->get()
            ),
            'executor_images' => $this->activeExecutorImages(),
            'demo_status' => $this->resolveDemoStatus(),
            'gpu_demo_enabled' => Setting::get('gpu_demo_enabled', 'false') === 'true',
            'custom_runtimes_enabled' => Setting::get('custom_runtimes_enabled', 'false') === 'true',
            // may projects allow callers of other organizations (platform setting, docs/internal-network.md)
            'internal_cross_tenant_grants' => InternalNetwork::crossTenantGrantsEnabled(),
            // The organization's time zone: the timed warmers' crons run in it (OrgTimezone)
            'timezone' => OrgTimezone::get(),
        ];
    }

    protected function resolveDemoStatus(): string
    {
        $raw = Setting::get('demo_status', 'idle');

        if (in_array($raw, ['seeding', 'removing'])) {
            return $raw;
        }

        $demoRegion = KnativeRegion::where('is_demo', true)->first();

        if (! $demoRegion) {
            return 'idle';
        }

        return Project::where('knative_region_id', $demoRegion->id)->exists() ? 'loaded' : 'idle';
    }
}
