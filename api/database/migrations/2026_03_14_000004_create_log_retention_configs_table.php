<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_retention_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('region_id')->unique()->constrained('knative_regions')->cascadeOnDelete();
            $table->integer('retention_days')->default(90);
            $table->integer('keep_last_n_deployments')->default(5);
            $table->string('storage_backend')->default('loki');
            $table->jsonb('storage_config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_retention_configs');
    }
};
