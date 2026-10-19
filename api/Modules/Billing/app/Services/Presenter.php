<?php

namespace Modules\Billing\Services;

use App\Models\User;
use App\Support\Rbac\Rbac;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\Money;

/**
 * API shapes of bills and fixed resource requests (docs/billing.md#api). The metered cost of a
 * bill (metered_cost, difference, metered_lines) is shown to billing.view holders only — a project
 * owner sees the bill, the seller also what the usage would have cost.
 */
class Presenter
{
    /** @var array<string, array{id: string, name: string|null, email: string|null}|null> */
    private array $users = [];

    /** @var array<string, array{id: string, slug: string|null, name: string|null}|null> */
    private array $tenants = [];

    public function __construct(private readonly Regions $regions) {}

    /**
     * @param  array<string, mixed>|null  $computed  BillCalculator result of an open bill
     */
    public function bill(Bill $bill, ?array $computed = null, bool $withLines = false, bool $withCosts = false): array
    {
        $values = $computed ?? [
            'line_items' => $bill->line_items ?? [],
            'metered_lines' => $bill->metered_lines ?? [],
            'fixed_total' => (float) $bill->fixed_total,
            'usage_total' => (float) $bill->usage_total,
            'total' => (float) $bill->total,
            'metered_cost' => $bill->metered_cost,
            'billing_type' => $bill->billing_type,
        ];
        $scale = Money::minorUnit($bill->currency);

        $owner = null;
        if ($bill->owner_id !== null) {
            $user = $this->user($bill->owner_id);
            $owner = [
                'id' => $bill->owner_id,
                'name' => $bill->isOpen() ? ($user['name'] ?? $bill->owner_name) : ($bill->owner_name ?? $user['name'] ?? null),
                'email' => $bill->isOpen() ? ($user['email'] ?? $bill->owner_email) : ($bill->owner_email ?? $user['email'] ?? null),
            ];
        }

        $data = [
            'id' => $bill->id,
            'kind' => $bill->kind,
            'period' => $bill->period,
            'period_start' => $bill->period_start->toDateString(),
            'period_end' => $bill->period_end->toDateString(),
            'status' => $bill->status,
            'estimate' => $bill->isOpen(),
            'currency' => $bill->currency,
            'source' => $bill->source,
            'project' => $bill->project_id !== null ? ['id' => $bill->project_id, 'name' => $bill->project_name] : null,
            'owner' => $owner,
            'tenant' => $bill->tenant_id !== null ? ($this->tenant($bill->tenant_id) ?? ['id' => $bill->tenant_id, 'slug' => null, 'name' => null]) : null,
            'billing_type' => $values['billing_type'] ?? null,
            'line_items' => $withLines ? array_values($values['line_items'] ?? []) : null,
            'fixed_total' => $this->amount($values['fixed_total'] ?? 0, $scale),
            'usage_total' => $this->amount($values['usage_total'] ?? 0, $scale),
            'total' => $this->amount($values['total'] ?? 0, $scale),
        ];

        if ($withCosts) {
            $metered = $values['metered_cost'] ?? null;
            $data['metered_cost'] = $metered === null ? null : $this->amount($metered, $scale);
            $data['difference'] = $metered === null ? null : Money::roundedFloat(Money::dec($values['total'] ?? 0)->minus(Money::dec($metered)), $scale);
            if ($withLines) {
                $data['metered_lines'] = array_values($values['metered_lines'] ?? []);
            }
        }

        return $data + [
            'payment_url' => $bill->payment_url,
            'payment_url_set_at' => $bill->payment_url_set_at?->toIso8601String(),
            'paid' => (bool) $bill->paid,
            'paid_at' => $bill->paid_at?->toIso8601String(),
            'payment_reference' => $bill->payment_reference,
            'closed_at' => $bill->closed_at?->toIso8601String(),
            'created_at' => $bill->created_at?->toIso8601String(),
            'updated_at' => $bill->updated_at?->toIso8601String(),
        ];
    }

    private function amount(mixed $value, int $scale): float
    {
        return Money::roundedFloat(Money::dec(is_numeric($value) ? $value : 0), $scale);
    }

    public function fixedRequest(FixedRequest $request): array
    {
        $users = [];
        foreach (array_filter([$request->requested_by, $request->decided_by]) as $id) {
            $user = $this->user($id);
            $users[$id] = ['id' => $id, 'name' => $user['name'] ?? null];
        }

        return $request->toApiArray($this->regions->names([$request->region_id]), $users);
    }

    /** @return array{id: string, name: string|null, email: string|null}|null */
    public function user(?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (! array_key_exists($id, $this->users)) {
            try {
                $user = User::on(app(Rbac::class)->organization()->userConnection())->find($id, ['id', 'name', 'username', 'email']);
            } catch (QueryException) {
                $user = null;
            }
            $this->users[$id] = $user ? ['id' => (string) $user->id, 'name' => $user->name ?: $user->username, 'email' => $user->email] : null;
        }

        return $this->users[$id];
    }

    /** @return array{id: string, slug: string|null, name: string|null}|null */
    public function tenant(string $id): ?array
    {
        if (! array_key_exists($id, $this->tenants)) {
            try {
                $row = DB::connection(BillingContext::central())->table('tenants')->where('id', $id)->first(['id', 'slug', 'name']);
            } catch (QueryException) {
                $row = null;
            }
            $this->tenants[$id] = $row ? ['id' => (string) $row->id, 'slug' => $row->slug, 'name' => $row->name] : null;
        }

        return $this->tenants[$id];
    }
}
