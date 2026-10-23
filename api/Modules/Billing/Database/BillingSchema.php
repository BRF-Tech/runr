<?php

namespace Modules\Billing\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * The billing tables (docs/billing.md), shared by the central migration (single-tenant install and
 * the platform of a multi-tenant install) and the tenant migration (each tenant's schema): the same
 * structure in both places, so the same models and services work in every organization.
 *
 *  billing_prices           price versions: region prices or the organization's default prices
 *  billing_region_settings  the billing type of a region (payg | fixed) and whether its queue
 *                           consumers are charged
 *  billing_region_overrides the organization's own choice for a region (queue consumers)
 *  billing_tenant_region_overrides  the platform's choice per tenant and platform region (queue
 *                           consumers; central schema only)
 *  billing_markups          a tenant's markup on shared platform regions (tenant schema only)
 *  billing_fixed_requests   fixed resource requests and approved allocations of projects
 *  billing_bills            monthly bills (project, account, tenant)
 *  billing_events           billing events for the event log and the webhook
 *  billing_settings         webhook settings of the organization, the day billing started (and
 *                           centrally the days billing was on and off, BillingActivity)
 *  billing_archived_bills   bills of deleted tenants (central schema only, BillArchive)
 *  billing_cost_limits      the monthly cost limit of pay-as-you-go projects and this month's state
 *                           of its notifications (CostLimits)
 *
 * The migrations cannot be rolled back (NO_ROLLBACK): going back from 0.5.8 is restoring the backup.
 */
final class BillingSchema
{
    /**
     * Why the billing migrations cannot be rolled back (docs/upgrade.md#rolling-back): the upgrade
     * to 0.5.8 archives and drops the 0.5.7 billing tables and the bills are the books.
     */
    public const NO_ROLLBACK = 'RunR 0.5.8 cannot be rolled back with migrate:rollback: the upgrade replaced the 0.5.7 billing tables and the bills are the books. To go back to 0.5.7, restore the database backup taken before the upgrade (docs/upgrade.md#rolling-back).';

    /** Amounts of bills and fixed requests: up to three decimals (currencies with a three-digit minor unit). */
    private const AMOUNT = [16, 3];

    /** Amount columns per table. */
    private const AMOUNT_COLUMNS = [
        'billing_bills' => ['fixed_total', 'usage_total', 'total', 'metered_cost'],
        'billing_fixed_requests' => ['monthly_price'],
        'billing_archived_bills' => ['fixed_total', 'usage_total', 'total', 'metered_cost'],
    ];

    /** Prices of a version; every version carries all of them. */
    public const PRICE_COLUMNS = [
        'cpu_vcpu_hour' => [18, 8],
        'memory_gb_hour' => [18, 8],
        'gpu_hour' => [18, 8],
        'cpu_vcpu_month' => [14, 4],
        'memory_gb_month' => [14, 4],
        'gpu_month' => [14, 4],
        'kv_gb_hour' => [18, 10],
        'object_gb_hour' => [18, 10],
        'postgres_gb_hour' => [18, 10],
        'postgres_instance_hour' => [18, 10],
        'multiplier_http' => [8, 3],
        'multiplier_queue' => [8, 3],
        'multiplier_schedule' => [8, 3],
    ];

    public static function create(Builder $schema, bool $tenant): void
    {
        if (! $schema->hasTable('billing_prices')) {
            $schema->create('billing_prices', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('region_id', 26)->nullable();
                $table->date('effective_from');
                foreach (self::PRICE_COLUMNS as $column => [$total, $places]) {
                    $table->decimal($column, $total, $places)->default(str_starts_with($column, 'multiplier_') ? 1 : 0);
                }
                $table->string('note')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();

                $table->index(['region_id', 'effective_from']);
            });
        }

        if (! $schema->hasTable('billing_region_settings')) {
            $schema->create('billing_region_settings', function (Blueprint $table) {
                $table->string('region_id', 26)->primary();
                $table->string('billing_type', 8)->default('payg');
                $table->string('changed_by')->nullable();
                $table->timestamps();
            });
        }

