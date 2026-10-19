<?php

namespace Modules\Billing\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\Rbac\Rbac;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\BillingPolicy;
use Modules\Billing\Support\Money;

/**
 * Fixed resource requests and allocations (docs/billing.md#fixed-regions):
 *
 *  - a project in a fixed region requests resources; the monthly price is quoted from the region's
 *    monthly unit prices (× (1 + markup) in a tenant on a shared region) and kept on the request;
 *  - the seller of the region approves (billing.approve) or rejects it; an approved request is the
 *    project's allocation from the approval day, the previous allocation ends the day before
 *    (superseded) — a change of resources is a new request, prorated by day from its approval;
 *  - deleting a project or moving it to another region ends its allocation there;
 *  - a tenant's allocations in platform regions are copied to the central schema (tenant_id), the
 *    platform bills the tenant for them.
 */
class FixedRequests
{
    /** @var array<string, bool> organization → billing tables exist */
    private array $tables = [];

    public function __construct(
        private readonly BillCalculator $calculator,
        private readonly Regions $regions,
        private readonly RegionTypes $types,
        private readonly BillingEvents $events,
        private readonly BillingNotifier $notifier,
        private readonly Presenter $presenter,
    ) {}

    /** The project's allocation in effect (in $regionId, or its current region). */
    public function active(string $projectId, ?string $regionId = null): ?FixedRequest
    {
        try {
            return FixedRequest::query()->own()->active()->where('project_id', $projectId)
                ->when($regionId !== null, fn ($q) => $q->where('region_id', $regionId))
                ->latest('active_from')->first();
        } catch (QueryException) {
            return null;
        }
    }

    public function pending(string $projectId): ?FixedRequest
    {
        return FixedRequest::query()->own()->where('project_id', $projectId)->where('status', FixedRequest::PENDING)->latest()->first();
    }

    /**
     * Monthly price of resources in the project's region, and what is left of the current month
     * from today (the first month is prorated by day).
     */
    public function quote(Project $project, int $cpuM, int $memoryMb, int $gpuCount): array
    {
        $today = CarbonImmutable::today();
        $quote = $this->calculator->monthlyPrice($project->knative_region_id, $cpuM, $memoryMb, $gpuCount, $today);

        return [
            'currency' => BillingContext::currency(),
            'region' => ['id' => $project->knative_region_id, 'name' => $this->regions->name($project->knative_region_id)],
            'billing_type' => $this->types->typeOf($project->knative_region_id),
            'cpu_m' => $cpuM,
            'memory_mb' => $memoryMb,
            'gpu_count' => $gpuCount,
            'monthly_price' => $quote['monthly_price'],
            'breakdown' => $quote['breakdown'],
            'markup_percent' => round($quote['price']->markupPercent, 3),
            'prices' => [
                'cpu_vcpu_month' => $quote['price']->get('cpu_vcpu_month'),
                'memory_gb_month' => $quote['price']->get('memory_gb_month'),
                'gpu_month' => $quote['price']->get('gpu_month'),
                'source' => $quote['price']->source,
            ],
            // What the bill charges for an approval today: the same calculation as the bill's
            'first_month' => $this->calculator->firstMonth($project->knative_region_id, $cpuM, $memoryMb, $gpuCount, $today),
        ];
    }

