<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;

/**
 * WebSocket URL the runr-agent of a region connects to.
 *
 * 1. region setting `agent_ws_url` (base URL of the RunR gateway)
 * 2. region is the cluster RunR runs in → in-cluster gateway Service
 *    (ws://runr-gateway.<runr-namespace>.svc.cluster.local), no public DNS / TLS needed
 * 3. config knative.agent.ws_url (RUNR_AGENT_WS_URL)
 * 4. region setting `app_url`, else APP_URL (http → ws, https → wss)
 *
 * `/ws/agent/<regionId>` is always appended (gateway strips /ws → Reverb /agent/{regionId}).
 */
class AgentWsUrlResolver
{
    public function __construct(
        private ?RunrClusterIdentity $identity = null,
    ) {
        $this->identity ??= new RunrClusterIdentity;
    }

    public function resolve(KnativeRegion $region): string
    {
        $path = '/ws/agent/'.$region->id;
        $settings = $region->effective_settings ?? [];

        if (! empty($settings['agent_ws_url'])) {
            return self::toWsBase($settings['agent_ws_url']).$path;
        }

        if ($this->identity->isRunrCluster($region)) {
            $ns = $this->identity->namespace();
            if ($ns) {
                return $this->inClusterBase($ns).$path;
            }
        }

        $global = config('knative.agent.ws_url');
        if ($global) {
            return self::toWsBase($global).$path;
        }

        $appUrl = $settings['app_url'] ?? config('app.url', 'http://localhost:8060');

        return self::toWsBase($appUrl).$path;
    }

    public function inClusterBase(string $namespace): string
    {
        $service = config('knative.agent.gateway_service', 'runr-gateway');
        $port = (int) config('knative.agent.gateway_port', 80);
        $domain = config('knative.agent.cluster_domain', 'cluster.local');
        if ($port <= 0) {
            $port = 80; // not a port (e.g. a service-link style "tcp://ip:80" value)
        }

        return "ws://{$service}.{$namespace}.svc.{$domain}".($port === 80 ? '' : ":{$port}");
    }

    /**
     * http(s) → ws(s), trailing slash and an already appended /ws/agent/... removed.
     */
    public static function toWsBase(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $url = preg_replace('#/ws/agent(/.*)?$#', '', $url);

        return preg_replace(['#^http://#i', '#^https://#i'], ['ws://', 'wss://'], $url);
    }
}
