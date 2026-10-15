<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing events (docs/billing.md): billing.period.closed, billing.plan.changed,
 * billing.usage.threshold, billing.charge.voided, billing.test. Kept for GET /api/billing/events
 * and delivered to the billing webhook (signed POST) when one is configured. dedupe_key makes an
 * event that must happen once (a period closing, a threshold crossed) idempotent.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('billing_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 64);
            $table->string('tenant_id')->nullable()->index();
            $table->json('payload');
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->string('delivery_status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['delivery_status', 'next_attempt_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('billing_events');
    }
};
