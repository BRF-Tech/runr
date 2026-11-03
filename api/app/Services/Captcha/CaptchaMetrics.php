<?php

namespace App\Services\Captcha;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Usage of the local fallback (docs/security.md#bot-protection): `captcha_fallback_total`, the
 * proof-of-work challenges handed out while the CAPTCHA provider could not be reached, by form and
 * provider. Counted in the shared cache (every API pod adds to the same counter; a cache flush
 * resets it, which Prometheus counters allow). Exposed in the Prometheus text format by
 * GET /api/admin/captcha/metrics, like the sandbox metrics; the alarm (more than 1 in 10 minutes)
 * is CaptchaFallbackAlert's.
 */
class CaptchaMetrics
{
    public const NAME = 'captcha_fallback_total';

    private const PREFIX = 'captcha:metrics:fallback:';

    /** Kept for years: a counter, not a window */
    private const TTL = 315360000;

    public function increment(string $form, string $provider): void
    {
        $key = self::PREFIX.$this->label($form).':'.$this->label($provider);

        try {
            $cache = $this->cache();
            $cache->add($key, 0, self::TTL);
            $cache->increment($key);
            $cache->put(self::PREFIX.'last', now()->getTimestamp(), self::TTL);
        } catch (\Throwable) {
            // a counter must never fail the form
        }
    }

    /** @return list<array{form: string, provider: string, count: int}> */
    public function counts(): array
    {
        $rows = [];
        $cache = $this->cache();

        foreach (CaptchaSettings::FORMS as $form) {
            foreach (array_diff(CaptchaSettings::PROVIDERS, ['none']) as $provider) {
                try {
                    $count = (int) $cache->get(self::PREFIX.$form.':'.$provider, 0);
                } catch (\Throwable) {
                    $count = 0;
                }
                if ($count > 0) {
                    $rows[] = ['form' => $form, 'provider' => $provider, 'count' => $count];
                }
            }
        }

        return $rows;
    }

    public function total(): int
    {
        return array_sum(array_column($this->counts(), 'count'));
    }

    public function lastAt(): ?int
    {
        try {
            $at = $this->cache()->get(self::PREFIX.'last');
        } catch (\Throwable) {
            return null;
        }

        return is_numeric($at) ? (int) $at : null;
    }

    public function prometheus(bool $fallbackActive): string
    {
        $lines = [
            '# HELP '.self::NAME.' Local proof-of-work challenges handed out while the CAPTCHA provider could not be reached.',
            '# TYPE '.self::NAME.' counter',
        ];
        foreach ($this->counts() as $row) {
            $lines[] = sprintf('%s{form="%s",provider="%s"} %d', self::NAME, $row['form'], $row['provider'], $row['count']);
        }

        $lines[] = '# HELP captcha_fallback_active Whether local answers are accepted right now (the provider failed in the last 120 s).';
        $lines[] = '# TYPE captcha_fallback_active gauge';
        $lines[] = 'captcha_fallback_active '.($fallbackActive ? 1 : 0);

        return implode("\n", $lines)."\n";
    }

    public function cache(): Repository
    {
        $store = config('captcha.cache_store');

        return is_string($store) && $store !== '' ? Cache::store($store) : Cache::store();
    }

    private function label(string $value): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower($value)) ?: 'unknown';
    }
}
