<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_configs', function (Blueprint $table) {
            $table->decimal('cpu_price_per_core_month', 10, 4)->default(0)->after('gpu_price_per_unit_ms');
            $table->decimal('memory_price_per_gb_month', 10, 4)->default(0)->after('cpu_price_per_core_month');
        });
    }

    public function down(): void
    {
        Schema::table('cost_configs', function (Blueprint $table) {
            $table->dropColumn(['cpu_price_per_core_month', 'memory_price_per_gb_month']);
        });
    }
};
