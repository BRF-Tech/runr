<?php

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Log;

/**
 * The alarm of the local fallback (shared contract: captcha-turnstile-plan §3.3, captcha-local-spec
 * §6): more than one local challenge handed out within 10 minutes means the CAPTCHA provider, or
 * the API's way out to it, is failing. The platform administrators then get an in-app notification
 * (CaptchaNotifier: type "infrastructure", level warning; their notification preferences apply)
 * and the log a warning — at most once per 10 minutes (dedup), counted in the shared cache like
 * captcha_fallback_total, so every API pod adds to the same window.
 *
 * The window opens with the first challenge after a quiet period and lasts 10 minutes. A challenge
 * handed out for a wrong configuration does not count here: CaptchaConfigAlert tells who can fix it.
 */
class CaptchaFallbackAlert
{
    public const WINDOW_SECONDS = 600;

    /** More than this many challenges in a window raise the alarm */
    public const THRESHOLD = 1;

    /** Notification type (NotificationPreferenceController): the provider or the way out to it fails */
    public const NOTIFICATION = CaptchaNotifier::TYPE;

    private const WINDOW_KEY = 'captcha:fallback:window';

    private const SENT_KEY = 'captcha:fallback:alerted';

    public function __construct(
        private readonly CaptchaMetrics $metrics,
        private readonly CaptchaNotifier $notifier,
    ) {}

    /** A local challenge was handed out for $form while $provider could not be reached. */
    public function record(string $form, string $provider): void
    {
        try {
            $cache = $this->metrics->cache();
            $cache->add(self::WINDOW_KEY, 0, self::WINDOW_SECONDS);
            $count = (int) $cache->increment(self::WINDOW_KEY);
            if ($count <= self::THRESHOLD || ! $cache->add(self::SENT_KEY, 1, self::WINDOW_SECONDS)) {
                return;
            }
        } catch (\Throwable) {
            // the alarm must never fail the form
            return;
        }

        Log::warning('Bot protection: the CAPTCHA provider cannot be reached — the local fallback answered '.$count.' times in 10 minutes', [
            'form' => $form,
            'provider' => $provider,
            'count' => $count,
        ]);

        // One in-app notification per platform administrator who has not turned them off
        $this->notifier->notify(null, 'warning', (string) __('captcha.alert.title'), (string) __('captcha.alert.body', [
            'count' => $count,
            'provider' => __("captcha.providers.{$provider}"),
        ]), [
            'captcha' => 'fallback',
            'provider' => $provider,
            'count' => $count,
            'path' => '/settings?tab=bot-protection',
        ]);
    }
}
