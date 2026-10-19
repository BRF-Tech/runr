<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSO sign-in handoff (docs/security.md): the organization the sign-in ran in — a tenant's
 * sign-in page — so the exchange starts the console in that tenant. Nullable: sign-ins of the
 * platform page and of single-tenant installs have none, and codes issued before the upgrade
 * (60 seconds) exchange as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sign_in_handoffs') || Schema::hasColumn('sign_in_handoffs', 'tenant_id')) {
            return;
        }

        Schema::table('sign_in_handoffs', function (Blueprint $table) {
            $table->string('tenant_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sign_in_handoffs', 'tenant_id')) {
            Schema::table('sign_in_handoffs', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }
};
