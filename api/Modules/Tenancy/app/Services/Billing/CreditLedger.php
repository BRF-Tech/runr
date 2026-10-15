<?php

namespace Modules\Tenancy\Services\Billing;

use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Models\Purchase;

/**
 * Prepaid credit of a tenant (docs/billing.md): credit purchases recorded through the billing API
 * minus what closed charges drew from it, per currency. Void purchases and void charges do not
 * count — voiding a closed charge gives its credit back.
 */
class CreditLedger
{
    /**
     * @return array<string, float> currency → balance (only currencies with credit purchases)
     */
    public function balances(string $tenantId): array
    {
        $bought = Purchase::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', Purchase::KIND_CREDIT)
            ->whereNull('voided_at')
            ->get(['amount', 'currency'])
            ->groupBy(fn (Purchase $p) => strtoupper((string) $p->currency))
            ->map(fn ($group) => (float) $group->sum(fn (Purchase $p) => (float) $p->amount));

        if ($bought->isEmpty()) {
            return [];
        }

        $drawn = Charge::query()
            ->where('tenant_id', $tenantId)
            ->where('status', Charge::STATUS_CLOSED)
            ->where('credit_applied', '>', 0)
            ->get(['credit_applied', 'currency'])
            ->groupBy(fn (Charge $c) => strtoupper((string) $c->currency))
            ->map(fn ($group) => (float) $group->sum(fn (Charge $c) => (float) $c->credit_applied));

        $balances = [];
        foreach ($bought as $currency => $amount) {
            $balances[$currency] = max(0.0, round($amount - (float) ($drawn[$currency] ?? 0), 2));
        }
        ksort($balances);

        return $balances;
    }

    public function balance(string $tenantId, string $currency): float
    {
        return $this->balances($tenantId)[strtoupper($currency)] ?? 0.0;
    }
}
