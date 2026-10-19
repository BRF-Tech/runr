<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Billing\Database\BillingSchema;
use Modules\Billing\Services\BillingSettings;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\DefaultPrices;

/**
 * Prices and history for billing (docs/upgrade.md, 0.5.7 → 0.5.8):
 *
 *  1. 0.5.7 data (multi-tenant installs; the Tenancy module moved it into billing_legacy_archive
 *     before dropping its tables):
 *     - active cost configurations → price versions effective from 2000-01-01 (the global one →
 *       the platform default prices, region ones → region prices). Units: millicore·ms → vCPU-hour,
 *       MiB·ms → GB-hour, GPU·ms → GPU-hour; monthly CPU / memory prices, binding prices and
 *       multipliers as they were. A 0.5.7 price of 0 meant "not set": the seeded default applies.
 *     - closed charges → closed platform → tenant bills (source legacy, subject
 *       tenant-legacy:<tenant>, next to the tenant's 0.5.8 bills), one per tenant, month and
 *       currency, with the charges' line items and a negative line for the credit they drew;
 *       total = what was due. Void and open charges stay readable in the archive only.
 *     - the billing webhook URL and secret of Settings → Plans & Billing → the platform's webhook.
 *     - the 0.5.7 currencies, with a warning when BILLING_CURRENCY differs (checkCurrency()).
 *  2. The seeded default prices as the platform default when there is none.
 *
 * Runs on every install, also while the module is off (the entrypoint migrates every module's
 * central migrations; BILLING_CURRENCY is then read from the environment). The day billing starts
 * is recorded when the module first runs (BillingSettings::startedOn()).
 *
 * Idempotent: imported rows are marked (billing_settings legacy_imported) and not imported again.
 */
