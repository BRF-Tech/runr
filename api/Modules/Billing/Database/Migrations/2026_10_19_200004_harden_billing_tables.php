<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Database\BillingSchema;

/**
 * Billing tables of an earlier 0.5.8 build brought to the current shape (idempotent):
 *
 *  - one price version and one markup version per scope and date (duplicates removed, the latest
 *    kept; unique indexes), so "a new version for the same date replaces it" holds under
 *    concurrent requests too;
 *  - bills unique per subject, first day and currency: a month in which billing was turned off and
 *    on again has a bill before and one after (docs/billing.md#turning-billing-off-and-on);
 *  - amounts with three decimals (currencies with a three-digit minor unit);
 *  - converted 0.5.7 charges under their own subject (tenant-legacy:<tenant>): a closed legacy bill
 *    no longer stands in for the tenant's 0.5.8 bill of the same month.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection();
        BillingSchema::harden($db->getSchemaBuilder(), false);

        foreach ($db->table('billing_bills')->where('source', 'legacy')->where('kind', 'tenant')->where('subject_key', 'like', 'tenant:%')->get(['id', 'tenant_id', 'period_start', 'currency']) as $bill) {
            $subject = 'tenant-legacy:'.$bill->tenant_id;
            $taken = $db->table('billing_bills')->where('subject_key', $subject)->where('period_start', $bill->period_start)->where('currency', $bill->currency)->exists();
            if (! $taken) {
                $db->table('billing_bills')->where('id', $bill->id)->update(['subject_key' => $subject]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException(BillingSchema::NO_ROLLBACK);
    }
};
