<?php

namespace App\Services\Observability;

use App\Models\KnativeRegion;
use App\Services\Helpers\AgentWsUrlResolver;

/**
 * RunR's base URL as seen from a region's pods (replay ingest): OBS_INGEST_URL when set, else the
 * base the region's runr-agent connects to (AgentWsUrlResolver: region agent_ws_url → in-cluster
 * gateway on RunR's own cluster → RUNR_AGENT_WS_URL → APP_URL), ws(s) → http(s).
 */
class IngestUrl
{
    public function __construct(private ?AgentWsUrlResolver $resolver = null)
    {
        $this->resolver ??= app(AgentWsUrlResolver::class);
    }

    public function forRegion(?KnativeRegion $region): ?string
    {
        $configured = trim((string) config('observability.replay.ingest_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        if (! $region) {
            return null;
        }

        try {
            return self::httpBase($this->resolver->resolve($region));
        } catch (\Throwable) {
            return null;
        }
    }

    /** ws://host/prefix/ws/agent/<id> → http://host/prefix */
    public static function httpBase(string $wsUrl): ?string
    {
        $url = preg_replace('#/ws/agent(/.*)?$#', '', rtrim(trim($wsUrl), '/'));
        $url = preg_replace(['#^ws://#i', '#^wss://#i'], ['http://', 'https://'], (string) $url);

        return preg_match('#^https?://[^/]+#i', (string) $url) ? rtrim((string) $url, '/') : null;
    }
}
