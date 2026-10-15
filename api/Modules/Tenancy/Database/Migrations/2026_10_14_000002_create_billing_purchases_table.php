<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases made in an external system and recorded through the billing API (docs/billing.md),
 * each with that system's reference (unique — recording it again returns the stored purchase):
 *
 *  - credit: amount in a currency; closed charges draw on the tenant's credit balance
 *  - plan:   a plan term (plan_id, interval) bought elsewhere — no plan fee is calculated for it
 *  - addon:  limits added to the tenant's plan limits from starts_at until ends_at
 *
 * A purchase reversed in the external system is voided (voided_at): its credit leaves the
 * balance, its add-on limits end.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('billing_purchases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id');
            $table->string('kind', 10);
            $table->string('external_ref', 191)->unique();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->foreignUlid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('interval', 10)->nullable();
            $table->json('limits')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('purchased_at');
            $table->timestamp('voided_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('billing_purchases');
    }
};
