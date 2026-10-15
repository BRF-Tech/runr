<?php

namespace App\Services\Bindings;

use App\Events\BindingAudited;
use App\Models\Binding;
use App\Models\KnativeRegion;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Log;

/**
 * Binding actions as domain events (BindingAudited) plus an application log line — never with
 * credential values. The audit log itself records the API requests (App\Services\Audit\
 * AuditCatalog: binding.create, binding.attach, binding.credentials.reveal, …); work without a
 * request — the purge after the retention — goes to the audit log through system().
 */
class BindingAudit
{
    public static function binding(string $action, Binding $binding, array $meta = []): void
    {
        self::record($action, self::subject($binding), $meta);
    }

    /** An action of the platform itself (scheduled job): domain event + audit log entry. */
    public static function system(string $action, Binding $binding, array $meta = []): void
    {
        self::record($action, self::subject($binding), $meta);

        app(AuditLogger::class)->record($action, [
            'actor' => 'system',
            'target' => ['type' => 'binding', 'id' => $binding->id, 'name' => $binding->name],
            'metadata' => ['type' => $binding->type, 'plan' => $binding->plan, 'region_id' => $binding->knative_region_id] + $meta,
        ]);
    }

    public static function region(string $action, KnativeRegion $region, string $service, array $meta = []): void
    {
        self::record($action, [
            'region_id' => $region->getKey(),
            'region_name' => $region->name,
            'service' => $service,
        ], $meta);
    }

    public static function record(string $action, array $subject, array $meta = []): void
    {
        $actor = auth()->id();
        $tenant = BindingTenancy::tenantKey();

        Log::info('bindings: '.$action, ['actor' => $actor, 'tenant' => $tenant] + $subject + ['meta' => $meta]);

        event(new BindingAudited($action, $actor !== null ? (string) $actor : null, $tenant, $subject, $meta));
    }

    private static function subject(Binding $binding): array
    {
        return [
            'binding_id' => $binding->id,
            'binding_name' => $binding->name,
            'type' => $binding->type,
            'plan' => $binding->plan,
            'region_id' => $binding->knative_region_id,
        ];
    }
}
