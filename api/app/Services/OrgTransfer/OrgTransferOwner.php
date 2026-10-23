<?php

namespace App\Services\OrgTransfer;

use App\Models\OrgTransfer;
use App\Services\Audit\AuditLogger;
use App\Support\TenantAccess;
use Illuminate\Support\Str;

/**
 * Whose transfers these are: the organization of the current tenant context (multi-tenant), or
 * the installation's single organization ("platform") — the same owner keys as uploaded images.
 * Also the organization's name for the manifest, and the audit trail of both sides.
 */
final class OrgTransferOwner
{
    public const PLATFORM = 'platform';

    public static function tenantId(): ?string
    {
        return TenantAccess::currentId();
    }

    public static function currentKey(): string
    {
        $tenant = self::tenantId();

        return $tenant !== null && $tenant !== '' ? $tenant : self::PLATFORM;
    }

    /** @return array{organization: string, slug: string, tenant_id: ?string, edition: string} */
    public static function organization(): array
    {
        $tenant = TenantAccess::current();
        $name = (string) ($tenant['name'] ?? config('app.name', 'RunR'));

        return [
            'organization' => $name,
            'slug' => (string) ($tenant['slug'] ?? Str::slug($name)),
            'tenant_id' => $tenant['id'] ?? null,
            'edition' => $tenant !== null ? 'multi' : 'single',
        ];
    }

    /**
     * An export or import event of the job (start and end are audited by their routes): in the
     * organization's own trail, with the other side's identity (source organization and RunR
     * version of an import).
     *
     * @param  array<string, mixed>  $metadata  never secrets: counts, names, codes
     */
    public static function audit(OrgTransfer $transfer, string $action, string $result, array $metadata = [], mixed $actor = null): void
    {
        app(AuditLogger::class)->record($action, array_filter([
            'actor' => $actor ?? 'system',
            'tenant_id' => $transfer->tenant_id,
            'result' => $result,
            'target' => ['type' => 'org_transfer', 'id' => $transfer->id, 'name' => $transfer->package_filename ?? $transfer->kind],
            'metadata' => $metadata + ['kind' => $transfer->kind, 'transfer_id' => $transfer->id],
        ], fn ($v) => $v !== null));
    }
}
