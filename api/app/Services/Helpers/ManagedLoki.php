<?php

namespace App\Services\Helpers;

use Symfony\Component\Yaml\Yaml;

/**
 * RunR's own Loki (Helm release loki-stack in 'observability', KnativeLokiTrait) — what log
 * retention (LogRetentionService) needs from it.
 *
 * loki-stack 2.10.x runs grafana/loki:2.6.1 (its loki subchart's image). Loki 2.6 serves the
 * delete API (POST /loki/api/v1/delete) only with compactor retention on, and applies requests
 * only when deletion is allowed:
 *  - compactor.retention_enabled: true, compactor.deletion_mode: filter-and-delete (lines are
 *    removed from the stored chunks), working directory and delete requests on the Loki volume
 *    (/data/loki/…, the chart's filesystem store);
 *  - limits_config.allow_deletes: true (the single "fake" tenant of auth_enabled: false);
 *  - limits_config.retention_period: with retention on, Loki 2.6 would otherwise delete
 *    everything older than its 744 h default — and it does not read 0s as "keep forever"
 *    (Loki < 2.8) — so an effectively unlimited period: only RunR's delete requests
 *    (retention_days per region) remove logs.
 * Delete requests are applied by the compactor after delete_request_cancel_period (24 h).
 */
final class ManagedLoki
{
    public const RELEASE = 'loki-stack';

    public const NAMESPACE = 'observability';

    public const CHART = 'grafana/loki-stack';

    public const REPO_NAME = 'grafana';

    public const REPO_URL = 'https://grafana.github.io/helm-charts';

    /** 100 years: nothing expires through Loki's own retention */
    public const RETENTION_PERIOD = '876000h';

    /**
     * Loki config (loki-stack values loki.config) merged over the chart's defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function deletionConfig(): array
    {
        return [
            'compactor' => [
                'working_directory' => '/data/loki/boltdb-shipper-compactor',
                'shared_store' => 'filesystem',
                'retention_enabled' => true,
                'deletion_mode' => 'filter-and-delete',
            ],
            'limits_config' => [
                'allow_deletes' => true,
                'retention_period' => self::RETENTION_PERIOD,
            ],
        ];
    }

    /**
     * The same keys as `helm --set-json` pairs for an upgrade that reuses the release values.
     *
     * @return array<string, string>
     */
    public static function helmSetJson(): array
    {
        $set = [];
        foreach (self::deletionConfig() as $section => $keys) {
            foreach ($keys as $key => $value) {
                $set["loki.config.{$section}.{$key}"] = json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        return $set;
    }

    /**
     * Keys of deletionConfig() a rendered Loki config (loki.yaml) does not have yet, as
     * "section.key" (empty = up to date).
     *
     * @return list<string>
     */
    public static function missing(array $config): array
    {
        $missing = [];
        foreach (self::deletionConfig() as $section => $keys) {
            foreach ($keys as $key => $value) {
                if (($config[$section][$key] ?? null) !== $value) {
                    $missing[] = "{$section}.{$key}";
                }
            }
        }

        return $missing;
    }

    /** loki.yaml of the release's config Secret (v1 Secret, data base64), null when unreadable. */
    public static function configFromSecret(?array $secret): ?array
    {
        $raw = $secret['data']['loki.yaml'] ?? null;
        if (! is_string($raw)) {
            return null;
        }

        try {
            $config = Yaml::parse((string) base64_decode($raw, true));
        } catch (\Throwable) {
            return null;
        }

        return is_array($config) ? $config : null;
    }
}
