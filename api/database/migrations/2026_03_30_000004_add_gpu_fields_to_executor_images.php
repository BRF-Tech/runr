<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executor_images', function (Blueprint $table) {
            $table->string('category')->default('general')->after('sort_order');
            $table->boolean('gpu_required')->default(false)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('executor_images', function (Blueprint $table) {
            $table->dropColumn(['category', 'gpu_required']);
        });
    }
};
