<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSO group mapping (docs/permissions.md): the claim / SAML attribute that carries the user's
 * groups, the role just-in-time provisioned users get, and external group → group / role rows.
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0042_add_sso_group_mapping.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('auth_providers', 'groups_attribute')) {
            Schema::table('auth_providers', function (Blueprint $table) {
                $table->string('groups_attribute')->nullable();
                $table->string('default_role', 64)->nullable();
            });
        }

        if (! Schema::hasTable('sso_group_mappings')) {
            Schema::create('sso_group_mappings', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('auth_provider_id', 26)->nullable(); // null = every provider
                $table->string('external_group', 500);
                $table->char('group_id', 26)->nullable();
                $table->string('role', 64)->nullable();
                $table->timestamps();

                $table->index('auth_provider_id');
                $table->foreign('auth_provider_id')->references('id')->on('auth_providers')->cascadeOnDelete();
                $table->foreign('group_id')->references('id')->on('rbac_groups')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_group_mappings');

        if (Schema::hasColumn('auth_providers', 'groups_attribute')) {
            Schema::table('auth_providers', function (Blueprint $table) {
                $table->dropColumn(['groups_attribute', 'default_role']);
            });
        }
    }
};
