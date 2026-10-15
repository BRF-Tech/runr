<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's plan (docs/billing.md): the plan (null = no plan / pay-as-you-go), interval, period,
 * trial and status (trialing | active | canceled). prepaid: the running term was bought in an
 * external system (plan purchase) — RunR calculates no plan fee for it and the plan ends with the
 * term unless another purchase extends it. external_ref: the external system's reference of the
 * subscription.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('tenant_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id')->unique();
            $table->foreignUlid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('interval', 10)->default('month');
            $table->string('status', 20)->default('active');
            $table->boolean('prepaid')->default(false);
            $table->string('external_ref', 191)->nullable()->index();

            $table->boolean('trial_used')->default(false);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('tenant_subscriptions');
    }
};
