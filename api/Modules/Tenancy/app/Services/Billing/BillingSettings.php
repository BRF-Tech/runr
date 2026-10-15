<?php

namespace Modules\Tenancy\Services\Billing;

use App\Services\WhiteLabel\PlatformSettings;

/**
 * Billing settings of the install (Settings → Plans & Billing, docs/billing.md): plans on/off, the
 * billing webhook (URL + signing secret), the usage thresholds that raise billing.usage.threshold
 * and the store URL tenants are sent to for buying plans and credits. Stored value (settings /
 * secure_settings) ← install default (config tenancy.billing.* / tenancy.plans.*, Helm values).
 *
 * The webhook secret is stored encrypted and never returned — the admin payload reports
 * webhook_secret_set only.
 */
class BillingSettings
{
    public const SECRET_WEBHOOK = 'billing_webhook_secret';

    /** At most this many usage thresholds */
    public const MAX_THRESHOLDS = 10;

    public function __construct(private readonly PlatformSettings $settings) {}

    // ── Plans ───────────────────────────────────────────────────────────────

    public function plansEnabled(): bool
    {
        if (! config('tenancy.enabled')) {
            return false;
        }

        return $this->settings->bool('plans_enabled', PlatformSettings::configBool(config('tenancy.plans.enabled'), false));
    }

    // ── Billing webhook ─────────────────────────────────────────────────────

    /** Where billing events are POSTed ('' = events are only kept for GET /api/billing/events). */
    public function webhookUrl(): string
    {
        $stored = $this->settings->raw('billing_webhook_url');

        return trim($stored !== null ? $stored : (string) config('tenancy.billing.webhook.url', ''));
    }

    public function webhookSecret(): string
    {
        return (string) $this->settings->secret(self::SECRET_WEBHOOK, (string) config('tenancy.billing.webhook.secret', ''));
    }

    // ── Usage thresholds ────────────────────────────────────────────────────

    /**
     * Month-to-date usage amounts (in the usage's currency) that raise billing.usage.threshold
     * once per tenant, month and currency — ascending.
     *
     * @return list<float>
     */
    public function usageThresholds(): array
    {
        $stored = $this->settings->raw('billing_usage_thresholds');

        return self::parseThresholds($stored !== null ? $stored : (string) config('tenancy.billing.events.usage_thresholds', ''));
    }

    /** @return list<float> */
    public static function parseThresholds(string|array|null $value): array
    {
        $parts = is_array($value) ? $value : PlatformSettings::splitList((string) $value);
        $amounts = [];
        foreach ($parts as $part) {
            if (is_numeric($part) && (float) $part > 0) {
                $amounts[] = round((float) $part, 2);
            }
        }
        $amounts = array_values(array_unique($amounts, SORT_REGULAR));
        sort($amounts);

        return array_slice($amounts, 0, self::MAX_THRESHOLDS);
    }

    // ── Store ───────────────────────────────────────────────────────────────

    /** The operator's store where tenants buy plans and credits ('' = none). */
    public function storeUrl(): string
    {
        $stored = $this->settings->raw('billing_store_url');

        return trim($stored !== null ? $stored : (string) config('tenancy.billing.store_url', ''));
    }

    // ── Admin endpoints ─────────────────────────────────────────────────────

    public function adminPayload(): array
    {
        return [
            'plans_enabled' => $this->plansEnabled(),
            'webhook' => [
                'url' => $this->webhookUrl() ?: null,
                'url_source' => $this->settings->raw('billing_webhook_url') !== null ? 'settings' : 'config',
                'secret_set' => $this->webhookSecret() !== '',
            ],
            'usage_thresholds' => $this->usageThresholds(),
            'store_url' => $this->storeUrl() ?: null,
            'default_currency' => strtoupper((string) config('tenancy.billing.default_currency', 'USD')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'plans_enabled' => ['sometimes', 'boolean'],
            'webhook_url' => ['sometimes', 'nullable', 'string', 'max:1000', 'url:http,https'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'min:16', 'max:255'],
            'usage_thresholds' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_THRESHOLDS],
            'usage_thresholds.*' => ['numeric', 'gt:0', 'max:1000000000'],
            'store_url' => ['sometimes', 'nullable', 'string', 'max:1000', 'url:https,http'],
            // Empty strings arrive as null (ConvertEmptyStringsToNull): clearing the secret is explicit
            'clear_secrets' => ['sometimes', 'array'],
            'clear_secrets.*' => ['string', 'in:webhook_secret'],
        ];
    }

    public function update(array $data): void
    {
        if (array_key_exists('plans_enabled', $data)) {
            $this->settings->set('plans_enabled', (bool) $data['plans_enabled']);
        }
        // null → back to the install default (Helm value)
        if (array_key_exists('webhook_url', $data)) {
            $this->settings->set('billing_webhook_url', $data['webhook_url']);
        }
        if (array_key_exists('usage_thresholds', $data)) {
            $this->settings->set('billing_usage_thresholds', $data['usage_thresholds'] === null
                ? null
                : implode(',', self::parseThresholds((array) $data['usage_thresholds'])));
        }
        if (array_key_exists('store_url', $data)) {
            $this->settings->set('billing_store_url', $data['store_url']);
        }

        // Write-only secret: omitted / null → unchanged; listed in clear_secrets → removed (the Helm
        // value applies again)
        if (in_array('webhook_secret', (array) ($data['clear_secrets'] ?? []), true)) {
            $this->settings->setSecret(self::SECRET_WEBHOOK, '');
        } elseif (isset($data['webhook_secret']) && $data['webhook_secret'] !== '') {
            $this->settings->setSecret(self::SECRET_WEBHOOK, (string) $data['webhook_secret']);
        }

        $this->settings->flush();
    }
}
