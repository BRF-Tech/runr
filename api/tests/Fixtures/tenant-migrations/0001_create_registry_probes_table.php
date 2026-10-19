<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant migration another module registers (TenantMigrationRegistry) — the tests check that
 * every path that creates a tenant runs it (TenancySetupTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registry_probes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_probes');
    }
};
