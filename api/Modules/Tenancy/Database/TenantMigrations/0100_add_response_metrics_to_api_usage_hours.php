<?php

use App\Services\ApiProduct\ApiResponseMetrics;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_22_400001_add_response_metrics_to_api_usage_hours.php:
 * what the functions behind the organization's key / JWT routes answered per usage row (status
 * classes, upstream errors, latency sum and histogram, bytes) and the developer page's opt-in to
 * show the routes' latency and success rate. Additive only.
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
        if (Schema::hasTable('api_usage_hours') && Schema::hasColumn('api_usage_hours', 'status_2xx')) {
            Schema::table('api_usage_hours', function (Blueprint $table) {
                $table->dropColumn(ApiResponseMetrics::columns());
            });
        }
        if (Schema::hasTable('gateways') && Schema::hasColumn('gateways', 'developer_page_metrics')) {
            Schema::table('gateways', function (Blueprint $table) {
                $table->dropColumn('developer_page_metrics');
            });
        }
    }
};