return new class extends Migration
{
    private const PRICE_NOTE = 'Imported from the 0.5.7 cost configuration';

    /** Subject of a converted charge bill (Bill::legacyTenantKey()): never the subject of a 0.5.8 tenant bill. */
    private const LEGACY_SUBJECT = 'tenant-legacy:';

    public function up(): void
    {
        $db = DB::connection();
        $schema = $db->getSchemaBuilder();
        $now = now();

        if ($schema->hasTable('billing_legacy_archive') && ! $this->setting('legacy_imported')) {
            $this->importPrices($now);
            $this->importCharges($now);
            $this->importWebhook($now);
            $this->putSetting('legacy_imported', $now->toDateString());
        }

        if (! $db->table('billing_prices')->whereNull('region_id')->exists()) {
            $db->table('billing_prices')->insert(DefaultPrices::VALUES + [
                'id' => (string) Str::ulid(),
                'region_id' => null,
                'effective_from' => DefaultPrices::SEED_EFFECTIVE_FROM,
                'note' => 'Seeded default prices',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** No rollback past 0.5.8 (docs/upgrade.md#rolling-back): restore the backup taken before the upgrade. */
    public function down(): void
    {
        throw new RuntimeException(BillingSchema::NO_ROLLBACK);
    }

    private function importPrices(DateTimeInterface $now): void
    {
        $rows = DB::table('billing_legacy_archive')->where('source_table', 'cost_configs')->orderBy('id')->get();
        $currencies = [];

        foreach ($rows as $row) {
            $config = $this->decode($row->data);
            if (! $this->truthy($config['is_active'] ?? true)) {
                continue;
            }
            $currency = strtoupper(trim((string) ($config['currency'] ?? '')));
            if ($currency !== '') {
                $currencies[$currency] = true;
            }

            $regionId = $config['region_id'] ?? null;
            if (DB::table('billing_prices')->where('effective_from', DefaultPrices::SEED_EFFECTIVE_FROM)
                ->when($regionId === null, fn ($q) => $q->whereNull('region_id'), fn ($q) => $q->where('region_id', $regionId))->exists()) {
                continue;
            }

            $legacy = fn (string $key) => (float) ($config[$key] ?? 0);
            $set = fn (float $value, string $field) => $value > 0 ? round($value, 8) : DefaultPrices::VALUES[$field];

            $values = [
                'cpu_vcpu_hour' => $set($legacy('cpu_price_per_mc_ms') * 3_600_000_000, 'cpu_vcpu_hour'),
                'memory_gb_hour' => $set($legacy('memory_price_per_mib_ms') * 1024 * 3_600_000, 'memory_gb_hour'),
                'gpu_hour' => $set($legacy('gpu_price_per_unit_ms') * 3_600_000, 'gpu_hour'),
                'cpu_vcpu_month' => $set($legacy('cpu_price_per_core_month'), 'cpu_vcpu_month'),
                'memory_gb_month' => $set($legacy('memory_price_per_gb_month'), 'memory_gb_month'),
                'gpu_month' => DefaultPrices::VALUES['gpu_month'],
                'kv_gb_hour' => $set($legacy('kv_price_per_gb_hour'), 'kv_gb_hour'),
                'object_gb_hour' => $set($legacy('object_price_per_gb_hour'), 'object_gb_hour'),
                'postgres_gb_hour' => $set($legacy('postgres_price_per_gb_hour'), 'postgres_gb_hour'),
                'postgres_instance_hour' => $set($legacy('postgres_instance_price_per_hour'), 'postgres_instance_hour'),
                'multiplier_http' => $set($legacy('multiplier_http'), 'multiplier_http'),
                'multiplier_queue' => $set($legacy('multiplier_queue'), 'multiplier_queue'),
                'multiplier_schedule' => $set($legacy('multiplier_schedule'), 'multiplier_schedule'),
            ];

            // Prices carry no currency any more (BILLING_CURRENCY); the note keeps the 0.5.7 one
            DB::table('billing_prices')->insert($values + [
                'id' => (string) Str::ulid(),
                'region_id' => $regionId,
                'effective_from' => DefaultPrices::SEED_EFFECTIVE_FROM,
                'note' => self::PRICE_NOTE.($currency !== '' ? " (currency {$currency})" : ''),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->checkCurrency(array_keys($currencies));
    }

    /**
     * The imported prices are numbers in the 0.5.7 currency; 0.5.8 reads every price in
     * BILLING_CURRENCY. When they differ the import says so — in the migration log and as
     * legacy_currencies, which GET /api/billing/settings reports as a warning (and the console
     * shows) for as long as BILLING_CURRENCY differs (docs/upgrade.md#billing).
     *
     * @param  list<string>  $currencies
     */
    private function checkCurrency(array $currencies): void
    {
        if ($currencies === []) {
            return;
        }
        sort($currencies);
        $this->putSetting(BillingSettings::LEGACY_CURRENCIES, (string) json_encode($currencies));

        $configured = BillingContext::currency();
        if (array_diff($currencies, [$configured]) !== []) {
            Log::warning(sprintf(
                'Billing: the 0.5.7 prices were in %s, BILLING_CURRENCY is %s. The imported prices are now read as %s amounts: set BILLING_CURRENCY to the currency of the 0.5.7 prices (docs/upgrade.md#billing).',
                implode(', ', $currencies), $configured, $configured,
            ));
        }
    }

    private function importCharges(DateTimeInterface $now): void
    {
        $groups = [];
        foreach (DB::table('billing_legacy_archive')->where('source_table', 'charges')->orderBy('id')->cursor() as $row) {
            $charge = $this->decode($row->data);
            if (($charge['status'] ?? null) !== 'closed' || empty($charge['tenant_id']) || empty($charge['period_start'])) {
                continue;
            }

            $period = substr((string) $charge['period_start'], 0, 7);
            $currency = strtoupper((string) ($charge['currency'] ?? 'USD')) ?: 'USD';
            $groups[$charge['tenant_id'].'|'.$period.'|'.$currency][] = $charge;
        }

        foreach ($groups as $key => $charges) {
            [$tenantId, $period, $currency] = explode('|', $key);
            $subject = self::LEGACY_SUBJECT.$tenantId;
            if (DB::table('billing_bills')->where('subject_key', $subject)->where('period', $period)->where('currency', $currency)->exists()) {
                continue;
            }

            $lines = [];
            $total = 0.0;
            $fixed = 0.0;
            $usage = 0.0;
            $refs = [];
            $closedAt = null;

            foreach ($charges as $charge) {
                $kind = (string) ($charge['kind'] ?? 'manual');
                $items = $charge['line_items'] ?? [];
                $items = is_string($items) ? (json_decode($items, true) ?: []) : (is_array($items) ? $items : []);
                if ($items === []) {
                    $items = [[
                        'description' => (string) ($charge['notes'] ?? ucfirst(str_replace('_', ' ', $kind))),
                        'quantity' => 1, 'unit' => 'item',
                        'unit_price' => (float) ($charge['total'] ?? $charge['amount'] ?? 0),
                        'total' => (float) ($charge['total'] ?? $charge['amount'] ?? 0),
                    ]];
                }
                foreach ($items as $item) {
                    $lines[] = [
                        'type' => 'legacy',
                        'metric' => 'legacy',
                        'description' => (string) ($item['description'] ?? $kind),
                        'region_id' => $item['metadata']['region_id'] ?? null,
                        'region_name' => null,
                        'project_id' => $item['metadata']['project_id'] ?? null,
                        'project_name' => null,
                        'quantity' => (float) ($item['quantity'] ?? 1),
                        'unit' => (string) ($item['unit'] ?? 'item'),
                        'unit_price' => (float) ($item['unit_price'] ?? 0),
                        'multiplier' => 1.0,
                        'markup_percent' => 0.0,
                        'total' => round((float) ($item['total'] ?? 0), 2),
                        'from' => $charge['period_start'],
                        'to' => $charge['period_end'] ?? null,
                        'metadata' => ((array) ($item['metadata'] ?? [])) + ['legacy_charge_id' => $charge['id'] ?? null, 'kind' => $kind],
                    ];
                }

                $credit = round((float) ($charge['credit_applied'] ?? 0), 2);
                if ($credit > 0) {
                    $lines[] = [
                        'type' => 'credit',
                        'metric' => 'legacy',
                        'description' => 'Credit applied (0.5.7)',
                        'region_id' => null, 'region_name' => null, 'project_id' => null, 'project_name' => null,
                        'quantity' => 1, 'unit' => 'item', 'unit_price' => -$credit, 'multiplier' => 1.0, 'markup_percent' => 0.0,
                        'total' => -$credit,
                        'from' => $charge['period_start'],
                        'to' => $charge['period_end'] ?? null,
                        'metadata' => ['legacy_charge_id' => $charge['id'] ?? null, 'kind' => 'credit'],
                    ];
                }

                $due = round((float) ($charge['amount_due'] ?? (($charge['total'] ?? 0) - $credit)), 2);
                $total += $due;
                if ($kind === 'usage') {
                    $usage += $due;
                } else {
                    $fixed += $due;
                }
                if (! empty($charge['external_ref'])) {
                    $refs[] = (string) $charge['external_ref'];
                }
                if (! empty($charge['closed_at']) && ($closedAt === null || $charge['closed_at'] > $closedAt)) {
                    $closedAt = (string) $charge['closed_at'];
                }
            }

            $start = $period.'-01';
            DB::table('billing_bills')->insert([
                'id' => (string) Str::ulid(),
                'kind' => 'tenant',
                'subject_key' => $subject,
                'tenant_id' => $tenantId,
                'period' => $period,
                'period_start' => $start,
                'period_end' => date('Y-m-t', (int) strtotime($start)),
                'currency' => $currency,
                'status' => 'closed',
                'line_items' => json_encode($lines, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'metered_lines' => json_encode([]),
                'fixed_total' => round($fixed, 2),
                'usage_total' => round($usage, 2),
                'total' => round($total, 2),
                'paid' => false,
                'payment_reference' => $refs !== [] ? mb_substr(implode(', ', array_unique($refs)), 0, 191) : null,
                'source' => 'legacy',
                'closed_at' => $closedAt ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function importWebhook(DateTimeInterface $now): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        if ($schema->hasTable('settings') && ! $this->setting('webhook_url')) {
            $url = DB::table('settings')->where('key', 'billing_webhook_url')->value('value');
            if (is_string($url) && trim($url) !== '') {
                $this->putSetting('webhook_url', trim($url));
            }
        }

        if ($schema->hasTable('secure_settings') && ! $this->setting('webhook_secret')) {
            $encrypted = DB::table('secure_settings')->where('key', 'billing_webhook_secret')->value('value');
            if (is_string($encrypted) && $encrypted !== '') {
                try {
                    // secure_settings stores Laravel-encrypted values (encrypted cast)
                    $secret = decrypt($encrypted, false);
                    if (is_string($secret) && $secret !== '') {
                        $this->putSetting('webhook_secret', Crypt::encryptString($secret));
                    }
                } catch (Throwable) {
                    Log::warning('Billing: the 0.5.7 webhook secret could not be read; set it again in the billing settings');
                }
            }
        }
    }

    private function setting(string $key): ?string
    {
        $value = DB::table('billing_settings')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    private function putSetting(string $key, string $value): void
    {
        DB::table('billing_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function decode(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode((string) $data, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
};
