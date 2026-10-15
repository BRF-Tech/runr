<?php

namespace App\Services\WhiteLabel;

/**
 * Self-service signup settings (Settings → Signup). Stored value ← install default
 * (config/whitelabel.php signup.*, Helm values). `registration_enabled` is the existing setting
 * and stays on by default.
 */
class SignupSettings
{
    public const CAPTCHA_PROVIDERS = ['none', 'turnstile', 'hcaptcha'];

    public const SECRET_CAPTCHA = 'signup_captcha_secret';

    public function __construct(private readonly PlatformSettings $settings) {}

    // ── Typed reads ─────────────────────────────────────────────────────────

    public function registrationEnabled(): bool
    {
        return $this->settings->bool('registration_enabled', true);
    }

    public function emailVerification(): bool
    {
        return $this->settings->bool('signup_email_verification', $this->cfgBool('email_verification', false));
    }

    public function inviteOnly(): bool
    {
        return $this->settings->bool('signup_invite_only', $this->cfgBool('invite_only', false));
    }

    /** @return list<string> lower-case domains; "*.example.com" also allows sub-domains */
    public function allowedDomains(): array
    {
        $raw = $this->settings->raw('signup_allowed_domains');

        return array_map('strtolower', PlatformSettings::splitList($raw ?? (string) config('whitelabel.signup.allowed_domains', '')));
    }

    public function isDomainAllowed(string $email): bool
    {
        $allowed = $this->allowedDomains();
        if ($allowed === []) {
            return true;
        }

        $domain = strtolower(trim((string) substr(strrchr($email, '@') ?: '', 1)));
        if ($domain === '') {
            return false;
        }

        foreach ($allowed as $entry) {
            $entry = ltrim($entry, '@');
            if (str_starts_with($entry, '*.')) {
                $base = substr($entry, 2);
                if ($domain === $base || str_ends_with($domain, '.'.$base)) {
                    return true;
                }
            } elseif ($domain === $entry) {
                return true;
            }
        }

        return false;
    }

    public function captchaProvider(): string
    {
        $provider = strtolower($this->settings->string('signup_captcha_provider', null) ?? (string) config('whitelabel.signup.captcha_provider', 'none'));

        return in_array($provider, self::CAPTCHA_PROVIDERS, true) ? $provider : 'none';
    }

    public function captchaSiteKey(): ?string
    {
        $key = $this->settings->string('signup_captcha_site_key', null) ?? (string) config('whitelabel.signup.captcha_site_key', '');

        return $key === '' ? null : $key;
    }

    public function captchaSecret(): ?string
    {
        return $this->settings->secret(self::SECRET_CAPTCHA, (string) config('whitelabel.signup.captcha_secret', ''));
    }

    public function captchaEnabled(): bool
    {
        return $this->captchaProvider() !== 'none';
    }

    public function termsUrl(): ?string
    {
        return $this->url('signup_terms_url', 'terms_url');
    }

    public function privacyUrl(): ?string
    {
        return $this->url('signup_privacy_url', 'privacy_url');
    }

    public function requiresTermsAcceptance(): bool
    {
        return $this->termsUrl() !== null || $this->privacyUrl() !== null;
    }

    public function tenantAutoCreate(): bool
    {
        return $this->settings->bool('signup_tenant_auto_create', $this->cfgBool('tenant_auto_create', true));
    }

    /** @return array{cpu_quota_m: int, memory_quota_mb: int} */
    public function defaultQuota(): array
    {
        return [
            'cpu_quota_m' => max(1, $this->settings->int('signup_default_cpu_quota_m', (int) config('whitelabel.signup.default_cpu_quota_m', 2000))),
            'memory_quota_mb' => max(1, $this->settings->int('signup_default_memory_quota_mb', (int) config('whitelabel.signup.default_memory_quota_mb', 4096))),
        ];
    }

    /** @return list<string> platform region ids offered in onboarding; [] = every active platform region */
    public function selfServiceRegionIds(): array
    {
        $raw = $this->settings->raw('signup_regions');

        return PlatformSettings::splitList($raw ?? (string) config('whitelabel.signup.regions', ''));
    }

    public function verificationTtlHours(): int
    {
        return max(1, min(24 * 30, $this->settings->int('signup_verification_ttl_hours', (int) config('whitelabel.signup.verification_ttl_hours', 48))));
    }

    // ── Payloads ────────────────────────────────────────────────────────────

