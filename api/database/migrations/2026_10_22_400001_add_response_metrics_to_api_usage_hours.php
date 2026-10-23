<?php

use App\Services\ApiProduct\ApiResponseMetrics;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the functions behind key / JWT routes answered, per usage hour, gateway, route, consumer and
 * key (docs/api-product.md#usage), as gateway-auth's metering proxy reports it: status classes,
 * upstream errors, the latency sum and its histogram (latency_b00 … latency_b19,
 * ApiResponseMetrics), request and answer bytes. And gateways.developer_page_metrics: the public
 * developer page shows the routes' latency and success rate (no counts, no consumers).
 *
 * Tenant copy: Modules/Tenancy/Database/TenantMigrations/0100. Additive: rows of earlier hours read
 * zero (no answers measured).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_usage_hours') && ! Schema::hasColumn('api_usage_hours', 'status_2xx')) {
            Schema::table('api_usage_hours', function (Blueprint $table) {
                foreach (ApiResponseMetrics::columns() as $column) {
                    $table->unsignedBigInteger($column)->default(0);
                }
            });
        }

        if (Schema::hasTable('gateways') && ! Schema::hasColumn('gateways', 'developer_page_metrics')) {
            Schema::table('gateways', function (Blueprint $table) {
                $table->boolean('developer_page_metrics')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('api_usage_hours', 'status_2xx')) {
            Schema::table('api_usage_hours', function (Blueprint $table) {
                $table->dropColumn(ApiResponseMetrics::columns());
            });
        }
        if (Schema::hasColumn('gateways', 'developer_page_metrics')) {
            Schema::table('gateways', function (Blueprint $table) {
                $table->dropColumn('developer_page_metrics');
            });
        }
    }
};
