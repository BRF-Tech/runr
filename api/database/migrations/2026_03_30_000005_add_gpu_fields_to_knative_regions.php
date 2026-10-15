<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('gpu_mode')->default('none')->after('sandbox_build_runtime');
            $table->json('gpu_available')->nullable()->after('gpu_mode');
            $table->json('gpu_allocated')->nullable()->after('gpu_available');
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn(['gpu_mode', 'gpu_available', 'gpu_allocated']);
        });
    }
};