    /**
     * GET /api/auth/registration-status (public).
     */
    public function publicStatus(): array
    {
        return [
            'enabled' => $this->registrationEnabled(),
            'invite_only' => $this->inviteOnly(),
            'email_verification' => $this->emailVerification(),
            'allowed_domains' => $this->allowedDomains(),
            'captcha' => [
                'provider' => $this->captchaProvider(),
                'site_key' => $this->captchaEnabled() ? $this->captchaSiteKey() : null,
            ],
            'terms_url' => $this->termsUrl(),
            'privacy_url' => $this->privacyUrl(),
            'tenant_auto_create' => (bool) config('tenancy.enabled') && $this->tenantAutoCreate(),
        ];
    }

    /**
     * GET /api/admin/signup-settings — the secret is reported as set / not set only.
     */
    public function adminPayload(): array
    {
        $quota = $this->defaultQuota();

        return [
            'registration_enabled' => $this->registrationEnabled(),
            'email_verification' => $this->emailVerification(),
            'invite_only' => $this->inviteOnly(),
            'allowed_domains' => $this->allowedDomains(),
            'captcha_provider' => $this->captchaProvider(),
            'captcha_site_key' => $this->captchaSiteKey(),
            'captcha_secret_set' => $this->captchaSecret() !== null,
            'terms_url' => $this->termsUrl(),
            'privacy_url' => $this->privacyUrl(),
            'tenant_auto_create' => $this->tenantAutoCreate(),
            'default_cpu_quota_m' => $quota['cpu_quota_m'],
            'default_memory_quota_mb' => $quota['memory_quota_mb'],
            'self_service_region_ids' => $this->selfServiceRegionIds(),
            'verification_ttl_hours' => $this->verificationTtlHours(),
            'multi_tenant' => (bool) config('tenancy.enabled'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'registration_enabled' => ['sometimes', 'boolean'],
            'email_verification' => ['sometimes', 'boolean'],
            'invite_only' => ['sometimes', 'boolean'],
            'allowed_domains' => ['sometimes', 'nullable', 'array', 'max:200'],
            'allowed_domains.*' => ['string', 'max:190', 'regex:/^@?(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}$/i'],
            'captcha_provider' => ['sometimes', 'string', 'in:'.implode(',', self::CAPTCHA_PROVIDERS)],
            'captcha_site_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'captcha_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Empty strings arrive as null (ConvertEmptyStringsToNull): clearing the secret is explicit
            'captcha_secret_clear' => ['sometimes', 'boolean'],
            'terms_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'privacy_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'tenant_auto_create' => ['sometimes', 'boolean'],
            'default_cpu_quota_m' => ['sometimes', 'integer', 'min:1', 'max:10000000'],
            'default_memory_quota_mb' => ['sometimes', 'integer', 'min:1', 'max:100000000'],
            'self_service_region_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'self_service_region_ids.*' => ['string', 'max:64'],
            'verification_ttl_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
        ];
    }

    public function update(array $data): void
    {
        $map = [
            'registration_enabled' => 'registration_enabled',
            'email_verification' => 'signup_email_verification',
            'invite_only' => 'signup_invite_only',
            'captcha_provider' => 'signup_captcha_provider',
            'captcha_site_key' => 'signup_captcha_site_key',
            'terms_url' => 'signup_terms_url',
            'privacy_url' => 'signup_privacy_url',
            'tenant_auto_create' => 'signup_tenant_auto_create',
            'default_cpu_quota_m' => 'signup_default_cpu_quota_m',
            'default_memory_quota_mb' => 'signup_default_memory_quota_mb',
            'verification_ttl_hours' => 'signup_verification_ttl_hours',
        ];

        foreach ($map as $field => $key) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            // Empty strings are stored as "" so that they override a non-empty install default
            $this->settings->set($key, is_bool($value) ? $value : ($value === null ? '' : $value));
        }

        if (array_key_exists('allowed_domains', $data)) {
            $this->settings->set('signup_allowed_domains', implode(',', array_map(
                fn ($d) => strtolower(ltrim(trim((string) $d), '@')),
                (array) ($data['allowed_domains'] ?? []),
            )));
        }

        if (array_key_exists('self_service_region_ids', $data)) {
            $this->settings->set('signup_regions', implode(',', (array) ($data['self_service_region_ids'] ?? [])));
        }

        // Write-only: null / omitted → unchanged; captcha_secret_clear → removed (the Helm value applies again)
        if (! empty($data['captcha_secret_clear'])) {
            $this->settings->setSecret(self::SECRET_CAPTCHA, '');
        } elseif (isset($data['captcha_secret']) && $data['captcha_secret'] !== '') {
            $this->settings->setSecret(self::SECRET_CAPTCHA, (string) $data['captcha_secret']);
        }
    }

    private function url(string $key, string $configKey): ?string
    {
        $value = $this->settings->string($key, null) ?? (string) config("whitelabel.signup.{$configKey}", '');
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function cfgBool(string $key, bool $default): bool
    {
        return PlatformSettings::configBool(config("whitelabel.signup.{$key}"), $default);
    }
}
