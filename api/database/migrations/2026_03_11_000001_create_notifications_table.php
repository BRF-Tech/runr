<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type');          // resource_threshold, deployment_failed, region_offline
            $table->string('level');         // info, warning, critical
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable(); // { region_id, resource_type, percentage, threshold }
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['read_at', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
