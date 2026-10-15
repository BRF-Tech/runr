<?php

use App\Services\Observability\ObservabilitySchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Observability v2 (docs/observability.md): region tracing configuration and the public status
 * page lookup (central), plus the per-tenant tables — project tracing / replay settings, replay
 * captures, uptime checks / results / incidents, the status page, alert channels / rules /
 * events / deliveries. Without Tenancy everything lives in this database.
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0050_create_observability_tables.php
 */
return new class extends Migration
{
    public function up(): void
    {
        ObservabilitySchema::createCentralTables();
        ObservabilitySchema::createTenantTables();
    }

    public function down(): void
    {
        ObservabilitySchema::dropTenantTables();
        ObservabilitySchema::dropCentralTables();
    }
};
