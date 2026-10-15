<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices of the external billing service (docs/billing-api.md) on payment_records,
 * and the statuses "cancelled" / "refunded" next to pending / paid / overdue.
 */
return new class extends Migration
{
    protected $connection = 'central';

    private const STATUSES = ['pending', 'paid', 'overdue', 'cancelled', 'refunded'];

    private const OLD_STATUSES = ['pending', 'paid', 'overdue'];

    public function up(): void
    {
        Schema::connection($this->connection)->table('payment_records', function (Blueprint $table) {
            $table->string('invoice_id')->nullable()->unique();
            $table->string('invoice_status', 32)->nullable();
            $table->text('payment_url')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('invoice_total', 12, 2)->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $table->unsignedSmallInteger('invoice_attempts')->default(0);
            $table->timestamp('invoice_next_attempt_at')->nullable();
            $table->text('invoice_error')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('amount_paid', 12, 2)->nullable();
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->timestamp('billing_synced_at')->nullable();
        });

        $this->setStatuses(self::STATUSES);
    }

    public function down(): void
    {
        DB::connection($this->connection)->table('payment_records')
            ->whereIn('status', ['cancelled', 'refunded'])
            ->update(['status' => 'paid']);

        $this->setStatuses(self::OLD_STATUSES);

        Schema::connection($this->connection)->table('payment_records', function (Blueprint $table) {
            $table->dropUnique(['invoice_id']);
            $table->dropColumn([
                'invoice_id', 'invoice_status', 'payment_url', 'due_date', 'invoice_total', 'invoiced_at',
                'invoice_attempts', 'invoice_next_attempt_at', 'invoice_error', 'paid_at', 'amount_paid',
                'refunded_amount', 'billing_synced_at',
            ]);
        });
    }

    /**
     * payment_records.status is an enum: a CHECK constraint on PostgreSQL (named
     * payment_records_status_check by PostgreSQL), a rebuilt column elsewhere.
     */
    private function setStatuses(array $statuses): void
    {
        $db = DB::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($s) => "'{$s}'", $statuses));
            $db->statement('ALTER TABLE payment_records DROP CONSTRAINT IF EXISTS payment_records_status_check');
            $db->statement("ALTER TABLE payment_records ADD CONSTRAINT payment_records_status_check CHECK (status IN ({$list}))");

            return;
        }

        Schema::connection($this->connection)->table('payment_records', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->default('pending')->change();
        });
    }
};
