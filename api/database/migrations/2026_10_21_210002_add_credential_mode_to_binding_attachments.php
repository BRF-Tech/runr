<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * binding_attachments.credential_mode (docs/bindings.md, "Dynamic credentials"): static — the
 * binding's own user and password in the project's Secret <slug>-bindings, as before —, or
 * dynamic — a Postgres user of its own per project, issued by the region's OpenBao for a limited
 * time and renewed in the region (DynamicDbCredentials).
 *
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0079_add_credential_mode_to_binding_attachments.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('binding_attachments') && ! Schema::hasColumn('binding_attachments', 'credential_mode')) {
            Schema::table('binding_attachments', function (Blueprint $table) {
                $table->string('credential_mode', 16)->default('static');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('binding_attachments') && Schema::hasColumn('binding_attachments', 'credential_mode')) {
            Schema::table('binding_attachments', function (Blueprint $table) {
                $table->dropColumn('credential_mode');
            });
        }
    }
};
