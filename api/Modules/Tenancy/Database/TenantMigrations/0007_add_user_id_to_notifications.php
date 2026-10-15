<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create tenant-scoped notifications table.
     *
     * Note: search_path is "tenant_X, public" so Schema::hasTable() would see
     * the central public.notifications too. We use Schema::create() directly
     * which creates in the first schema (tenant) without conflicting with public.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('user_id')->nullable();
            $table->string('type');
            $table->string('level');
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index(['read_at', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
