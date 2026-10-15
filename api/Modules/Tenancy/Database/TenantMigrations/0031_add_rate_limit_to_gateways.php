<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same columns as the central gateways table (2026_10_01_300002): optional per-gateway Traefik
 * rateLimit Middleware (average/burst requests per second). NULL average = no rate limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('gateways', 'rate_limit_average')) {
            return;
        }

        Schema::table('gateways', function (Blueprint $table) {
            $table->unsignedInteger('rate_limit_average')->nullable();
            $table->unsignedInteger('rate_limit_burst')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->dropColumn(['rate_limit_average', 'rate_limit_burst']);
        });
    }
};
