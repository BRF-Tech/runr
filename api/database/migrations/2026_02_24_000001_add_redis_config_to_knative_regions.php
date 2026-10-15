<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('redis_mode', 20)->nullable();
            $table->string('redis_address')->nullable();
            $table->text('redis_password')->nullable();
            $table->string('redis_status', 30)->nullable();
            $table->text('redis_status_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn(['redis_mode', 'redis_address', 'redis_password', 'redis_status', 'redis_status_message']);
        });
    }
};
