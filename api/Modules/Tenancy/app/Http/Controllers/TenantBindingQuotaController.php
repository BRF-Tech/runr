<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Services\Bindings\BindingAudit;
use App\Services\Bindings\BindingQuotas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantBindingQuota;

/**
 * Binding limits of a tenant (platform admin): the tenant's overrides, the install defaults
 * and what the tenant's bindings claim today.
 *
 * @tags Tenant
 */
class TenantBindingQuotaController
{
    public function show(string $tenant): JsonResponse
    {
        $model = Tenant::query()->findOrFail($tenant);

        return response()->json(['data' => $this->payload($model)]);
    }

    public function update(Request $request, string $tenant): JsonResponse
    {
        $model = Tenant::query()->findOrFail($tenant);

        $rules = [];
        foreach (BindingQuotas::KEYS as $key) {
            $rules[$key] = ['nullable', 'integer', 'min:0', 'max:10000000'];
        }
        $data = $request->validate($rules);

        TenantBindingQuota::query()->updateOrCreate(['tenant_id' => $model->getTenantKey()], $data);

        BindingAudit::record('binding_quota.updated', ['tenant_id' => $model->getTenantKey()], $data);

        return response()->json(['data' => $this->payload($model)]);
    }

    private function payload(Tenant $tenant): array
    {
        $quotas = app(BindingQuotas::class);
        $overrides = TenantBindingQuota::query()->find($tenant->getTenantKey())?->limits() ?? array_fill_keys(BindingQuotas::KEYS, null);

        $summary = $tenant->run(fn () => $quotas->summary());

        return [
            'tenant_id' => $tenant->getTenantKey(),
            'overrides' => $overrides,
            'defaults' => array_map('intval', array_intersect_key((array) config('bindings.quotas'), array_flip(BindingQuotas::KEYS))),
            'limits' => $summary['limits'],
            'used' => $summary['used'],
        ];
    }
}
