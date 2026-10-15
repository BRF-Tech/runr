<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding wizard of tenants created by self-service signup (organization name, first region,
 * plan). Tenants created by an admin have no row and never see the wizard.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('tenant_onboarding', function (Blueprint $table) {
            $table->string('tenant_id')->primary();
            $table->string('status', 20)->default('pending');
            $table->string('region_id', 26)->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('tenant_onboarding');
    }
};
