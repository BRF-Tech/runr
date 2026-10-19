<?php

namespace Modules\Billing\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Modules\Billing\Models\BillingSetting;
use Modules\Billing\Support\BillingContext;

/**
 * Billing settings of the organization the request or job runs in (docs/billing.md#webhook):
 * webhook URL and signing secret, and the day billing started (months before it are not billed).
 * Stored in the organization's billing_settings; the install / platform falls back to the
 * environment (BILLING_WEBHOOK_URL / BILLING_WEBHOOK_SECRET), a tenant to nothing.
 */
class BillingSettings
{
    public const WEBHOOK_URL = 'webhook_url';

    public const WEBHOOK_SECRET = 'webhook_secret';

    public const STARTED_ON = 'started_on';

    /** Currencies of the 0.5.7 prices the upgrade imported (JSON list; install / platform). */
    public const LEGACY_CURRENCIES = 'legacy_currencies';

    /** @var array<string, array<string, string|null>> organization → key → value */
    private array $memo = [];

    public function get(string $key): ?string
    {
        $org = BillingContext::key();
        if (! array_key_exists($org, $this->memo)) {
            try {
                $this->memo[$org] = BillingSetting::query()->pluck('value', 'key')->map(fn ($v) => $v === null ? null : (string) $v)->all();
            } catch (QueryException) {
                $this->memo[$org] = [];
            }
        }

        return $this->memo[$org][$key] ?? null;
    }

    public function has(string $key): bool
    {
        $this->get($key);

        return array_key_exists($key, $this->memo[BillingContext::key()] ?? []);
    }

    public function set(string $key, ?string $value): void
    {
        if ($value === null) {
            BillingSetting::query()->whereKey($key)->delete();
        } else {
            BillingSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $this->flush();
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    private function usesEnvironment(): bool
    {
        return ! BillingContext::inTenant();
    }

    public function webhookUrl(): string
    {
        if ($this->has(self::WEBHOOK_URL)) {
            return trim((string) $this->get(self::WEBHOOK_URL));
        }

        return $this->usesEnvironment() ? trim((string) config('billing.webhook.url', '')) : '';
    }

    public function webhookUrlSource(): ?string
    {
        if ($this->has(self::WEBHOOK_URL)) {
            return 'settings';
        }

        return $this->usesEnvironment() && trim((string) config('billing.webhook.url', '')) !== '' ? 'config' : null;
    }

    public function webhookSecret(): string
    {
        $stored = $this->get(self::WEBHOOK_SECRET);
        if ($stored !== null && $stored !== '') {
            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable) {
                return ''; // undecryptable after an APP_KEY change: deliveries go unsigned until it is set again
            }
        }

        return $this->usesEnvironment() ? (string) config('billing.webhook.secret', '') : '';
    }

    /** The first day billed in this organization (set when billing first runs here). */
    public function startedOn(): string
    {
        $value = $this->get(self::STARTED_ON);
        if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        $today = Carbon::today()->toDateString();
        try {
            $this->set(self::STARTED_ON, $today);
        } catch (QueryException) {
            // tables not migrated yet in this organization
        }

        return $today;
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'webhook_url' => ['sometimes', 'nullable', 'string', 'max:2000', 'url:http,https'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'min:16', 'max:255'],
            'clear_secrets' => ['sometimes', 'array'],
            'clear_secrets.*' => ['string', 'in:webhook_secret'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): void
    {
        // null → the environment's value again (install / platform), none (tenant)
        if (array_key_exists('webhook_url', $data)) {
            $url = $data['webhook_url'] === null ? null : trim((string) $data['webhook_url']);
            $this->set(self::WEBHOOK_URL, $url === '' ? null : $url);
        }

        // Write-only secret: omitted / null → unchanged; listed in clear_secrets → removed
        if (in_array('webhook_secret', (array) ($data['clear_secrets'] ?? []), true)) {
            $this->set(self::WEBHOOK_SECRET, null);
        } elseif (isset($data['webhook_secret']) && $data['webhook_secret'] !== '') {
            $this->set(self::WEBHOOK_SECRET, Crypt::encryptString((string) $data['webhook_secret']));
        }
    }

    public function payload(?float $defaultMarkup = null): array
    {
        return array_filter([
            'enabled' => true,
            'currency' => BillingContext::currency(),
            'context' => BillingContext::kind(),
            'webhook' => [
                'url' => $this->webhookUrl() ?: null,
                'url_source' => $this->webhookUrlSource(),
                'secret_set' => $this->webhookSecret() !== '',
            ],
            'started_on' => $this->get(self::STARTED_ON),
            'warnings' => $this->warnings(),
            'default_markup_percent' => $defaultMarkup,
        ], fn ($v, $k) => $k !== 'default_markup_percent' || BillingContext::inTenant(), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * What the administrators of the install / platform should fix: the 0.5.7 prices were imported
     * in another currency than BILLING_CURRENCY (shown until BILLING_CURRENCY matches them).
     *
     * @return list<array{code: string, message: string, legacy_currencies: list<string>, currency: string}>
     */
    public function warnings(): array
    {
        if (BillingContext::inTenant()) {
            return [];
        }

        $legacy = json_decode((string) $this->get(self::LEGACY_CURRENCIES), true);
        $legacy = is_array($legacy) ? array_values(array_filter($legacy, 'is_string')) : [];
        $currency = BillingContext::currency();
        if ($legacy === [] || array_diff($legacy, [$currency]) === []) {
            return [];
        }

        return [[
            'code' => 'legacy_currency_mismatch',
            'message' => __('billing.legacy_currency_mismatch', ['legacy' => implode(', ', $legacy), 'currency' => $currency]),
            'legacy_currencies' => $legacy,
            'currency' => $currency,
        ]];
    }
}