    /**
     * @param  array{cpu_m: int, memory_mb: int, gpu_count?: int|null, note?: string|null}  $data
     *
     * @throws BillingRefusal
     */
    public function create(Project $project, array $data, Authenticatable $user): FixedRequest
    {
        $regionId = $project->knative_region_id;
        if (! $regionId || ! $this->types->isFixed($regionId)) {
            throw new BillingRefusal('region_not_fixed', __('billing.region_not_fixed'), 422);
        }
        $gpus = (int) ($data['gpu_count'] ?? 0);
        if ($gpus > 0 && ! $this->regions->metersGpu($regionId)) {
            throw new BillingRefusal('gpu_not_available', __('billing.gpu_not_available'), 422);
        }
        $this->assertCoversWhatRuns($project, (int) $data['cpu_m'], (int) $data['memory_mb'], $gpus);

        $quote = $this->calculator->monthlyPrice($regionId, (int) $data['cpu_m'], (int) $data['memory_mb'], $gpus);

        $request = DB::transaction(function () use ($project, $data, $user, $regionId, $gpus, $quote) {
            // A newer request replaces a pending one
            FixedRequest::query()->own()->where('project_id', $project->id)->where('status', FixedRequest::PENDING)
                ->update(['status' => FixedRequest::CANCELLED, 'decision_reason' => __('billing.replaced_by_newer_request'), 'decided_at' => now()]);

            return FixedRequest::query()->create([
                'project_id' => $project->id,
                'project_name' => $project->name,
                'region_id' => $regionId,
                'cpu_m' => (int) $data['cpu_m'],
                'memory_mb' => (int) $data['memory_mb'],
                'gpu_count' => $gpus,
                'status' => FixedRequest::PENDING,
                'monthly_price' => $quote['monthly_price'],
                'currency' => BillingContext::currency(),
                'note' => isset($data['note']) ? mb_substr(trim((string) $data['note']), 0, 1000) : null,
                'requested_by' => (string) $user->getAuthIdentifier(),
            ]);
        });

        $this->events->record(BillingEvents::FIXED_REQUEST_CREATED, ['fixed_request' => $this->presenter->fixedRequest($request)]);
        $this->notifier->notifyPermission('billing.approve', BillingNotifier::FIXED_REQUEST,
            __('billing.notify.request_title', ['project' => $project->name]),
            __('billing.notify.request_body', ['project' => $project->name, 'resources' => $this->resources($request), 'price' => $this->money($request->monthly_price)]),
            ['fixed_request_id' => $request->id, 'project_id' => $project->id],
            '/settings?tab=billing&section=approvals');

        return $request;
    }

    /** @throws BillingRefusal */
    public function cancel(FixedRequest $request): FixedRequest
    {
        if ($request->status !== FixedRequest::PENDING) {
            throw new BillingRefusal('request_not_pending', __('billing.request_not_pending'), 409);
        }

        $request->forceFill(['status' => FixedRequest::CANCELLED, 'decided_at' => now()])->save();

        return $request;
    }

    /**
     * Approve a pending request: the project's allocation from today; the previous one ends
     * yesterday. Refused when the region is not a fixed region (any more), when the project left
     * it, and when the request is smaller than what the project runs now (scale down first).
     *
     * @throws BillingRefusal
     */
    public function approve(FixedRequest $request, Authenticatable $user, ?string $note = null): FixedRequest
    {
        if ($request->status !== FixedRequest::PENDING) {
            throw new BillingRefusal('request_not_pending', __('billing.request_not_pending'), 409);
        }
        if (! $this->types->isFixed($request->region_id)) {
            throw new BillingRefusal('region_not_fixed', __('billing.region_no_longer_fixed'), 409);
        }
        $project = Project::query()->find($request->project_id);
        if (! $project || $project->knative_region_id !== $request->region_id) {
            throw new BillingRefusal('project_moved', __('billing.project_moved'), 409);
        }
        $this->assertCoversWhatRuns($project, $request->cpu_m, $request->memory_mb, $request->gpu_count);

        $today = CarbonImmutable::today();
        $superseded = [];

        DB::transaction(function () use ($request, $user, $note, $today, &$superseded) {
            $previous = FixedRequest::query()->own()->active()->where('project_id', $request->project_id)->get();
            foreach ($previous as $old) {
                $old->forceFill(['status' => FixedRequest::SUPERSEDED, 'active_until' => $today->subDay()->toDateString()])->save();
                $superseded[] = $old;
            }

            $request->forceFill([
                'status' => FixedRequest::APPROVED,
                'decided_by' => (string) $user->getAuthIdentifier(),
                'decided_at' => now(),
                'decision_reason' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null,
                'active_from' => $today->toDateString(),
                'active_until' => null,
            ])->save();
        });

        foreach ([...$superseded, $request] as $row) {
            $this->mirror($row);
        }

        $this->events->record(BillingEvents::FIXED_REQUEST_APPROVED, ['fixed_request' => $this->presenter->fixedRequest($request)]);
        $this->notifyDecision($request, $project, true);

        return $request;
    }

    /** @throws BillingRefusal */
    public function reject(FixedRequest $request, Authenticatable $user, string $reason): FixedRequest
    {
        if ($request->status !== FixedRequest::PENDING) {
            throw new BillingRefusal('request_not_pending', __('billing.request_not_pending'), 409);
        }

        $request->forceFill([
            'status' => FixedRequest::REJECTED,
            'decided_by' => (string) $user->getAuthIdentifier(),
            'decided_at' => now(),
            'decision_reason' => mb_substr(trim($reason), 0, 1000),
        ])->save();

        $this->events->record(BillingEvents::FIXED_REQUEST_REJECTED, ['fixed_request' => $this->presenter->fixedRequest($request)]);
        $this->notifyDecision($request, Project::query()->find($request->project_id), false);

        return $request;
    }

