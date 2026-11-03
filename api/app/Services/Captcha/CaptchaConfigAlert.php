<?php

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Log;

/**
 * The alarm of a wrong bot protection configuration (shared contract captcha-turnstile-plan §3.5:
 * a configuration error counts as an outage). No site key, no secret, a stored secret that cannot
 * be decrypted (CaptchaConfig::problem()), or a secret the provider refuses (siteverify
 * invalid-input-secret, missing-input-secret, sitekey-secret-mismatch …): the forms keep working on
 * the local fallback meanwhile — never fail-closed, the protection never switches itself off — and
 * the people who can fix it are told (CaptchaNotifier: the platform administrators for the
 * platform's protection, the organization's settings.manage holders for its own keys; level error),
 * at most once an hour per scope and reason, with an error in the log.
 *
 * The last problem seen is kept a day for the settings page (problem()); the provider accepting the
 * keys again (resolved()) or saving the settings (clear()) forgets it.
 */
class CaptchaConfigAlert
{
    /** One alarm per scope and reason within this many seconds */
    public const DEDUP_SECONDS = 3600;

    /** How long the settings page shows the last problem seen */
    public const MARK_SECONDS = 86400;

    public const REASONS = [
        CaptchaConfig::SITE_KEY_MISSING,
        CaptchaConfig::SECRET_MISSING,
        CaptchaConfig::SECRET_UNREADABLE,
        CaptchaConfig::SECRET_REJECTED,
    ];

    private const MARK_PREFIX = 'captcha:misconfigured:';

    private const SENT_PREFIX = 'captcha:misconfigured:alerted:';

    public function __construct(
        private readonly CaptchaMetrics $metrics,
        private readonly CaptchaNotifier $notifier,
    ) {}

    /** A form met the wrong configuration $reason (one of REASONS). */
    public function record(CaptchaConfig $config, string $reason): void
    {
        $scope = $config->scope();

        try {
            $cache = $this->metrics->cache();
            $cache->put(self::MARK_PREFIX.$scope, ['reason' => $reason, 'provider' => $config->provider, 'at' => now()->getTimestamp()], self::MARK_SECONDS);
            if (! $cache->add(self::SENT_PREFIX.$scope.':'.$reason, 1, self::DEDUP_SECONDS)) {
                return;
            }
        } catch (\Throwable) {
            // the alarm must never fail the form
            return;
        }

        $provider = __("captcha.providers.{$config->provider}");
        Log::error('Bot protection: the CAPTCHA configuration is wrong ('.$reason.') — the local fallback answers until it is fixed in Settings → Bot protection', [
            'provider' => $config->provider,
            'source' => $config->source,
            'tenant' => $config->tenantId,
            'reason' => $reason,
        ]);

        $this->notifier->notify($config->tenantId, 'error', (string) __('captcha.misconfigured.title'), (string) __('captcha.misconfigured.body', [
            'provider' => $provider,
            'problem' => __("captcha.misconfigured.reasons.{$reason}", ['provider' => $provider]),
        ]), [
            'captcha' => 'misconfigured',
            'reason' => $reason,
            'provider' => $config->provider,
            'path' => '/settings?tab=bot-protection',
        ]);
    }

    /** A token passed with the keys of $config: an earlier "secret rejected" is over. */
    public function resolved(CaptchaConfig $config): void
    {
        try {
            $this->metrics->cache()->forget(self::MARK_PREFIX.$config->scope());
        } catch (\Throwable) {
            // the next problem() reads an old mark at worst
        }
    }

    /**
     * What the settings page shows: the configuration error of $config — the one it has now, else
     * the provider's refusal of its secret within the last day. `at`: when a form last met it.
     *
     * @return array{reason: string, at: ?string}|null
     */
    public function problem(CaptchaConfig $config): ?array
    {
        if (! $config->enabled()) {
            return null;
        }

        try {
            $mark = $this->metrics->cache()->get(self::MARK_PREFIX.$config->scope());
        } catch (\Throwable) {
            $mark = null;
        }
        $mark = is_array($mark) && is_string($mark['reason'] ?? null) ? $mark : null;
        $at = fn () => isset($mark['at']) && is_numeric($mark['at']) ? date(DATE_ATOM, (int) $mark['at']) : null;

        if (($reason = $config->problem()) !== null) {
            return ['reason' => $reason, 'at' => $mark !== null && $mark['reason'] === $reason ? $at() : null];
        }

        return $mark !== null && $mark['reason'] === CaptchaConfig::SECRET_REJECTED
            ? ['reason' => CaptchaConfig::SECRET_REJECTED, 'at' => $at()]
            : null;
    }

    /** The settings of $scope ("platform" | "tenant:<id>") changed: a new mistake alarms at once. */
    public function clear(string $scope): void
    {
        try {
            $cache = $this->metrics->cache();
            $cache->forget(self::MARK_PREFIX.$scope);
            foreach (self::REASONS as $reason) {
                $cache->forget(self::SENT_PREFIX.$scope.':'.$reason);
            }
        } catch (\Throwable) {
            // nothing to forget
        }
    }
}
