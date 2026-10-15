<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GPU quota of a tenant on a region (docs/ai.md#quotas-and-billing): the number of GPUs its
 * projects and model services may reserve there. null = no GPU limit (default), 0 = no GPUs.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('tenant_regions', function (Blueprint $table) {
            $table->unsignedInteger('gpu_quota')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('tenant_regions', function (Blueprint $table) {
            $table->dropColumn('gpu_quota');
        });
    }
};
