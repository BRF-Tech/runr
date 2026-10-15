<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans (docs/billing.md) — optional, off by default (setting plans_enabled). A plan bundles
 * prices and limits: its price is the plan fee RunR calculates per period, assigning it to a
 * tenant writes its CPU / memory onto the tenant's region quotas (tenant_regions). Limits: null =
 * unlimited.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('price_monthly', 12, 2)->default(0);
            $table->decimal('price_yearly', 12, 2)->nullable();

            $table->integer('cpu_quota_m')->nullable();
            $table->integer('memory_quota_mb')->nullable();
            $table->integer('max_projects')->nullable();
            $table->integer('gpu_quota')->nullable();
            $table->bigInteger('invocations_per_month')->nullable();
            $table->integer('storage_quota_mb')->nullable();

            $table->json('features')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('plans');
    }
};
