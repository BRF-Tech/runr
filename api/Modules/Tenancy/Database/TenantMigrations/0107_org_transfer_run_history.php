<?php

use App\Support\OrgTransfer\RunHistorySchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_22_520002_org_transfer_run_history.php: imported
 * deployments (deployments.imported_at) and the read-only usage / bill history of an organization
 * import (imported_history), in the tenant's schema. Additive only.
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
