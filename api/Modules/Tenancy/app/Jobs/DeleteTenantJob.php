<?php

namespace Modules\Tenancy\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDeletionService;

/**
 * Tenant deletion, off the HTTP request (TenantController::destroy → 202): K8s teardown of
 * every project across regions can take far longer than a request may run. One run per tenant.
 * See TenantDeletionService for the steps and when the tenant is kept (delete_failed).
 */
class DeleteTenantJob implements ShouldBeUnique, ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable;

    public const TIMEOUT = 1800;

    public int $timeout = self::TIMEOUT;

    public int $tries = 1;

    public int $uniqueFor = self::TIMEOUT;

    public function __construct(
        public string $tenantId,
        public bool $force = false,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(TenantDeletionService $deletion): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $result = $deletion->delete($tenant, $this->force);
        $payload = ['tenant_id' => $this->tenantId, 'slug' => $tenant->slug];

        if ($result['deleted']) {
            $this->broadcastSuccess('tenant.deleted', $payload, "Tenant deleted: {$tenant->slug}");

            return;
        }

        $this->broadcastFailure(
            'tenant.delete_failed',
            $payload,
            new \RuntimeException(implode('; ', $result['failures'])),
            "Tenant deletion incomplete: {$tenant->slug}",
        );
    }

    /**
     * Crashed or timed out mid-way: the tenant must not stay "deleting" forever.
     */
    public function failed(?\Throwable $e): void
    {
        Log::error("DeleteTenantJob failed for tenant {$this->tenantId}: ".$e?->getMessage());

        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $tenant->forceFill([
            'status' => Tenant::STATUS_DELETE_FAILED,
            'status_message' => mb_substr('Deletion aborted: '.($e?->getMessage() ?? 'unknown error'), 0, 2000),
        ])->save();

        if ($e) {
            $this->broadcastFailure('tenant.delete_failed', ['tenant_id' => $this->tenantId, 'slug' => $tenant->slug], $e, "Tenant deletion failed: {$tenant->slug}");
        }
    }
}
