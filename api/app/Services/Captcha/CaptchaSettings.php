<?php

namespace App\Services\Captcha;

use App\Models\CaptchaSetting;
use App\Services\WhiteLabel\PlatformSettings;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bot protection settings (docs/security.md#bot-protection), resolved per request:
 *
 *   the organization's own provider and keys (multi-tenant, when it set some)
 *     ← the platform's stored settings (Settings → Access & security → Bot protection), per field
 *     ← the install default (Helm values captcha.* → CAPTCHA_* env → config/captcha.php)
 *     ← off
 *
 * An organization sets a provider with its own site key and secret (for its own host names); it
 * cannot switch the protection off, nor pick the forms: the platform's forms, fallback and timeout
 * apply to it. The secrets are write-only: stored encrypted, reported as set / not set only.
 *
 * The CAPTCHA of the sign-up form (SIGNUP_CAPTCHA_*, Helm values signup.captcha.*) is the legacy
 * install default: it applies while CAPTCHA_PROVIDER is "none", on the sign-up form only.
 */
class CaptchaSettings
{
    public const PROVIDERS = ['none', 'turnstile', 'recaptcha', 'hcaptcha'];

    /** What an organization may pick: a provider of its own, never "off" */
    public const TENANT_PROVIDERS = ['turnstile', 'recaptcha', 'hcaptcha'];

    /** The anonymous forms (shared model). RunR has no password reset form: the key is accepted, nothing uses it. */
    public const FORMS = ['login', 'register', 'password_reset', 'demo_login', 'public_forms'];

    public const FALLBACKS = ['local', 'off'];

    public const DEFAULT_TIMEOUT_MS = 4000;

    /** @var array<string, CaptchaConfig> per-instance memo (one request) */
    private array $memo = [];

    // ── Resolution ──────────────────────────────────────────────────────────

    /**
     * The install default (env / Helm values), with the legacy sign-up CAPTCHA.
     *
     * @return array{provider: string, site_key: ?string, secret: ?string, forms: list<string>, fallback: string, verify_timeout_ms: int, legacy: bool}
     */
    public function installDefaults(): array
    {
        $provider = self::provider(config('captcha.provider'));
        $legacy = false;
        if ($provider === 'none') {
            $old = self::provider(config('whitelabel.signup.captcha_provider'));
            if ($old !== 'none') {
                $provider = $old;
                $legacy = true;
            }
        }

        $siteKey = self::text(config('captcha.site_key')) ?? ($legacy ? self::text(config('whitelabel.signup.captcha_site_key')) : null);
        $secret = self::text(config('captcha.secret')) ?? ($legacy ? self::text(config('whitelabel.signup.captcha_secret')) : null);

        $forms = self::forms(PlatformSettings::splitList((string) config('captcha.forms', '')));

        return [
            'provider' => $provider,
            'site_key' => $siteKey,
            'secret' => $secret,
            'forms' => $forms ?? ($legacy ? ['register'] : self::FORMS),
            'fallback' => self::fallback(config('captcha.fallback')) ?? 'local',
            'verify_timeout_ms' => self::timeout(config('captcha.verify_timeout_ms')),
            'legacy' => $legacy,
        ];
    }

    /** The platform's protection: stored values over the install default, per field. */
    public function platform(): CaptchaConfig
    {
        if (isset($this->memo['platform'])) {
            return $this->memo['platform'];
        }

        $install = $this->installDefaults();
        $row = CaptchaSetting::platform();

        $provider = self::provider($row->provider ?? $install['provider']);
        $source = $row?->provider !== null ? 'platform' : ($install['provider'] !== 'none' ? 'install' : 'off');
        // A stored secret that cannot be decrypted is a configuration error: never silently the
        // install's secret instead (it may not belong to the stored site key)
        $unreadable = $row?->secretUnreadable() ?? false;

        return $this->memo['platform'] = new CaptchaConfig(
            provider: $provider,
            siteKey: $row !== null && $row->site_key !== null ? self::text($row->site_key) : $install['site_key'],
            secret: $unreadable ? null : ($row?->secretValue() ?? $install['secret']),
            // A stored list wins even when it is empty (no form guarded)
            forms: $row?->forms !== null ? (self::forms($row->forms) ?? []) : $install['forms'],
            fallback: self::fallback($row?->fallback) ?? $install['fallback'],
            verifyTimeoutMs: $row?->verify_timeout_ms !== null ? self::timeout($row->verify_timeout_ms) : $install['verify_timeout_ms'],
            source: $provider === 'none' ? 'off' : $source,
            secretUnreadable: $unreadable,
        );
    }

    /** What the requests of $tenantId get: the organization's own provider, else the platform's. */
    public function forTenant(?string $tenantId): CaptchaConfig
    {
        $platform = $this->platform();
        if ($tenantId === null || $tenantId === '' || ! config('tenancy.enabled')) {
            return $platform;
        }

        $key = 'tenant:'.$tenantId;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $row = CaptchaSetting::forTenant($tenantId);
        if (! $row || ! in_array($row->provider, self::TENANT_PROVIDERS, true)) {
            return $this->memo[$key] = $platform;
        }

        return $this->memo[$key] = new CaptchaConfig(
            provider: $row->provider,
            siteKey: self::text($row->site_key),
            secret: $row->secretValue(),
            forms: $platform->forms,
            fallback: $platform->fallback,
            verifyTimeoutMs: $platform->verifyTimeoutMs,
            source: 'tenant',
            tenantId: $tenantId,
            secretUnreadable: $row->secretUnreadable(),
        );
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    // ── Payloads ────────────────────────────────────────────────────────────

    /**
     * GET /api/captcha/config (public): what the console needs to render the widget.
     *
     * @return array{provider: string, site_key: ?string, fallback: string, forms: list<string>, misconfigured: bool}
     */
    public function publicConfig(?string $tenantId): array
    {
        $config = $this->forTenant($tenantId);

        return [
            'provider' => $config->provider,
            'site_key' => $config->enabled() ? $config->siteKey : null,
            'fallback' => $config->fallback,
            'forms' => $config->enabled() ? $config->forms : [],
            // No site key, no secret, a secret that cannot be decrypted: the provider cannot be
            // asked, so the console skips the widget and asks for the local check at once
            'misconfigured' => $config->problem() !== null,
        ];
    }

    /**
     * GET /api/admin/captcha: the effective values, the stored ones and the install defaults.
     * Secrets appear as secret_set only.
     */
    public function adminPayload(): array
    {
        $config = $this->platform();
        $install = $this->installDefaults();
        $row = CaptchaSetting::platform();

        return [
            'provider' => $config->provider,
            'site_key' => $config->siteKey,
            'secret_set' => $config->secret !== null,
            'forms' => $config->forms,
            'fallback' => $config->fallback,
            'verify_timeout_ms' => $config->verifyTimeoutMs,
            'source' => $config->source,
            'stored' => $row ? [
                'provider' => $row->provider,
                'site_key' => $row->site_key,
                'secret_set' => $row->secretValue() !== null,
                'forms' => $row->forms,
                'fallback' => $row->fallback,
                'verify_timeout_ms' => $row->verify_timeout_ms,
            ] : null,
            'defaults' => [
                'provider' => $install['provider'],
                'site_key' => $install['site_key'],
                'secret_set' => $install['secret'] !== null,
                'forms' => $install['forms'],
                'fallback' => $install['fallback'],
                'verify_timeout_ms' => $install['verify_timeout_ms'],
                'legacy' => $install['legacy'],
            ],
            'providers' => self::PROVIDERS,
            'form_keys' => self::FORMS,
            'multi_tenant' => (bool) config('tenancy.enabled'),
            // A configuration error the forms meet (no site key, no secret, an unreadable or refused
            // secret): they run on the local fallback until it is fixed (CaptchaConfigAlert)
            'problem' => app(CaptchaConfigAlert::class)->problem($config),
        ];
    }

    /** GET /api/tenant/captcha: the organization's own provider (null = the platform's). */
    public function tenantPayload(string $tenantId): array
    {
        $row = CaptchaSetting::forTenant($tenantId);
        $own = $row && in_array($row->provider, self::TENANT_PROVIDERS, true);
        $effective = $this->forTenant($tenantId);
        $platform = $this->platform();

        return [
            'provider' => $own ? $row->provider : null,
            'site_key' => $own ? $row->site_key : null,
            'secret_set' => $own && $row->secretValue() !== null,
            'effective' => ['provider' => $effective->provider, 'source' => $effective->source, 'forms' => $effective->enabled() ? $effective->forms : []],
            'platform' => ['provider' => $platform->provider, 'forms' => $platform->enabled() ? $platform->forms : []],
            'providers' => self::TENANT_PROVIDERS,
            // The configuration error of the organization's own keys (see adminPayload)
            'problem' => $own ? app(CaptchaConfigAlert::class)->problem($effective) : null,
        ];
    }

    // ── Updates ─────────────────────────────────────────────────────────────

    /**
     * PUT /api/admin/captcha. A field sent as null goes back to the install default; omitted =
     * unchanged; `reset` removes every stored value. Secret: omitted / null = keep, a value =
     * replace (never read back), secret_clear = remove.
     *
     * @return array<string, mixed>
     */
    public static function platformRules(): array
    {
        return [
            'provider' => ['sometimes', 'nullable', 'string', Rule::in(self::PROVIDERS)],
            'site_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Empty strings arrive as null (ConvertEmptyStringsToNull): clearing the secret is explicit
            'secret_clear' => ['sometimes', 'boolean'],
            'forms' => ['sometimes', 'nullable', 'array', 'max:'.count(self::FORMS)],
            'forms.*' => ['string', 'distinct', Rule::in(self::FORMS)],
            'fallback' => ['sometimes', 'nullable', 'string', Rule::in(self::FALLBACKS)],
            'verify_timeout_ms' => ['sometimes', 'nullable', 'integer', 'min:500', 'max:30000'],
            'reset' => ['sometimes', 'boolean'],
        ];
    }

    /** PUT /api/tenant/captcha: provider null = the platform's protection again. */
    public static function tenantRules(): array
    {
        return [
            'provider' => ['sometimes', 'nullable', 'string', Rule::in(self::TENANT_PROVIDERS)],
            'site_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'secret_clear' => ['sometimes', 'boolean'],
        ];
    }

    public function updatePlatform(array $data): void
    {
        if (! empty($data['reset'])) {
            CaptchaSetting::platform()?->delete();
            $this->changed(null);

            return;
        }

        $row = CaptchaSetting::platform() ?? new CaptchaSetting(['tenant_id' => null]);

        foreach (['provider', 'site_key', 'fallback', 'verify_timeout_ms'] as $field) {
            if (array_key_exists($field, $data)) {
                $row->{$field} = $data[$field] === null ? null : (is_string($data[$field]) ? trim($data[$field]) : $data[$field]);
            }
        }
        if (array_key_exists('forms', $data)) {
            $row->forms = $data['forms'] === null ? null : (self::forms((array) $data['forms']) ?? []);
        }
        $this->applySecret($row, $data);

        // A provider picked here needs its site key and secret (stored or from the install)
        if ($row->provider !== null && $row->provider !== 'none') {
            $install = $this->installDefaults();
            $siteKey = $row->site_key !== null ? self::text($row->site_key) : $install['site_key'];
            $secret = $this->pendingSecret($row) ?? $install['secret'];
            $this->requireKeys($siteKey, $secret);
        }

        $this->persist($row);
    }

    public function updateTenant(string $tenantId, array $data): void
    {
        $row = CaptchaSetting::forTenant($tenantId) ?? new CaptchaSetting(['tenant_id' => $tenantId]);

        if (array_key_exists('provider', $data) && $data['provider'] === null) {
            // Back to the platform's protection: the organization's keys go too
            if ($row->exists) {
                $row->delete();
            }
            $this->changed($tenantId);

            return;
        }

        if (array_key_exists('provider', $data)) {
            $row->provider = $data['provider'];
        }
        if (array_key_exists('site_key', $data)) {
            $row->site_key = $data['site_key'] === null ? null : trim((string) $data['site_key']);
        }
        $this->applySecret($row, $data);

        if ($row->provider !== null) {
            $this->requireKeys(self::text($row->site_key), $this->pendingSecret($row));
        }

        $this->persist($row);
    }

    private function applySecret(CaptchaSetting $row, array $data): void
    {
        if (! empty($data['secret_clear'])) {
            $row->secret = null;
        } elseif (isset($data['secret']) && trim((string) $data['secret']) !== '') {
            $row->secret = trim((string) $data['secret']);
        }
    }

    /** The secret the row will hold after save. */
    private function pendingSecret(CaptchaSetting $row): ?string
    {
        return $row->secretValue();
    }

    private function requireKeys(?string $siteKey, ?string $secret): void
    {
        $errors = [];
        if ($siteKey === null) {
            $errors['site_key'] = __('captcha.settings.site_key_required');
        }
        if ($secret === null) {
            $errors['secret'] = __('captcha.settings.secret_required');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function persist(CaptchaSetting $row): void
    {
        if ($row->isEmpty()) {
            if ($row->exists) {
                $row->delete();
            }
        } else {
            $row->save();
        }

        $this->changed($row->tenant_id === null ? null : (string) $row->tenant_id);
    }

    /** The settings of the platform (null) or an organization changed: memo and alarm marks start over. */
    private function changed(?string $tenantId): void
    {
        $this->flush();
        app(CaptchaConfigAlert::class)->clear($tenantId === null ? 'platform' : 'tenant:'.$tenantId);
    }

    // ── Normalization ───────────────────────────────────────────────────────

    public static function provider(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, self::PROVIDERS, true) ? $value : 'none';
    }

    /**
     * Known forms in their canonical order; "all" / "*" = every form; null when nothing was named.
     *
     * @param  iterable<mixed>  $values
     * @return list<string>|null
     */
    public static function forms(iterable $values): ?array
    {
        $named = [];
        foreach ($values as $value) {
            $value = strtolower(trim((string) $value));
            if ($value === 'all' || $value === '*') {
                return self::FORMS;
            }
            $named[] = $value;
        }
        if ($named === []) {
            return null;
        }

        return array_values(array_filter(self::FORMS, fn (string $form) => in_array($form, $named, true)));
    }

    private static function fallback(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, self::FALLBACKS, true) ? $value : null;
    }

    private static function timeout(mixed $value): int
    {
        $ms = is_numeric($value) ? (int) $value : self::DEFAULT_TIMEOUT_MS;

        return $ms > 0 ? max(500, min(30000, $ms)) : self::DEFAULT_TIMEOUT_MS;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
