<?php

namespace App\Services\Captcha;

/**
 * The bot protection that applies to one request: its provider and keys, the forms it guards,
 * the fallback and the siteverify timeout. `source` tells where the provider came from:
 * tenant (the organization's own keys) | platform (Settings) | install (env / Helm values) | off.
 * `tenantId` is set for an organization's own keys only: its fallback window and its alarm are its
 * own (CaptchaGate, CaptchaConfigAlert).
 */
final class CaptchaConfig
{
    /** Configuration errors that keep the provider from being asked (problem()) */
    public const SITE_KEY_MISSING = 'site_key_missing';

    public const SECRET_MISSING = 'secret_missing';

    public const SECRET_UNREADABLE = 'secret_unreadable';

    /** The provider refused the secret (siteverify invalid-input-secret, …): learned per call */
    public const SECRET_REJECTED = 'secret_rejected';

    /**
     * @param  list<string>  $forms
     */
    public function __construct(
        public readonly string $provider,
        public readonly ?string $siteKey,
        public readonly ?string $secret,
        public readonly array $forms,
        public readonly string $fallback,
        public readonly int $verifyTimeoutMs,
        public readonly string $source,
        public readonly ?string $tenantId = null,
        // a stored secret that cannot be decrypted (the application key changed)
        public readonly bool $secretUnreadable = false,
    ) {}

    public function enabled(): bool
    {
        return $this->provider !== 'none';
    }

    /** The form needs a CAPTCHA answer. */
    public function protects(string $form): bool
    {
        return $this->enabled() && in_array($form, $this->forms, true);
    }

    /**
     * Why the provider cannot be asked (shared contract: a configuration error counts as an outage
     * — the local fallback answers and the administrators are told): no site key, no secret, a
     * stored secret that cannot be decrypted. Null when siteverify can be asked, or the protection
     * is off.
     */
    public function problem(): ?string
    {
        return match (true) {
            ! $this->enabled() => null,
            $this->siteKey === null => self::SITE_KEY_MISSING,
            $this->secret === null => $this->secretUnreadable ? self::SECRET_UNREADABLE : self::SECRET_MISSING,
            default => null,
        };
    }

    /** Provider, site key and secret are all there: siteverify can be asked. */
    public function usable(): bool
    {
        return $this->enabled() && $this->problem() === null;
    }

    public function localFallback(): bool
    {
        return $this->fallback === 'local';
    }

    /** Cache scope of the fallback window and the alarm: "platform", or "tenant:<id>" for an organization's own keys. */
    public function scope(): string
    {
        return $this->tenantId !== null ? 'tenant:'.$this->tenantId : 'platform';
    }
}
