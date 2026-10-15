<?php

namespace App\Services\Bindings;

use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Services\Bindings\DataServices\KvService;
use App\Services\Bindings\Kv\RedisScripts;
use App\Services\Bindings\Ops\BindingOpsRunner;
use Illuminate\Support\Facades\Log;

/**
 * Keeps region state matching the bindings (current organization context): a region's own Redis
 * keeps its ACL users in memory only, so after a restart they are gone — the ACL users of KV
 * bindings on such a Redis are re-applied (idempotent: reset + the same rules). The managed KV
 * Redis persists them (ACL file on its volume).
 */
class BindingReconciler
{
    public function __construct(
        private KvService $kv,
        private BindingOpsRunner $ops,
    ) {}

    public function reconcile(): int
    {
        $applied = 0;
        $groups = Binding::query()
            ->where('type', 'kv')
            ->whereIn('status', [Binding::STATUS_READY, Binding::STATUS_ROTATING, Binding::STATUS_DELETING])
            ->with('region')
            ->get()
            ->filter(fn (Binding $b) => $b->credential('mode') === 'acl')
            ->groupBy('knative_region_id');

        foreach ($groups as $bindings) {
            $region = $bindings->first()->region;
            $row = $region ? BindingRegionService::forRegion($region, 'kv') : null;
            if (! $row?->isReady() || $row->mode !== 'region') {
                continue;
            }

            $commands = $bindings->map(fn (Binding $b) => RedisScripts::setUser(
                $b,
                array_filter([(string) $b->credential('password'), (string) ($b->previous_credentials['password'] ?? '')]),
                (bool) $b->setting('over_quota', false),
                $b->isActive(),
            ))->values()->all();

            $result = $this->ops->run($region, RedisScripts::commands($this->kv->endpoint($region, $row), (string) config('bindings.kv.image'), 'kv-reconcile', $commands));
            if ($result->ok) {
                $applied += count($commands);
            } else {
                Log::warning('Bindings: KV ACL reconcile failed', ['region' => $region->name, 'error' => $result->tail()]);
            }
        }

        return $applied;
    }
}
