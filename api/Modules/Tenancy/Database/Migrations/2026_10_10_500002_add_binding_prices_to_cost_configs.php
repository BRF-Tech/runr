<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prices of binding usage (docs/bindings.md "Usage and billing"): storage per GB-hour of each
 * binding type and the instance hour of a dedicated Postgres cluster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_configs', function (Blueprint $table) {
            $table->decimal('kv_price_per_gb_hour', 14, 8)->default(0);
            $table->decimal('object_price_per_gb_hour', 14, 8)->default(0);
            $table->decimal('postgres_price_per_gb_hour', 14, 8)->default(0);
            $table->decimal('postgres_instance_price_per_hour', 14, 8)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('cost_configs', function (Blueprint $table) {
            $table->dropColumn([
                'kv_price_per_gb_hour',
                'object_price_per_gb_hour',
                'postgres_price_per_gb_hour',
                'postgres_instance_price_per_hour',
            ]);
        });
    }
};
