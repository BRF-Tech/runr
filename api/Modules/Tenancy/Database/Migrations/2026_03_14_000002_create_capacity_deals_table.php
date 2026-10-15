<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacity_deals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id');
            $table->foreignUlid('region_id')->constrained('knative_regions')->cascadeOnDelete();
            $table->integer('cpu_m');
            $table->integer('memory_mb');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('monthly_price', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->decimal('infra_cost', 12, 2)->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['region_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_deals');
    }
};
