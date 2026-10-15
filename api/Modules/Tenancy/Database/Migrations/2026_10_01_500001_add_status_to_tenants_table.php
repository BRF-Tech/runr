<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant lifecycle: active → deleting (DeleteTenantJob running) → row gone, or → delete_failed
 * (some workload could not be removed; row and database kept for a retry / forced delete).
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('tenants', function (Blueprint $table) {
            $table->string('status', 20)->default('active');
            $table->text('status_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('tenants', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_message']);
        });
    }
};
