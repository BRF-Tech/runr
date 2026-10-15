<?php

namespace App\Services\SupplyChain;

use App\Events\SupplyChainAuditEvent;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Audit events of the supply chain and data residency that do not map to one API request
 * (AuditCatalog::EXPLICIT_ACTIONS): decisions taken inside a request and the results of region
 * jobs. Requests themselves (settings, key, admission, scanner DB, residency changes, scans) are
 * recorded by the route catalog with the model changes they made (AuditCatalog).
 *
 *   deploy.policy_blocked     deploy.policy_warned          deploy.policy_overridden
 *   residency.denied          residency.migration_denied    image.signed
 *   region.signing_key.generated / .rotated / .failed
 *   region.admission.installed / .removed / .failed         region.scanner_db.refreshed
 *
 * Each one is written to the audit log (AuditLogger; metadata runs through its secret redactor)
 * and dispatched as SupplyChainAuditEvent. Never carries a private key, a password or a
 * registry credential.
 */
final class SupplyChainAudit
{
    /**
     * @param  array<string, mixed>  $details
     * @param  string  $result  success | denied | failure
     */
    public static function record(string $action, array $details = [], ?string $regionId = null, string $result = AuditEvent::RESULT_SUCCESS): void
    {
        $user = auth()->user();

        app(AuditLogger::class)->record($action, [
            'result' => $result,
            'target' => self::target($details, $regionId),
            'metadata' => $details + ($regionId ? ['region_id' => $regionId] : []),
        ]);

        try {
            event(new SupplyChainAuditEvent(
                action: $action,
                details: $details,
                actorId: $user ? (string) $user->getKey() : null,
                actorName: $user->username ?? $user->email ?? null,
                tenantId: RuntimeRegistry::currentTenantId(),
                regionId: $regionId,
            ));
        } catch (\Throwable $e) {
            Log::warning('Supply-chain audit listener failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** The project / model service of a deploy decision, else the region. */
    private static function target(array $details, ?string $regionId): ?array
    {
        if (! empty($details['model_service_id'])) {
            return ['type' => 'model_service', 'id' => (string) $details['model_service_id'], 'name' => $details['model_service'] ?? null];
        }
        if (! empty($details['project_id'])) {
            return ['type' => 'project', 'id' => (string) $details['project_id'], 'name' => $details['project'] ?? null];
        }

        return $regionId ? ['type' => 'region', 'id' => $regionId] : null;
    }
}
