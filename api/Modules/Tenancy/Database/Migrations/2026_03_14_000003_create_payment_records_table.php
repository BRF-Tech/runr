<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id');
            $table->foreignUlid('capacity_deal_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->date('payment_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['pending', 'paid', 'overdue'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_records');
    }
};