        if ($tenant && ! $schema->hasTable('billing_markups')) {
            $schema->create('billing_markups', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('region_id', 26)->nullable();
                $table->decimal('percent', 8, 3);
                $table->date('effective_from');
                $table->string('created_by')->nullable();
                $table->timestamps();

                $table->index(['region_id', 'effective_from']);
            });
        }

        if (! $schema->hasTable('billing_fixed_requests')) {
            $schema->create('billing_fixed_requests', function (Blueprint $table) {
                $table->ulid('id')->primary();
                // Central copies of a tenant's allocations in platform regions (platform → tenant bills)
                $table->string('tenant_id')->nullable();
                $table->string('source_id', 26)->nullable();
                $table->string('project_id', 26);
                $table->string('project_name')->nullable();
                $table->string('region_id', 26);
                $table->unsignedInteger('cpu_m');
                $table->unsignedInteger('memory_mb');
                $table->unsignedInteger('gpu_count')->default(0);
                $table->string('status', 16)->default('pending');
                $table->decimal('monthly_price', ...self::AMOUNT)->default(0);
                $table->string('currency', 3);
                $table->text('note')->nullable();
                $table->string('requested_by')->nullable();
                $table->string('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_reason')->nullable();
                $table->date('active_from')->nullable();
                $table->date('active_until')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'status']);
                $table->index(['region_id', 'status']);
                $table->index('tenant_id');
            });
        }

        if (! $schema->hasTable('billing_bills')) {
            $schema->create('billing_bills', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('kind', 10);
                $table->string('subject_key', 80);
                $table->string('project_id', 26)->nullable();
                $table->string('project_name')->nullable();
                $table->string('owner_id')->nullable();
                $table->string('owner_name')->nullable();
                $table->string('owner_email')->nullable();
                $table->string('tenant_id')->nullable();
                $table->string('period', 7);
                $table->date('period_start');
                $table->date('period_end');
                $table->string('currency', 3);
                $table->string('status', 8)->default('open');
                $table->json('line_items')->nullable();
                $table->json('metered_lines')->nullable();
                $table->decimal('fixed_total', ...self::AMOUNT)->nullable();
                $table->decimal('usage_total', ...self::AMOUNT)->nullable();
                $table->decimal('total', ...self::AMOUNT)->nullable();
                $table->decimal('metered_cost', ...self::AMOUNT)->nullable();
                $table->string('billing_type', 8)->nullable();
                $table->text('payment_url')->nullable();
                $table->timestamp('payment_url_set_at')->nullable();
                $table->boolean('paid')->default(false);
                $table->timestamp('paid_at')->nullable();
                $table->string('payment_reference', 191)->nullable();
                $table->string('source', 8)->default('runr');
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                // One bill per subject and run of billed days (normally the month); converted 0.5.7
                // charges per currency
                $table->unique(['subject_key', 'period_start', 'currency']);
                $table->index(['subject_key', 'period']);
                $table->index(['period', 'status']);
                $table->index('owner_id');
                $table->index('tenant_id');
            });
        }

        // 0.5.7 multi-tenant installs already have the central table (same columns)
        if (! $schema->hasTable('billing_events')) {
            $schema->create('billing_events', function (Blueprint $table) {
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

        if (! $schema->hasTable('billing_settings')) {
            $schema->create('billing_settings', function (Blueprint $table) {
                $table->string('key', 64)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! $tenant) {
            self::createArchivedBills($schema);
            self::createTenantRegionOverrides($schema);
        }

        self::createCostLimits($schema);

        self::addQueueConsumerCharging($schema);
        self::harden($schema, $tenant);
    }

    /**
     * The monthly cost limit of a project (docs/billing.md#monthly-cost-limit): one row per project
     * that has one — the amount in the billing currency, what happens at 100 % (notify | stop) and
     * the month its state belongs to: when the 80 % warning and the 100 % notice went out (once per
     * month and limit), and the month-to-date cost of the last check.
     */
    public static function createCostLimits(Builder $schema): void
    {
        if ($schema->hasTable('billing_cost_limits')) {
            return;
        }

        $schema->create('billing_cost_limits', function (Blueprint $table) {
            $table->string('project_id', 26)->primary();
            $table->decimal('amount', ...self::AMOUNT);
            $table->string('currency', 3);
            $table->string('action', 8)->default('notify');
            $table->string('period', 7)->nullable();
            $table->timestamp('warned_at')->nullable();
            $table->timestamp('reached_at')->nullable();
            $table->decimal('month_cost', ...self::AMOUNT)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * The platform's choice per tenant and platform region (docs/billing.md#queue-consumers),
     * central only: charge_queue_consumers false — the region's queue consumers are not charged to
     * that tenant (nor to its projects); null — as the region. Idempotent: the central migration
     * of the table calls it, and create() too.
     */
    public static function createTenantRegionOverrides(Builder $schema): void
    {
        if ($schema->hasTable('billing_tenant_region_overrides')) {
            return;
        }

        $schema->create('billing_tenant_region_overrides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id');
            $table->string('region_id', 26);
            $table->boolean('charge_queue_consumers')->nullable();
            $table->string('changed_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'region_id']);
            $table->index('region_id');
        });
    }

    /**
     * Whether queue consumers are charged (docs/billing.md#queue-consumers), off by default:
     *
     *  - billing_region_settings.charge_queue_consumers — the region owner's setting;
     *  - billing_region_overrides — the organization's own choice for a region (null: as the
     *    region; false: not charged for the organization's projects there), in every organization.
     *
     * Idempotent: the central and the tenant migration of the setting call it, and create() too.
     */
    public static function addQueueConsumerCharging(Builder $schema): void
    {
        if ($schema->hasTable('billing_region_settings') && ! $schema->hasColumn('billing_region_settings', 'charge_queue_consumers')) {
            $schema->table('billing_region_settings', function (Blueprint $table) {
                $table->boolean('charge_queue_consumers')->default(false);
            });
        }

        if (! $schema->hasTable('billing_region_overrides')) {
            $schema->create('billing_region_overrides', function (Blueprint $table) {
                $table->string('region_id', 26)->primary();
                $table->boolean('charge_queue_consumers')->nullable();
                $table->string('changed_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Central archive of the project and account bills of deleted tenants: their schema is dropped
     * with the tenant, the bills are copied here first (BillArchive) — one row per bill with its
     * line items, amounts, payer, paid state and payment link, and the tenant it belonged to.
     */
    public static function createArchivedBills(Builder $schema): void
    {
        if ($schema->hasTable('billing_archived_bills')) {
            return;
        }

        $schema->create('billing_archived_bills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id');
            $table->string('tenant_slug')->nullable();
            $table->string('tenant_name')->nullable();
            // The bill's id in the tenant's schema
            $table->string('bill_id', 26);
            $table->string('kind', 10);
            $table->string('period', 7);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('project_id', 26)->nullable();
            $table->string('project_name')->nullable();
            $table->string('owner_id')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('currency', 3);
            $table->string('status', 8);
            $table->string('billing_type', 8)->nullable();
            $table->json('line_items')->nullable();
            $table->json('metered_lines')->nullable();
            $table->decimal('fixed_total', ...self::AMOUNT)->nullable();
            $table->decimal('usage_total', ...self::AMOUNT)->nullable();
            $table->decimal('total', ...self::AMOUNT)->nullable();
            $table->decimal('metered_cost', ...self::AMOUNT)->nullable();
            $table->text('payment_url')->nullable();
            $table->timestamp('payment_url_set_at')->nullable();
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 191)->nullable();
            $table->string('source', 8)->default('runr');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('bill_created_at')->nullable();
            $table->timestamp('archived_at');

            $table->unique(['tenant_id', 'bill_id']);
            $table->index(['period', 'tenant_id']);
            $table->index('tenant_slug');
        });
    }

    /**
     * Brings tables made by an earlier build to the current shape (idempotent):
     *
     *  - one price version (one markup version) per scope and date: duplicates are removed, the
     *    latest kept, and unique indexes added — region scopes on (region_id, effective_from), the
     *    default scope (region_id null) with a partial index on effective_from;
     *  - bills unique per subject, first day and currency (several runs of billed days in a month);
     *  - amounts with three decimals.
     */
    public static function harden(Builder $schema, bool $tenant): void
    {
        foreach (['billing_prices' => 'billing_prices_scope_date_unique', 'billing_markups' => 'billing_markups_scope_date_unique'] as $table => $index) {
            if (! $schema->hasTable($table) || self::hasIndex($schema, $table, $index)) {
                continue;
            }
            self::deduplicateVersions($schema, $table);
            $schema->table($table, fn (Blueprint $t) => $t->unique(['region_id', 'effective_from'], $index));
            // NULL region ids are distinct in a unique index: the default scope gets its own
            $schema->getConnection()->statement("CREATE UNIQUE INDEX {$table}_default_date_unique ON {$table} (effective_from) WHERE region_id IS NULL");
        }

        if ($schema->hasTable('billing_bills') && ! self::hasIndex($schema, 'billing_bills', 'billing_bills_subject_key_period_start_currency_unique')) {
            $schema->table('billing_bills', function (Blueprint $t) use ($schema) {
                if (self::hasIndex($schema, 'billing_bills', 'billing_bills_subject_key_period_currency_unique')) {
                    $t->dropUnique('billing_bills_subject_key_period_currency_unique');
                }
                $t->unique(['subject_key', 'period_start', 'currency']);
                $t->index(['subject_key', 'period']);
            });
        }

        // PostgreSQL (SQLite keeps any precision in a numeric column)
        foreach ($schema->getConnection()->getDriverName() === 'pgsql' ? self::AMOUNT_COLUMNS : [] as $table => $columns) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            $current = collect($schema->getColumns($table))->keyBy('name');
            $narrow = array_values(array_filter($columns, fn (string $c) => $current->has($c) && ! str_contains((string) $current[$c]['type'], self::AMOUNT[0].','.self::AMOUNT[1])));
            if ($narrow !== []) {
                $schema->table($table, function (Blueprint $t) use ($narrow, $table) {
                    foreach ($narrow as $column) {
                        $definition = $t->decimal($column, ...self::AMOUNT);
                        $table === 'billing_fixed_requests' ? $definition->default(0)->change() : $definition->nullable()->change();
                    }
                });
            }
        }
    }

    private static function hasIndex(Builder $schema, string $table, string $name): bool
    {
        return collect($schema->getIndexes($table))->contains(fn (array $index) => strcasecmp($index['name'], $name) === 0);
    }

    /** Several versions of one scope and date (made before the unique index): the latest stays. */
    private static function deduplicateVersions(Builder $schema, string $table): void
    {
        $db = $schema->getConnection();
        $seen = [];
        $rows = $db->table($table)->orderByDesc('updated_at')->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'region_id', 'effective_from']);
        foreach ($rows as $row) {
            $key = ($row->region_id ?? '*').'|'.substr((string) $row->effective_from, 0, 10);
            if (isset($seen[$key])) {
                $db->table($table)->where('id', $row->id)->delete();

                continue;
            }
            $seen[$key] = true;
        }
    }
}
