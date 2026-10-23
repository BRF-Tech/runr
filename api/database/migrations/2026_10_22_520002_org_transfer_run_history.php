<?php

use App\Support\OrgTransfer\RunHistorySchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Run history in organization packages (docs/org-export.md#run-history):
 *
 *  - deployments.imported_at: a deployment an organization import brought from another
 *    organization — history only, in a final state; metering never counts it
 *  - imported_history: what an import keeps of the source's usage records and bills, read-only
 *    (GET /api/org-transfers/{id}/history) — never in usage_records or billing_bills, so nothing
 *    is charged or billed twice
 *
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0107_org_transfer_run_history.php
 */
return new class extends Migration
{
    public function up(): void
    {
        RunHistorySchema::up();
    }

    public function down(): void
    {
        RunHistorySchema::down();
    }
};
