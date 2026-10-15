<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event of a binding / data service action (docs/bindings.md "Audit") for listeners
 * (notifications, integrations); App\Services\Bindings\BindingAudit also writes it to the
 * application log. The audit log records the API requests themselves (AuditCatalog) and the
 * purge (binding.purged).
 *
 * Actions: binding.created, binding.updated, binding.attached, binding.detached,
 * binding.attachment_updated, binding.credentials_revealed, binding.credentials_rotated,
 * binding.deletion_scheduled, binding.restored, binding.purged, data_service.installed,
 * data_service.uninstalled, binding_quota.updated
 */
class BindingAudited
{
    use Dispatchable;

    public function __construct(
        public string $action,
        public ?string $actorId,
        public ?string $tenantId,
        public array $subject,
        public array $meta = [],
    ) {}
}
