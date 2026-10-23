<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * region_secret_settings: the secret features of a region (docs/secret-stores.md) — whether
 * External Secrets Operator runs on its cluster (eso_*). Platform-wide (central schema only, read
 * from tenant context through the central connection like region_supply_chain_settings); a region
 * without a row has none installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('region_secret_settings')) {
            return;
        }

        Schema::create('region_secret_settings', function (Blueprint $table) {
            $table->string('knative_region_id', 26)->primary();
            $table->string('owner_tenant_id')->nullable();   // tenant-owned region: the tenant to run its jobs in
            $table->string('eso_status', 16)->default('not_installed'); // not_installed | installing | ready | uninstalling | error
            $table->text('eso_message')->nullable();
            $table->string('eso_chart_version', 32)->nullable();
            $table->timestamp('eso_installed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region_secret_settings');
    }
};
