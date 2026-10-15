<?php

namespace App\Services\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\Sandbox;
use App\Models\SandboxPool;
use App\Models\SandboxQuota;
use App\Models\SandboxUsageRecord;
use App\Services\Helpers\KataRuntime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantQuotaService;

/**
 * Organization quotas of the Sandbox API (0 = unlimited):
 *
 *  - max_concurrent: sandboxes alive at once (pending, running, paused)
 *  - max_cpu_m / max_memory_mb: their reserved CPU / memory together
 *  - max_sandbox_seconds_month: run time of all sandboxes in the calendar month (UTC); a running
 *    sandbox is stopped by the reaper when the month's budget is used up
 *  - max_pool_size: warm pool pods of the organization together
 *  - allowed_classes: sandbox classes the organization may use (null: what the region allows)
 *
 * With tenancy the sandboxes' CPU / memory (Kata overhead included) — warm pods too — also count
 * against the organization's region quota (tenant_regions, the same quota projects use).
 */
final class SandboxQuotaService
{
    /**
     * Effective limits: the organization's row, else the defaults row, else config('sandbox.quota').
     *
     * @return array{max_concurrent: int, max_cpu_m: int, max_memory_mb: int, max_sandbox_seconds_month: int, max_pool_size: int, allowed_classes: list<string>|null}
     */
    public function limits(?string $tenantId): array
    {
        $own = $tenantId !== null ? SandboxQuota::where('tenant_id', $tenantId)->first() : null;
        $defaults = SandboxQuota::whereNull('tenant_id')->first();

        $limits = [];
        foreach (SandboxQuota::LIMIT_FIELDS as $field) {
            $limits[$field] = (int) ($own?->{$field} ?? $defaults?->{$field} ?? config("sandbox.quota.{$field}", 0));
        }
        $limits['allowed_classes'] = $own?->allowed_classes ?? $defaults?->allowed_classes;

        return $limits;
    }

    /** @return array{concurrent: int, cpu_m: int, memory_mb: int, sandbox_seconds_month: int, pool_size: int} */
    public function usage(?string $tenantId): array
    {
        $active = Sandbox::query()->ownedBy($tenantId)->active()->get(['cpu_m', 'memory_mb']);

        return [
            'concurrent' => $active->count(),
            'cpu_m' => (int) $active->sum('cpu_m'),
            'memory_mb' => (int) $active->sum('memory_mb'),
            'sandbox_seconds_month' => $this->secondsThisMonth($tenantId),
            'pool_size' => (int) SandboxPool::query()
                ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('is_enabled', true)
                ->sum('size'),
        ];
    }

    /**
     * Admission of a new sandbox (call inside lock()).
     *
     * @throws SandboxException quota_*
     */
    public function check(?string $tenantId, KnativeRegion $region, int $cpuM, int $memoryMb, string $class, bool $platformAdmin = false): void
    {
        $limits = $this->limits($tenantId);
        $usage = $this->usage($tenantId);

        if ($limits['max_concurrent'] > 0 && $limits['max_concurrent'] < $usage['concurrent'] + 1) {
            $this->refuse('quota_concurrent', __('sandboxes.quota_concurrent', ['max' => $limits['max_concurrent']]), $limits, $usage);
        }
        if ($limits['max_cpu_m'] > 0 && $limits['max_cpu_m'] < $usage['cpu_m'] + $cpuM) {
            $this->refuse('quota_cpu', __('sandboxes.quota_cpu', ['max' => $limits['max_cpu_m'], 'used' => $usage['cpu_m']]), $limits, $usage);
        }
        if ($limits['max_memory_mb'] > 0 && $limits['max_memory_mb'] < $usage['memory_mb'] + $memoryMb) {
            $this->refuse('quota_memory', __('sandboxes.quota_memory', ['max' => $limits['max_memory_mb'], 'used' => $usage['memory_mb']]), $limits, $usage);
        }
        if ($limits['max_sandbox_seconds_month'] > 0 && $usage['sandbox_seconds_month'] >= $limits['max_sandbox_seconds_month']) {
            $this->refuse('quota_seconds', __('sandboxes.quota_seconds', ['max' => $limits['max_sandbox_seconds_month']]), $limits, $usage);
        }

        if (! $platformAdmin) {
            $this->checkRegionQuota($tenantId, $region, $cpuM, $memoryMb, $class);
        }
    }

