<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('region_id')->nullable()->constrained('knative_regions')->nullOnDelete();
            $table->decimal('cpu_price_per_mc_ms', 20, 12)->default(0.00000001);
            $table->decimal('memory_price_per_mib_ms', 20, 12)->default(0.00000008);
            $table->decimal('gpu_price_per_unit_ms', 20, 12)->default(0.0);
            $table->decimal('multiplier_http', 5, 2)->default(5.0);
            $table->decimal('multiplier_queue', 5, 2)->default(2.0);
            $table->decimal('multiplier_schedule', 5, 2)->default(1.0);
            $table->string('currency', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_configs');
    }
};
