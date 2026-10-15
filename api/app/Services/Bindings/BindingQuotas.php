<?php

namespace App\Services\Bindings;

use App\Models\Binding;

/**
 * Organization limits of bindings (docs/bindings.md "Quotas"), counted from the declared sizes —
 * what a binding may grow to, not what it uses today:
 *
 *  - max_bindings        number of bindings (every type, deleted ones until they are purged)
 *  - kv_mb               Σ memory limit of KV bindings
 *  - object_gb           Σ bucket quota of object bindings
 *  - postgres_gb         Σ storage of Postgres bindings (dedicated: storage × instances)
 *  - dedicated_postgres  number of dedicated Postgres clusters
 *
 * 0 = unlimited. Defaults: config bindings.quotas; the Tenancy module overrides them per tenant.
 */
class BindingQuotas
{
    public const KEYS = ['max_bindings', 'kv_mb', 'object_gb', 'postgres_gb', 'dedicated_postgres'];

    /** @return array<string, int> */
    public function limits(): array
    {
        $defaults = array_map('intval', array_intersect_key((array) config('bindings.quotas', []), array_flip(self::KEYS)));
        $overrides = array_filter((array) (BindingTenancy::quotaOverrides() ?? []), fn ($v) => $v !== null);

        $limits = [];
        foreach (self::KEYS as $key) {
            $limits[$key] = (int) ($overrides[$key] ?? $defaults[$key] ?? 0);
        }

        return $limits;
    }

    /**
     * What the organization's bindings claim today.
     *
     * @return array<string, int>
     */
    public function used(?string $exceptId = null): array
    {
        $used = array_fill_keys(self::KEYS, 0);

        $query = Binding::query()->where('status', '!=', Binding::STATUS_PURGING);
        if ($exceptId) {
            $query->whereKeyNot($exceptId);
        }

        foreach ($query->get() as $binding) {
            foreach (self::claim($binding->type, $binding->plan, (array) $binding->settings) as $key => $value) {
                $used[$key] += $value;
            }
        }

        return $used;
    }

    /**
     * What one binding claims.
     *
     * @return array<string, int>
     */
    public static function claim(string $type, string $plan, array $settings): array
    {
        $claim = ['max_bindings' => 1];

        switch ($type) {
            case 'kv':
                $claim['kv_mb'] = (int) ($settings['memory_mb'] ?? config('bindings.kv.default_memory_mb'));
                break;
            case 'object':
                $claim['object_gb'] = (int) ($settings['quota_gb'] ?? config('bindings.object.default_quota_gb'));
                break;
            case 'postgres':
                if ($plan === Binding::PLAN_DEDICATED) {
                    $instances = max(1, (int) ($settings['instances'] ?? config('bindings.postgres.dedicated.default_instances')));
                    $claim['postgres_gb'] = $instances * (int) ($settings['storage_gb'] ?? config('bindings.postgres.dedicated.default_storage_gb'));
                    $claim['dedicated_postgres'] = 1;
                } else {
                    $claim['postgres_gb'] = (int) ($settings['storage_gb'] ?? config('bindings.postgres.default_storage_gb'));
                }
                break;
        }

        return $claim;
    }

    /**
     * Does a binding of this type / plan / size fit? $exceptId: the binding being resized.
     *
     * @return array{key: string, limit: int, used: int, requested: int}|null the first exceeded limit
     */
    public function exceeded(string $type, string $plan, array $settings, ?string $exceptId = null): ?array
    {
        $limits = $this->limits();
        $used = $this->used($exceptId);
        $claim = self::claim($type, $plan, $settings);

        foreach ($claim as $key => $requested) {
            $limit = $limits[$key] ?? 0;
            if ($limit > 0 && $limit < $used[$key] + $requested) {
                return ['key' => $key, 'limit' => $limit, 'used' => $used[$key], 'requested' => $requested];
            }
        }

        return null;
    }

    /**
     * Limits, claimed amounts and measured usage (bytes per type) — GET /bindings/quota.
     */
    public function summary(): array
    {
        $measured = ['kv' => 0, 'object' => 0, 'postgres' => 0];
        foreach (Binding::query()->whereIn('type', array_keys($measured))->get(['type', 'usage_bytes']) as $binding) {
            $measured[$binding->type] += (int) $binding->usage_bytes;
        }

        return [
            'limits' => $this->limits(),
            'used' => $this->used(),
            'measured_bytes' => $measured,
        ];
    }
}
