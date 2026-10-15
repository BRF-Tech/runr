<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_records → charges (docs/billing.md): what a tenant owes per period, calculated by RunR.
 * Invoicing and payment happen in the operator's billing system, which reads closed charges
 * through the billing API; RunR keeps that system's reference (external_ref).
 *
 *  - kind: capacity_deal | plan | usage | manual
 *  - status: open (period running, amounts provisional) → closed (amounts final) | void
 *  - closed: line_items, total, credit_applied (prepaid credit drawn), amount_due
 *
 * Existing rows: invoice_id becomes external_ref; cancelled rows are void, rows whose period has
 * ended are closed with their invoiced (else recorded) amount, the others stay open.
 */
return new class extends Migration
{
    protected $connection = 'central';

    private const OLD_STATUSES = ['pending', 'paid', 'overdue', 'cancelled', 'refunded'];

    private const STATUSES = ['open', 'closed', 'void'];

    private const PAYMENT_COLUMNS = [
        'invoice_id', 'invoice_status', 'payment_url', 'due_date', 'invoice_total', 'invoiced_at',
        'invoice_attempts', 'invoice_next_attempt_at', 'invoice_error', 'paid_at', 'amount_paid',
        'refunded_amount', 'billing_synced_at',
    ];

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $db = DB::connection($this->connection);

        $schema->table('payment_records', function (Blueprint $table) {
            $table->string('kind', 20)->default('manual');
            $table->string('external_ref', 191)->nullable();
            $table->json('line_items')->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->decimal('credit_applied', 12, 2)->default(0);
            $table->decimal('amount_due', 12, 2)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
        });

        $this->releaseStatus('payment_records', 'payment_records_status_check');

        $table = $db->table('payment_records');
        $table->clone()->whereNotNull('capacity_deal_id')->update(['kind' => 'capacity_deal']);
        $table->clone()->whereNull('capacity_deal_id')->where('notes', 'like', 'Plan %')->update(['kind' => 'plan']);
        $table->clone()->whereNull('capacity_deal_id')->where('notes', 'like', 'Usage %')->update(['kind' => 'usage']);
        $table->clone()->whereNotNull('invoice_id')->update(['external_ref' => DB::raw('invoice_id')]);

        $today = now()->toDateString();
        $table->clone()->where('status', 'cancelled')->update(['status' => 'void', 'voided_at' => now()]);
        $table->clone()->whereIn('status', ['pending', 'paid', 'overdue', 'refunded'])->whereDate('period_end', '<', $today)
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
                'total' => DB::raw('COALESCE(invoice_total, amount)'),
                'amount_due' => DB::raw('COALESCE(invoice_total, amount)'),
            ]);
        $table->clone()->whereIn('status', ['pending', 'paid', 'overdue', 'refunded'])->update(['status' => 'open']);

        $schema->table('payment_records', function (Blueprint $table) {
            $table->dropUnique(['invoice_id']);
        });
        $schema->table('payment_records', function (Blueprint $table) {
            $table->dropColumn([...self::PAYMENT_COLUMNS, 'payment_date']);
        });

        $schema->rename('payment_records', 'charges');

        $schema->table('charges', function (Blueprint $table) {
            $table->index('external_ref');
            $table->index(['status', 'period_start']);
        });

        $this->restrictStatus('charges', 'charges_status_check', self::STATUSES, 'open');
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $db = DB::connection($this->connection);

        $this->releaseStatus('charges', 'charges_status_check');

        $schema->table('charges', function (Blueprint $table) {
            $table->dropIndex(['external_ref']);
            $table->dropIndex(['status', 'period_start']);
        });

        $schema->rename('charges', 'payment_records');

        $schema->table('payment_records', function (Blueprint $table) {
            $table->date('payment_date')->nullable();
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

        $table = $db->table('payment_records');
        $table->clone()->update(['payment_date' => DB::raw('period_end'), 'invoice_total' => DB::raw('total')]);
        $table->clone()->whereNotNull('external_ref')->update(['invoice_id' => DB::raw('external_ref')]);
        $table->clone()->where('status', 'void')->update(['status' => 'cancelled']);
        $table->clone()->whereIn('status', ['open', 'closed'])->update(['status' => 'pending']);

        $schema->table('payment_records', function (Blueprint $table) {
            $table->date('payment_date')->nullable(false)->change();
            $table->dropColumn(['kind', 'external_ref', 'line_items', 'total', 'credit_applied', 'amount_due', 'closed_at', 'voided_at']);
        });

        $this->restrictStatus('payment_records', 'payment_records_status_check', self::OLD_STATUSES, 'pending');
    }

    /**
     * status is an enum: a CHECK constraint on PostgreSQL (named <table>_status_check), a column
     * type elsewhere — a plain string while the values are rewritten.
     */
    private function releaseStatus(string $table, string $constraint): void
    {
        $db = DB::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $db->statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");

            return;
        }

        Schema::connection($this->connection)->table($table, function (Blueprint $t) {
            $t->string('status', 20)->default('open')->change();
        });
    }

    /** @param  list<string>  $statuses */
    private function restrictStatus(string $table, string $constraint, array $statuses, string $default): void
    {
        $db = DB::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($s) => "'{$s}'", $statuses));
            $db->statement("ALTER TABLE {$table} ALTER COLUMN status SET DEFAULT '{$default}'");
            $db->statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK (status IN ({$list}))");

            return;
        }

        Schema::connection($this->connection)->table($table, function (Blueprint $t) use ($statuses, $default) {
            $t->enum('status', $statuses)->default($default)->change();
        });
    }
};
