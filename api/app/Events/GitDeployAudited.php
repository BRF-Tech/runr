<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A git deploy action (App\Services\GitDeploy\GitAudit lists them) for integrations; the audit
 * log itself records them through AuditCatalog and GitAudit. Not broadcast.
 */
class GitDeployAudited
{
    use Dispatchable;

    public function __construct(
        public readonly string $action,
        public readonly ?string $actorId,
        public readonly ?string $tenantId,
        public readonly ?string $projectId,
        public readonly array $data = [],
    ) {}
}
