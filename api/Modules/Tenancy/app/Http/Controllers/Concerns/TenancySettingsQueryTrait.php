<?php

namespace Modules\Tenancy\Http\Controllers\Concerns;

use App\Http\Controllers\Api\Concerns\SettingsQueryTrait;
use App\Models\KnativeRegion;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

trait TenancySettingsQueryTrait
{
    use SettingsQueryTrait {
        SettingsQueryTrait::regionQuery as baseRegionQuery;
        SettingsQueryTrait::buildSettingsData as baseBuildSettingsData;
        SettingsQueryTrait::resolveDemoStatus as baseResolveDemoStatus;
    }

    protected function regionQuery(): Builder
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant) {
            // Tenant context: all regions in tenant schema (synced + tenant-owned)
            return KnativeRegion::query();
        }

        // Central admin: platform regions only (from public schema)
        return KnativeRegion::query()->whereNull('owner_tenant_id');
    }

    protected function resolveDemoStatus(): string
    {
        $tenant = request()->attributes->get('tenant');

        if (! $tenant) {
            return $this->baseResolveDemoStatus();
        }

        $raw = $tenant->data['demo_status'] ?? 'idle';

        if (in_array($raw, ['seeding', 'removing'])) {
            return $raw;
        }

        // Demo regions live in tenant schema (tenant-owned)
        $demoRegion = KnativeRegion::where('is_demo', true)
            ->where('is_synced', false)
            ->first();

        if (! $demoRegion) {
            return 'idle';
        }

        // Query Project directly — region's central connection would miss tenant projects.
        return Project::where('knative_region_id', $demoRegion->id)->exists() ? 'loaded' : 'idle';
    }

    protected function buildSettingsData(): array
    {
        $data = $this->baseBuildSettingsData();
        $data['tenancy_enabled'] = true;

        // Platform regions available for assignment (managed + infinite)
        $regions = KnativeRegion::on('central')
            ->whereNull('owner_tenant_id')
            ->where('is_demo', false)
            ->orderBy('name')
            ->get(['id', 'name', 'region_mode', 'total_cpu_m', 'total_memory_mb']);

        $allocations = DB::connection('central')
            ->table('tenant_regions')
            ->selectRaw('knative_region_id, SUM(cpu_quota_m) as allocated_cpu_m, SUM(memory_quota_mb) as allocated_memory_mb')
            ->groupBy('knative_region_id')
            ->get()
            ->keyBy('knative_region_id');

        $data['assignable_regions'] = $regions->map(function ($r) use ($allocations) {
            $alloc = $allocations->get($r->id);

            return [
                'id' => $r->id,
                'name' => $r->name,
                'region_mode' => $r->region_mode,
                'total_cpu_m' => $r->total_cpu_m,
                'total_memory_mb' => $r->total_memory_mb,
                'allocated_cpu_m' => (int) ($alloc->allocated_cpu_m ?? 0),
                'allocated_memory_mb' => (int) ($alloc->allocated_memory_mb ?? 0),
            ];
        })->toArray();

        $tenant = request()->attributes->get('tenant');

        if ($tenant) {
            // base_domain now comes from Setting::get() with fallback.
            // Tenant overrides via their own settings table; central value is the fallback.

            $quotaMap = $tenant->regions->keyBy('id');

            $data['tenant_quota'] = $quotaMap->map(fn ($r) => [
                'region_id' => $r->id,
                'region_name' => $r->name,
                'cpu_quota_m' => $r->pivot->cpu_quota_m,
                'memory_quota_mb' => $r->pivot->memory_quota_mb,
            ])->values()->all();

            $regions = collect($data['regions']->resolve());
            $totalCpu = 0;
            $totalMem = 0;
            $data['regions'] = $regions->map(function ($r) use ($quotaMap, &$totalCpu, &$totalMem) {
                if (($r['region_mode'] ?? null) === 'infinite') {
                    // Infinite regions: hide real capacity, show as unlimited
                    $r['total_cpu_m'] = 0;
                    $r['total_memory_mb'] = 0;
                    $r['unlimited'] = true;
                } elseif ($quotaMap->has($r['id'])) {
                    // Managed region with quota: use pivot values
                    $r['total_cpu_m'] = (int) $quotaMap[$r['id']]->pivot->cpu_quota_m;
                    $r['total_memory_mb'] = (int) $quotaMap[$r['id']]->pivot->memory_quota_mb;
                }
                $totalCpu += $r['total_cpu_m'] ?? 0;
                $totalMem += $r['total_memory_mb'] ?? 0;

                return $r;
            });

            $data['total_cpu_m'] = $totalCpu;
            $data['total_memory_mb'] = $totalMem;
        }

        return $data;
    }
}
