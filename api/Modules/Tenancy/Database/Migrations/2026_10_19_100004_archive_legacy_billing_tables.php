<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade from 0.5.7 (docs/upgrade.md): billing is rebuilt as the Billing module (docs/billing.md)
 * and the tenant-level billing of the Tenancy module goes — plans, subscriptions, purchases (credit,
 * prepaid plan terms, add-ons), capacity deals, charges and the cost configuration.
 *
 * Nothing is lost silently: every row of those tables is copied into billing_legacy_archive (one
 * JSON document per row with its table, id and tenant) before the tables are dropped, together with
 * the settings that only they used (plans_enabled, billing_store_url, billing_usage_thresholds).
 * The Billing module imports what maps onto the new model from the archive — the cost
 * configuration as price records, closed charges as closed platform → tenant bills — and shows the
 * archive to platform administrators (GET /api/billing/legacy-records). billing_events stays: the
 * Billing module keeps writing its event log there.
 *
 * Idempotent: a table that is gone is skipped, archived rows are not archived twice.
 */
return new class extends Migration
{
    protected $connection = 'central';

    /** Dropped in this order (foreign keys: purchases and subscriptions → plans, charges → deals). */
    private const TABLES = ['billing_purchases', 'tenant_subscriptions', 'charges', 'capacity_deals', 'plans', 'cost_configs'];

    private const SETTINGS = ['plans_enabled', 'billing_store_url', 'billing_usage_thresholds'];

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $db = DB::connection($this->connection);

        if (! $schema->hasTable('billing_legacy_archive')) {
            $schema->create('billing_legacy_archive', function (Blueprint $table) {
                $table->id();
                $table->string('source_table', 64)->index();
                $table->string('row_id', 64);
                $table->string('tenant_id')->nullable()->index();
                $table->longText('data');
                $table->timestamp('archived_at')->nullable();

                $table->unique(['source_table', 'row_id']);
            });
        }

        foreach (self::TABLES as $name) {
            if (! $schema->hasTable($name)) {
                continue;
            }

            $hasTenant = $schema->hasColumn($name, 'tenant_id');
            $db->table($name)->orderBy('id')->chunk(500, function ($rows) use ($db, $name, $hasTenant) {
                foreach ($rows as $row) {
                    $this->archive($db, $name, (string) $row->id, $hasTenant ? ($row->tenant_id ?? null) : null, (array) $row);
                }
            });

            $schema->drop($name);
        }

        if ($schema->hasTable('settings')) {
            foreach ($db->table('settings')->whereIn('key', self::SETTINGS)->get() as $setting) {
                $this->archive($db, 'settings', (string) $setting->key, null, (array) $setting);
            }
            $db->table('settings')->whereIn('key', self::SETTINGS)->delete();
        }
    }

    /**
     * No rollback past 0.5.8: the tables are not recreated (their rows stay readable in
     * billing_legacy_archive), so the migrations before this one would fail on them. Going back to
     * 0.5.7 is restoring the database backup taken before the upgrade (docs/upgrade.md#rolling-back).
     */
    public function down(): void
    {
        throw new RuntimeException('RunR 0.5.8 cannot be rolled back with migrate:rollback: the upgrade archived and dropped the 0.5.7 billing tables. To go back to 0.5.7, restore the database backup taken before the upgrade (docs/upgrade.md#rolling-back).');
    }

    /** @param  array<string, mixed>  $data */
    private function archive(Connection $db, string $table, string $rowId, ?string $tenantId, array $data): void
    {
        $exists = $db->table('billing_legacy_archive')->where('source_table', $table)->where('row_id', $rowId)->exists();
        if ($exists) {
            return;
        }

        $db->table('billing_legacy_archive')->insert([
            'source_table' => $table,
            'row_id' => $rowId,
            'tenant_id' => $tenantId,
            'data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'archived_at' => now(),
        ]);
    }
};
