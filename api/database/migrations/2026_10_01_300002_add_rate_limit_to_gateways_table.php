<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-gateway Traefik rateLimit Middleware (average/burst requests per
 * second). NULL average = no rate limit. Tenant copy: TenantMigrations/0031.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('gateways', 'rate_limit_average')) {
            return;
        }

        Schema::table('gateways', function (Blueprint $table) {
            $table->unsignedInteger('rate_limit_average')->nullable()->after('status');
            $table->unsignedInteger('rate_limit_burst')->nullable()->after('rate_limit_average');
        });
    }

    public function down(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->dropColumn(['rate_limit_average', 'rate_limit_burst']);
        });
    }
};
