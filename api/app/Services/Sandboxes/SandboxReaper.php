<?php

namespace App\Services\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\Sandbox;
use App\Models\SandboxEvent;
use App\Models\SandboxProcess;
use App\Services\KubernetesClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Housekeeping of the Sandbox API (ReapSandboxesJob, every minute):
 *
 *  1. TTL: alive sandboxes past expires_at are stopped (end_reason ttl)
 *  2. idle: running sandboxes without activity for idle_timeout_seconds are stopped (idle)
 *  3. quota: when an organization used its month's sandbox seconds, its running sandboxes stop (quota)
 *  4. stuck: pending / claiming sandboxes that never started, terminating ones whose pod delete
 *     failed earlier, are finished
 *  5. pods: every live sandbox's pod is checked — a pod that is gone or ended marks its sandbox
 *     failed / lost; and every pod or egress policy labelled as a RunR sandbox without a live row
 *     (an orphan) is deleted
 *  6. retention: ended sandboxes older than sandbox.retention_days are removed with their
 *     processes and events
 */
class SandboxReaper
{
    public function __construct(
        protected SandboxService $service,
        protected SandboxQuotaService $quotas,
    ) {}

    /** @return array<string, int> what was done, per step */
    public function run(): array
    {
        $done = ['ttl' => 0, 'idle' => 0, 'quota' => 0, 'stuck' => 0, 'lost' => 0, 'orphans' => 0, 'pruned' => 0];

        $done['ttl'] = $this->expire();
        $done['idle'] = $this->idle();
        $done['quota'] = $this->quota();
        $done['stuck'] = $this->stuck();

        [$lost, $orphans] = $this->reconcilePods();
        $done['lost'] = $lost;
        $done['orphans'] = $orphans;
        $done['pruned'] = $this->prune();

        return $done;
    }

    public function expire(): int
    {
        $n = 0;
        Sandbox::query()
            ->whereIn('status', [Sandbox::STATUS_PENDING, Sandbox::STATUS_RUNNING, Sandbox::STATUS_PAUSED, Sandbox::STATUS_CLAIMING])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->limit(500)
            ->get()
            ->each(function (Sandbox $s) use (&$n) {
                $this->stop($s, Sandbox::END_TTL);
                $n++;
            });

        return $n;
    }

    public function idle(): int
    {
        $n = 0;
        Sandbox::query()
            ->where('status', Sandbox::STATUS_RUNNING)
            ->where('idle_timeout_seconds', '>', 0)
            ->limit(2000)
            ->get()
            ->filter(fn (Sandbox $s) => ($at = $s->idleExpiresAt()) !== null && $at->lessThanOrEqualTo(now()))
            ->each(function (Sandbox $s) use (&$n) {
                $this->stop($s, Sandbox::END_IDLE);
                $n++;
            });

        return $n;
    }

    /** Organizations over their month's sandbox seconds: their running sandboxes stop. */
    public function quota(): int
    {
        $n = 0;
        $tenants = Sandbox::query()->whereIn('status', [Sandbox::STATUS_RUNNING, Sandbox::STATUS_PAUSED])
            ->select('tenant_id')->distinct()->pluck('tenant_id');

        foreach ($tenants as $tenantId) {
            $max = $this->quotas->limits($tenantId)['max_sandbox_seconds_month'];
            if ($max <= 0 || $this->quotas->secondsThisMonth($tenantId) < $max) {
                continue;
            }

            Sandbox::query()->ownedBy($tenantId)->whereIn('status', [Sandbox::STATUS_RUNNING, Sandbox::STATUS_PAUSED])->get()
                ->each(function (Sandbox $s) use (&$n) {
                    $this->stop($s, Sandbox::END_QUOTA, __('sandboxes.quota_seconds_reached'));
                    $n++;
                });
        }

        return $n;
    }

    public function stuck(): int
    {
        $n = 0;
        $startLimit = now()->subSeconds((int) config('sandbox.start_timeout_seconds', 120) * 3);

        Sandbox::query()->whereIn('status', [Sandbox::STATUS_PENDING, Sandbox::STATUS_CLAIMING])
            ->where('updated_at', '<', $startLimit)->limit(500)->get()
            ->each(function (Sandbox $s) use (&$n) {
                $this->stop($s, Sandbox::END_FAILED, __('sandboxes.start_timeout'));
                $n++;
            });

        Sandbox::query()->where('status', Sandbox::STATUS_TERMINATING)
            ->where('updated_at', '<', now()->subMinutes(2))->limit(500)->get()
            ->each(function (Sandbox $s) use (&$n) {
                // A sandbox stuck in terminating (its pod delete failed earlier): delete again
                $this->stop($s, $s->end_reason ?: Sandbox::END_DELETED, $s->error);
                $n++;
            });

        return $n;
    }

    /**
     * Per region with sandbox pods (or rows): live rows whose pod is gone or ended → failed / lost;
     * labelled pods and egress policies without a live row → deleted.
     *
     * @return array{0: int, 1: int} [lost, orphans]
     */
    public function reconcilePods(): array
    {
        $lost = 0;
        $orphans = 0;

        // Regions: the ones live rows point to, plus those with recently ended sandboxes (orphans)
        $pairs = Sandbox::query()
            ->where(fn ($q) => $q->whereIn('status', Sandbox::LIVE_STATUSES)->orWhere('ended_at', '>', now()->subDay()))
            ->select('knative_region_id', 'tenant_id')->distinct()->get();

        $byRegion = [];
        foreach ($pairs as $pair) {
            $byRegion[$pair->knative_region_id][] = $pair->tenant_id;
        }

        foreach ($byRegion as $regionId => $tenantIds) {
            $region = $this->findRegion($regionId, array_unique($tenantIds));
            if (! $region || ! $region->kubeconfig) {
                continue;
            }

            try {
                [$l, $o] = $this->reconcileRegion($region);
                $lost += $l;
                $orphans += $o;
            } catch (\Throwable $e) {
                Log::warning('sandbox reconcile failed', ['region' => $regionId, 'error' => $e->getMessage()]);
            }
        }

        return [$lost, $orphans];
    }

