<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An audit-relevant supply-chain or data-residency action (docs/supply-chain.md, "Audit"):
 * settings changed, signing key generated / rotated, admission policy installed / removed,
 * deploy blocked / overridden / warned, residency changed or a request refused by it, image
 * signed. An audit log listens to this event; SupplyChainAudit also writes it to the log.
 *
 * Never carries secrets (no private key, no password, no registry credential).
 */
class SupplyChainAuditEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $action,
        public readonly array $details,
        public readonly ?string $actorId,
        public readonly ?string $actorName,
        public readonly ?string $tenantId,
        public readonly ?string $regionId,
    ) {}
}
