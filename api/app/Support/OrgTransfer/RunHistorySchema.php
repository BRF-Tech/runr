<?php

namespace App\Support\OrgTransfer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema of the run history an organization import brings (docs/org-export.md#run-history),
 * shared by the central migration (2026_10_22_520002) and the tenant migration (0107):
 *
 *  - deployments.imported_at — set on deployments copied from the source organization: history in
 *    a final state (shut_down / failed), never live, never metered (UsageAggregator skips them)
 *  - imported_history — the source's usage records and bills as the import found them, read-only:
 *    kind usage (source_table usage_records / binding_usage_records / workflow_usage) or bill;
 *    never written into usage_records or billing_bills, so nothing is charged twice
 */
final class RunHistorySchema
{
    public static function up(): void
    {
        if (Schema::hasTable('deployments') && ! Schema::hasColumn('deployments', 'imported_at')) {
            Schema::table('deployments', function (Blueprint $table) {
                $table->timestamp('imported_at')->nullable();
            });
        }

        if (! Schema::hasTable('imported_history')) {
            Schema::create('imported_history', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('org_transfer_id', 26);
                $table->string('kind', 16);                     // usage | bill
                $table->string('source_table', 64)->nullable();
                $table->char('project_id', 26)->nullable();      // the imported project
                $table->string('source_project_id', 64)->nullable();
                $table->string('subject')->nullable();           // project / binding / workflow name
                $table->date('period_date')->nullable();         // record date, a bill's period start
                $table->json('data');
                $table->timestamp('created_at')->nullable();

                $table->index(['org_transfer_id', 'kind']);
                $table->index(['project_id', 'kind', 'period_date']);
            });
        }
    }

    public static function down(): void
    {
        Schema::dropIfExists('imported_history');
        if (Schema::hasTable('deployments') && Schema::hasColumn('deployments', 'imported_at')) {
            Schema::table('deployments', function (Blueprint $table) {
                $table->dropColumn('imported_at');
            });
        }
    }
}
