<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('tenant_regions', function (Blueprint $table) {
            $table->string('tenant_id');
            $table->string('knative_region_id', 26); // ULID
            $table->integer('cpu_quota_m')->default(0);
            $table->integer('memory_quota_mb')->default(0);
            $table->timestamps();

            $table->primary(['tenant_id', 'knative_region_id']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreign('knative_region_id')
                ->references('id')
                ->on('knative_regions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('tenant_regions');
    }
};
