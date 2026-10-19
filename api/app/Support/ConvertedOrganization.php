<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single organization a conversion (php artisan tenancy:setup, docs/tenancy.md) turned into the
 * first tenant. The conversion copies the organization's rows into the tenant and keeps them in the
 * central schema, so the central context still sees the organization's projects, model services
 * and bindings: from the conversion day on they are metered and billed in the tenant only, never
 * centrally again (UsageAggregator, the Billing module).
 *
 * Recorded by the conversion in its central state (setting tenancy_setup → converted):
 *   on         the conversion day: the tenant bills from this day, the central bills end the day before
 *   tenant_id  the tenant
 *   ids        the ids of the projects, model services and bindings copied into the tenant
 *
 * since() and contains() answer for the central context only (inside a tenant its rows are its own).
 */
final class ConvertedOrganization
{
    /** The conversion's state (Modules\Tenancy\Services\SingleTenantConversion::STATE_KEY). */
    public const STATE_KEY = 'tenancy_setup';

    /** @var array{on: string|null, tenant_id: string|null, ids: array<string, true>}|null */
    private ?array $record = null;

    /** The conversion day (Y-m-d) in the central context of a converted installation, else null. */
    public function since(): ?string
    {
        return $this->central() ? $this->read()['on'] : null;
    }

    /** The conversion day when $tenantId is the tenant the conversion created (any context). */
    public function tenantSince(string $tenantId): ?string
    {
        $record = $this->read();

        return $record['tenant_id'] === $tenantId ? $record['on'] : null;
    }

    /** Is $id, in the central context, a project, model service or binding the conversion copied into the tenant? */
    public function contains(?string $id): bool
    {
        return $id !== null && $this->central() && isset($this->read()['ids'][$id]);
    }

    private function central(): bool
    {
        return ! (function_exists('tenancy') && tenancy()->initialized);
    }

    /** @return array{on: string|null, tenant_id: string|null, ids: array<string, true>} */
    private function read(): array
    {
        $none = ['on' => null, 'tenant_id' => null, 'ids' => []];
        if (! config('tenancy.enabled')) {
            return $none;
        }

        if ($this->record === null) {
            try {
                $value = DB::connection(PlatformConnection::name())->table('settings')->where('key', self::STATE_KEY)->value('value');
            } catch (QueryException) {
                return $none;
            }
            $state = is_string($value) ? json_decode($value, true) : null;
            $converted = is_array($state) ? ($state['converted'] ?? null) : null;
            $this->record = is_array($converted) && is_string($converted['on'] ?? null)
                ? [
                    'on' => $converted['on'],
                    'tenant_id' => isset($converted['tenant_id']) ? (string) $converted['tenant_id'] : null,
                    'ids' => array_fill_keys(array_map('strval', (array) ($converted['ids'] ?? [])), true),
                ]
                : $none;
        }

        return $this->record;
    }
}