    /** @return array{0: int, 1: int} */
    public function reconcileRegion(KnativeRegion $region): array
    {
        $k8s = new KubernetesClient((string) $region->kubeconfig);
        $pods = $k8s->listClusterResources('v1', 'Pod', [SandboxManifests::LABEL_SANDBOX => 'true']);
        if ($pods === null) {
            return [0, 0];
        }

        $byName = [];
        foreach ($pods['items'] ?? [] as $pod) {
            $byName[($pod['metadata']['namespace'] ?? '').'/'.($pod['metadata']['name'] ?? '')] = $pod;
        }

        $rows = Sandbox::query()->where('knative_region_id', $region->id)->whereIn('status', Sandbox::LIVE_STATUSES)->get();
        $live = [];
        $lost = 0;
        $grace = now()->subSeconds(30);

        foreach ($rows as $row) {
            $key = $row->namespace.'/'.$row->pod_name;
            $live[$key] = true;
            if ($row->status === Sandbox::STATUS_TERMINATING || $row->created_at->gt($grace)) {
                continue;
            }

            $pod = $byName[$key] ?? null;
            if ($pod === null) {
                $this->finish($row, Sandbox::END_LOST, __('sandboxes.pod_gone'));
                $lost++;

                continue;
            }

            $phase = $pod['status']['phase'] ?? 'Pending';
            if (in_array($phase, ['Failed', 'Succeeded'], true)) {
                $reason = trim(($pod['status']['reason'] ?? $phase).': '.($pod['status']['message'] ?? ''), ': ');
                $this->stop($row, ($pod['status']['reason'] ?? '') === 'DeadlineExceeded' ? Sandbox::END_TTL : Sandbox::END_FAILED, $reason);
                $lost++;
            }
        }

        $orphans = 0;
        foreach ($byName as $key => $pod) {
            if (isset($live[$key])) {
                continue;
            }
            $created = isset($pod['metadata']['creationTimestamp']) ? Carbon::parse($pod['metadata']['creationTimestamp']) : null;
            if ($created && $created->gt($grace)) {
                continue; // just created; its row may not be visible yet
            }
            if ($k8s->deleteResource('v1', 'Pod', $pod['metadata']['name'], $pod['metadata']['namespace'])) {
                $orphans++;
            }
        }

        // Egress policies of sandboxes that are no longer alive
        $policies = $k8s->listClusterResources('networking.k8s.io/v1', 'NetworkPolicy', [SandboxManifests::LABEL_COMPONENT => 'sandbox']);
        $liveIds = $rows->map(fn ($r) => strtolower($r->id))->flip();
        foreach ($policies['items'] ?? [] as $policy) {
            $sid = $policy['metadata']['labels'][SandboxManifests::LABEL_SANDBOX_ID] ?? null;
            if ($sid !== null && ! isset($liveIds[$sid])) {
                $k8s->deleteResource('networking.k8s.io/v1', 'NetworkPolicy', $policy['metadata']['name'], $policy['metadata']['namespace']);
            }
        }

        // Env Secrets (sbx-<id>-env) of sandboxes that are no longer alive
        $secrets = $k8s->listClusterResources('v1', 'Secret', [SandboxManifests::LABEL_COMPONENT => 'sandbox']);
        foreach ($secrets['items'] ?? [] as $secret) {
            $sid = $secret['metadata']['labels'][SandboxManifests::LABEL_SANDBOX_ID] ?? null;
            if ($sid !== null && ! isset($liveIds[$sid])) {
                $k8s->deleteResource('v1', 'Secret', $secret['metadata']['name'], $secret['metadata']['namespace']);
            }
        }

        return [$lost, $orphans];
    }

    public function prune(): int
    {
        $days = (int) config('sandbox.retention_days', 30);
        if ($days <= 0) {
            return 0;
        }

        $ids = Sandbox::query()->whereIn('status', Sandbox::ENDED_STATUSES)
            ->where('ended_at', '<', now()->subDays($days))
            ->limit(1000)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        SandboxProcess::whereIn('sandbox_id', $ids)->delete();
        SandboxEvent::whereIn('sandbox_id', $ids)->delete();

        return Sandbox::whereIn('id', $ids)->delete();
    }

    /** Stop one sandbox in its organization's context; errors are logged, not thrown. */
    protected function stop(Sandbox $sandbox, string $reason, ?string $error = null): void
    {
        try {
            SandboxContext::run($sandbox->tenant_id, fn () => $this->service->terminate($sandbox, $reason, $error));
        } catch (\Throwable $e) {
            Log::warning('sandbox stop failed', ['sandbox' => $sandbox->id, 'reason' => $reason, 'error' => $e->getMessage()]);
        }
    }

    /** The pod is already gone: no delete needed beyond the policy. */
    protected function finish(Sandbox $sandbox, string $reason, string $error): void
    {
        $this->stop($sandbox, $reason, $error);
    }

    /** The region as one of the organizations sees it (tenant-owned regions only exist there). */
    protected function findRegion(string $regionId, array $tenantIds): ?KnativeRegion
    {
        foreach ($tenantIds as $tenantId) {
            try {
                $region = SandboxContext::region($regionId, $tenantId);
                if ($region) {
                    return $region;
                }
            } catch (\Throwable) {
                // tenant gone: try the next one
            }
        }

        return null;
    }
}