    /**
     * The project was deleted ($regionId null: every region) or left $regionId: its allocation ends
     * today, pending requests are cancelled.
     */
    public function endForProject(string $projectId, ?string $regionId = null): void
    {
        // Billing never ran in this organization (the hooks run with billing off too): nothing to
        // end — and no failing query that would abort the transaction the project is deleted in
        if (! $this->hasTable()) {
            return;
        }

        try {
            $today = CarbonImmutable::today()->toDateString();
            $active = FixedRequest::query()->own()->active()->where('project_id', $projectId)
                ->when($regionId !== null, fn ($q) => $q->where('region_id', $regionId))->get();
            foreach ($active as $allocation) {
                $allocation->forceFill(['status' => FixedRequest::ENDED, 'active_until' => $today])->save();
                $this->mirror($allocation);
            }

            FixedRequest::query()->own()->where('project_id', $projectId)->where('status', FixedRequest::PENDING)
                ->when($regionId !== null, fn ($q) => $q->where('region_id', $regionId))
                ->update(['status' => FixedRequest::CANCELLED, 'decided_at' => now(), 'decision_reason' => __('billing.project_left_region')]);
        } catch (QueryException $e) {
            // billing tables not migrated in this organization yet
            Log::warning('Billing: allocations of a project not ended', ['project' => $projectId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Allocations left in effect although their project is gone or in another region — deleted or
     * moved without the hooks (raw deletes, an earlier release): they end yesterday (at the
     * earliest on their first day), pending requests of missing projects are cancelled. Run by the
     * close job and before bills are listed.
     */
    public function endOrphaned(): int
    {
        if (! $this->hasTable()) {
            return 0;
        }

        try {
            $yesterday = CarbonImmutable::yesterday()->toDateString();
            $projects = Project::query()->pluck('knative_region_id', 'id')->mapWithKeys(fn ($region, $id) => [(string) $id => $region !== null ? (string) $region : null])->all();
            $ended = 0;

            foreach (FixedRequest::query()->own()->active()->get() as $allocation) {
                if (array_key_exists($allocation->project_id, $projects) && $projects[$allocation->project_id] === $allocation->region_id) {
                    continue;
                }
                $allocation->forceFill(['status' => FixedRequest::ENDED, 'active_until' => max($yesterday, $allocation->active_from->toDateString())])->save();
                $this->mirror($allocation);
                $ended++;
            }

            FixedRequest::query()->own()->where('status', FixedRequest::PENDING)->whereNotIn('project_id', array_map('strval', array_keys($projects)))
                ->update(['status' => FixedRequest::CANCELLED, 'decided_at' => now(), 'decision_reason' => __('billing.project_left_region')]);

            return $ended;
        } catch (QueryException $e) {
            Log::warning('Billing: orphaned allocations not ended', ['tenant' => BillingContext::tenantKey(), 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Central copies of allocations whose tenant is gone (deleted without TenantDeletionService
     * ending them): they end yesterday, at the earliest on their first day. Central context only.
     */
    public function endOrphanedCopies(): int
    {
        if (BillingContext::inTenant() || ! BillingContext::multiTenant()) {
            return 0;
        }

        try {
            $central = DB::connection(BillingContext::central());
            $tenants = $central->table('tenants')->pluck('id')->map(fn ($id) => (string) $id)->all();
            $yesterday = CarbonImmutable::yesterday()->toDateString();
            $ended = 0;

            foreach (FixedRequest::on(BillingContext::central())->whereNotNull('tenant_id')->whereNotIn('tenant_id', $tenants)->active()->get() as $copy) {
                $copy->forceFill(['status' => FixedRequest::ENDED, 'active_until' => max($yesterday, $copy->active_from->toDateString())])->save();
                $ended++;
            }

            return $ended;
        } catch (QueryException $e) {
            Log::warning('Billing: allocations of deleted tenants not ended', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Copy a tenant's allocation in a platform region to the central schema (tenant_id, source_id =
     * the tenant's row), where the platform → tenant bill reads it. Outside a tenant, or in its own
     * regions: nothing.
     */
    public function mirror(FixedRequest $request): void
    {
        $tenantId = BillingContext::tenantKey();
        if ($tenantId === null || $request->tenant_id !== null || ! in_array($request->status, FixedRequest::ALLOCATED, true)
            || ! $this->regions->isShared($request->region_id)) {
            return;
        }

        $attributes = $request->only([
            'project_id', 'project_name', 'region_id', 'cpu_m', 'memory_mb', 'gpu_count', 'status', 'monthly_price',
            'currency', 'note', 'requested_by', 'decided_by', 'decided_at', 'decision_reason', 'active_from', 'active_until',
        ]);

        // Found by tenant and source row: a converted installation keeps the organization's own row
        // with the same id centrally (SingleTenantConversion), the copy has an id of its own
        $central = FixedRequest::on(BillingContext::central())->where('tenant_id', $tenantId)->where('source_id', $request->id)->first();
        if ($central === null) {
            $central = (new FixedRequest)->setConnection((string) BillingContext::central());
            $taken = FixedRequest::on(BillingContext::central())->whereKey($request->id)->exists();
            $attributes['id'] = $taken ? (string) Str::ulid() : $request->id;
        }
        $central->forceFill($attributes + ['tenant_id' => $tenantId, 'source_id' => $request->id])->save();
    }

    /** Whether the organization has the billing tables (memoized per organization). */
    private function hasTable(): bool
    {
        return $this->tables[BillingContext::key()] ??= Schema::hasTable('billing_fixed_requests');
    }

    /**
     * In a fixed region the allocation is a hard limit (BillingPolicy::FIXED_HARD_LIMIT): resources
     * smaller than the worst case of what the project runs now (Admission::running — its live
     * deployment at max_scale and its live previews) are refused; the project scales down first. A
     * live deployment without max_scale is refused outright (fixed_max_scale_required): it is set
     * and the project redeployed first.
     *
     * @throws BillingRefusal
     */
    private function assertCoversWhatRuns(Project $project, int $cpuM, int $memoryMb, int $gpus): void
    {
        if (! BillingPolicy::fixedHardLimit()) {
            return;
        }

        $admission = app(Admission::class);
        // Without max_scale the worst case of what runs is unknown: nothing can be checked against it
        if ($admission->runsUnbounded($project)) {
            throw new BillingRefusal('fixed_max_scale_required', __('billing.fixed_running_unbounded'), 422);
        }

        $running = $admission->running($project);
        if ($running['cpu_m'] <= $cpuM && $running['memory_mb'] <= $memoryMb && $running['gpus'] <= $gpus) {
            return;
        }

        throw new BillingRefusal('scale_down_first', __('billing.fixed_scale_down_first', [
            'cpu' => $running['cpu_m'], 'memory' => $running['memory_mb'], 'gpus' => $running['gpus'],
        ]), 422, ['running' => ['cpu_m' => $running['cpu_m'], 'memory_mb' => $running['memory_mb'], 'gpu_count' => $running['gpus']]]);
    }

    public function resources(FixedRequest $request): string
    {
        $parts = [round($request->cpu_m / 1000, 3).' vCPU', round($request->memory_mb / 1024, 3).' GB'];
        if ($request->gpu_count > 0) {
            $parts[] = $request->gpu_count.' GPU';
        }

        return implode(' · ', $parts);
    }

    private function money(float $amount): string
    {
        return number_format($amount, Money::minorUnit(), '.', '').' '.BillingContext::currency();
    }

    private function notifyDecision(FixedRequest $request, ?Project $project, bool $approved): void
    {
        $recipients = array_filter([$request->requested_by, $project?->payerId()]);
        if ($recipients === []) {
            return;
        }

        $users = User::on(app(Rbac::class)->organization()->userConnection())->whereIn('id', array_unique($recipients))->get();
        $name = $request->project_name ?? $request->project_id;
        $this->notifier->notify($users, BillingNotifier::FIXED_REQUEST_DECIDED,
            __($approved ? 'billing.notify.approved_title' : 'billing.notify.rejected_title', ['project' => $name]),
            __($approved ? 'billing.notify.approved_body' : 'billing.notify.rejected_body', [
                'project' => $name, 'resources' => $this->resources($request), 'price' => $this->money($request->monthly_price),
                'reason' => (string) $request->decision_reason,
            ]),
            ['fixed_request_id' => $request->id, 'project_id' => $request->project_id, 'status' => $request->status],
            '/projects/'.$request->project_id.'#billing');
    }
}
