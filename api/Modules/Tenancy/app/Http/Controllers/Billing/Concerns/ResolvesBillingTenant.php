<?php

namespace Modules\Tenancy\Http\Controllers\Billing\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Models\Tenant;

trait ResolvesBillingTenant
{
    /** {tenant} of the billing API: the tenant id or its slug. */
    protected function billingTenant(string $key): Tenant
    {
        return Tenant::query()->whereKey($key)->first()
            ?? Tenant::query()->where('slug', $key)->firstOrFail();
    }

    /**
     * ?period=YYYY-MM → [first day, last day].
     *
     * @return array{0: string, 1: string}|null
     */
    protected function periodRange(Request $request, string $field = 'period'): ?array
    {
        $period = $request->query($field);
        if ($period === null || $period === '') {
            return null;
        }

        if (! is_string($period) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw ValidationException::withMessages([$field => __('api.billing.invalid_period')]);
        }

        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }
}
