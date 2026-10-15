<?php

use App\Services\Observability\ObservabilitySchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Tenant copy of database/migrations/2026_10_11_000001_create_observability_tables.php: the
 * per-tenant observability tables (project tracing / replay settings, replay captures, uptime
 * checks / results / incidents, status page, alert channels / rules / events / deliveries).
 * Region tracing configuration and the status page lookup stay central.
 */
return new class extends Migration
{
    public function up(): void
    {
        ObservabilitySchema::createTenantTables();
    }

    public function down(): void
    {
        ObservabilitySchema::dropTenantTables();
    }
};