    /**
     * The organization's region quota (tenancy): its projects plus its live sandboxes and warm pods
     * in the region plus the new one must fit.
     *
     * @throws SandboxException quota_region
     */
    public function checkRegionQuota(?string $tenantId, KnativeRegion $region, int $cpuM, int $memoryMb, string $class): void
    {
        if ($tenantId === null || ! SandboxContext::tenancyEnabled() || ($region->region_mode ?? 'managed') === 'infinite'
            || ! class_exists(TenantQuotaService::class)) {
            return;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return;
        }

        $reservation = $this->regionReservation($tenantId, $region->id);
        $overhead = KataRuntime::podOverhead($class === SandboxClassResolver::RUNC ? null : $class);
        $reservation['cpu_m'] += $cpuM + $overhead['cpu_m'];
        $reservation['memory_mb'] += $memoryMb + $overhead['memory_mb'];

        $result = SandboxContext::run($tenantId, fn () => app(TenantQuotaService::class)
            ->checkReservation($tenant, $region, $reservation));

        if (! ($result['allowed'] ?? true)) {
            throw new SandboxException($result['message'] ?? __('sandboxes.quota_region'), 422, 'quota_region', [
                'remaining_cpu_m' => $result['remaining_cpu_m'] ?? 0,
                'remaining_memory_mb' => $result['remaining_memory_mb'] ?? 0,
            ]);
        }
    }

    /** CPU / memory the organization's sandboxes and warm pods hold in a region (Kata overhead included). */
    public function regionReservation(?string $tenantId, string $regionId): array
    {
        $rows = Sandbox::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('knative_region_id', $regionId)
            ->whereIn('status', Sandbox::LIVE_STATUSES)
            ->get(['cpu_m', 'memory_mb', 'sandbox_class']);

        $cpu = 0;
        $mem = 0;
        foreach ($rows as $row) {
            $overhead = KataRuntime::podOverhead($row->sandbox_class === SandboxClassResolver::RUNC ? null : $row->sandbox_class);
            $cpu += $row->cpu_m + $overhead['cpu_m'];
            $mem += $row->memory_mb + $overhead['memory_mb'];
        }

        return ['cpu_m' => $cpu, 'memory_mb' => $mem];
    }

    /** Sandbox seconds of the calendar month so far: metered days before today + today's run time. */
    public function secondsThisMonth(?string $tenantId, ?Carbon $now = null): int
    {
        $now = ($now ?? now())->copy()->utc();
        $monthStart = $now->copy()->startOfMonth();
        $today = $now->copy()->startOfDay();

        $past = (int) SandboxUsageRecord::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereDate('record_date', '>=', $monthStart->toDateString())
            ->whereDate('record_date', '<', $today->toDateString())
            ->sum('sandbox_seconds');

        $todaySeconds = 0;
        $rows = Sandbox::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotNull('started_at')
            ->where('started_at', '<', $now)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $today))
            ->get(['started_at', 'ended_at']);
        foreach ($rows as $row) {
            $todaySeconds += SandboxUsageAggregator::overlapSeconds($row->started_at, $row->ended_at ?? $now, $today, $now);
        }

        return $past + $todaySeconds;
    }

    /** Pool sizes must fit the organization's max_pool_size. */
    public function checkPoolSize(?string $tenantId, int $newTotal): void
    {
        $max = $this->limits($tenantId)['max_pool_size'];
        if ($max > 0 && $newTotal > $max) {
            throw new SandboxException(__('sandboxes.quota_pool', ['max' => $max]), 422, 'quota_pool', ['errors' => ['size' => [__('sandboxes.quota_pool', ['max' => $max])]]]);
        }
    }

    /**
     * Serialize admission per organization (check + insert of the row), so parallel creates cannot
     * both pass the last free slot.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function lock(?string $tenantId, callable $callback): mixed
    {
        return Cache::lock('sandbox-admission:'.($tenantId ?? 'default'), 30)->block(15, $callback);
    }

    private function refuse(string $reason, string $message, array $limits, array $usage): never
    {
        throw new SandboxException($message, 422, $reason, ['limits' => $limits, 'usage' => $usage]);
    }
}
