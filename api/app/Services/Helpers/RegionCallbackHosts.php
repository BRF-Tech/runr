<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Host names of the addresses RunR hands to the regions as its own, which the regions call back
 * on (/api/internal/…: a region agent's uptime checks and results, the replay captures of
 * function pods, the workflow queue bridge). App\Http\Middleware\TrustHosts accepts them there.
 *
 *  - the in-cluster gateway the agent of RunR's own cluster connects to
 *    (AgentWsUrlResolver::inClusterBase(): RUNR_AGENT_GATEWAY_SERVICE / _PORT,
 *    RUNR_CLUSTER_DOMAIN and RunR's namespace), when RunR runs in a pod or RUNR_NAMESPACE is set
 *  - RUNR_AGENT_WS_URL (AgentWsUrlResolver), OBS_INGEST_URL (IngestUrl) and
 *    WORKFLOWS_PUBLIC_API_URL (QueueBridge)
 *  - the regions' own `agent_ws_url` and `app_url` settings, read at most once a minute
 *
 * The first two come from the configuration and the pod alone, never from the database or the
 * cache. APP_URL's host is trusted on every route already.
 */
class RegionCallbackHosts
{
    private const CACHE_KEY = 'runr:region-callback-hosts';

    private const CACHE_TTL = 60;

    public function __construct(
        private ?AgentWsUrlResolver $resolver = null,
        private ?RunrClusterIdentity $identity = null,
    ) {
        $this->identity ??= new RunrClusterIdentity;
        $this->resolver ??= new AgentWsUrlResolver($this->identity);
    }

    /**
     * Lower-case host names, without port.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $urls = [
            config('knative.agent.ws_url'),
            config('observability.replay.ingest_url'),
            config('workflows.public_api_url'),
        ];

        $namespace = $this->identity->namespace();
        if ($namespace) {
            $urls[] = $this->resolver->inClusterBase($namespace);
        }

        return self::hostsOf([...$urls, ...$this->regionSettingUrls()]);
    }

    /**
     * The hosts as TrustHosts patterns (the exact host name).
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return array_map(fn (string $host): string => '^'.preg_quote($host).'$', $this->hosts());
    }

    /**
     * `agent_ws_url` and `app_url` of every region; none when the database cannot be read. Only a
     * list that names hosts is cached: after an empty or failed read the next callback reads again.
     *
     * @return list<string>
     */
    private function regionSettingUrls(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && $cached !== []) {
                return array_values(array_filter($cached, 'is_string'));
            }
        } catch (Throwable) {
            // no cache: read the database
        }

        try {
            $urls = [];
            foreach (KnativeRegion::query()->whereNotNull('knative_settings')->pluck('knative_settings') as $settings) {
                if (is_string($settings)) {
                    $settings = json_decode($settings, true);
                }
                if (is_array($settings)) {
                    $urls[] = $settings['agent_ws_url'] ?? null;
                    $urls[] = $settings['app_url'] ?? null;
                }
            }
            $urls = array_values(array_unique(array_filter($urls, fn ($url): bool => is_string($url) && $url !== '')));
        } catch (Throwable) {
            return [];
        }

        if ($urls !== []) {
            try {
                Cache::put(self::CACHE_KEY, $urls, self::CACHE_TTL);
            } catch (Throwable) {
                // not cached: the next callback reads the database again
            }
        }

        return $urls;
    }

    /**
     * @param  array<int, mixed>  $urls
     * @return list<string>
     */
    private static function hostsOf(array $urls): array
    {
        $hosts = [];
        foreach ($urls as $url) {
            $host = is_string($url) ? parse_url(trim($url), PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }
}
