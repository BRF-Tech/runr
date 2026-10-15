<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant binding limits (docs/bindings.md "Quotas"). A null column falls back to the
 * install-wide default (config bindings.quotas); 0 means unlimited.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('tenant_binding_quotas', function (Blueprint $table) {
            $table->string('tenant_id')->primary();
            $table->unsignedInteger('max_bindings')->nullable();
            $table->unsignedInteger('kv_mb')->nullable();
            $table->unsignedInteger('object_gb')->nullable();
            $table->unsignedInteger('postgres_gb')->nullable();
            $table->unsignedInteger('dedicated_postgres')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('tenant_binding_quotas');
    }
};
