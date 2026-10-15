<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;

/**
 * A region's Prometheus or Loki as an in-cluster Service on the region's own cluster.
 *
 * RunR reads a region's monitoring stack through the region's Kubernetes API service proxy
 * (/api/v1/namespaces/{ns}/services/{scheme}:{svc}:{port}/proxy/{path}) — never by HTTP from
 * the central API to an arbitrary URL: prometheus_url / loki_url can be set by tenant owners,
 * and a free-form URL would let them make the API fetch any address it can reach. A direct
 * HTTP call is made only when the region is RunR's own cluster, and then only to the
 * canonical in-cluster name built from the parsed parts (see directUrl()).
 *
 * Input (operator-supplied, see App\Rules\InClusterServiceUrl):
 *   http(s)://<service>.<namespace>.svc[.cluster.local]:<port>[/path]
 * Stored values additionally accepted when reading: RunR's own "<service>.<namespace>[:port]"
 * form (e.g. http://prometheus-operated.observability:9090).
 */
final class MonitoringEndpoint
{
    public const MAX_LENGTH = 255;

    private const LABEL = '[a-z0-9](?:[-a-z0-9]{0,61}[a-z0-9])?';

    private const PATH = '(/[A-Za-z0-9._~\-/]*)?';

    public function __construct(
        public readonly string $scheme,
        public readonly string $service,
        public readonly string $namespace,
        public readonly int $port,
        public readonly string $path = '',
    ) {}

    /**
     * Operator input: strict form, explicit port, at most 255 characters.
     */
    public static function isValidInput(string $url): bool
    {
        if (strlen($url) > self::MAX_LENGTH) {
            return false;
        }

        $pattern = '#^(https?)://('.self::LABEL.')\.('.self::LABEL.')\.svc(?:\.cluster\.local)?:(\d{1,5})'.self::PATH.'$#D';
        if (! preg_match($pattern, $url, $m)) {
            return false;
        }

        $port = (int) $m[4];

        return $port >= 1 && $port <= 65535;
    }

    /**
     * Parse a stored URL (input form or RunR's own "<svc>.<ns>[:port]" form). Null when the value
     * is not an in-cluster Service URL (e.g. a legacy http://<node-ip>:30090).
     */
    public static function parse(?string $url): ?self
    {
        if ($url === null || $url === '' || strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        $pattern = '#^(https?)://('.self::LABEL.')\.('.self::LABEL.')(?:\.svc(?:\.cluster\.local)?)?(?::(\d{1,5}))?'.self::PATH.'$#D';
        if (! preg_match($pattern, trim($url), $m)) {
            return null;
        }

        $scheme = $m[1];
        $port = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return new self($scheme, $m[2], $m[3], $port, trim($m[5] ?? '', '/'));
    }

    /**
     * The region's Prometheus; RunR's own kube-prometheus-stack when no usable URL is stored.
     */
    public static function prometheus(KnativeRegion $region): self
    {
        return self::parse($region->prometheus_url) ?? new self('http', 'prometheus-operated', 'observability', 9090);
    }

    /**
     * The region's Loki; RunR's own loki-stack when no usable URL is stored.
     */
    public static function loki(KnativeRegion $region): self
    {
        return self::parse($region->loki_url) ?? new self('http', 'loki-stack', 'observability', 3100);
    }

    /**
     * Path below the service proxy root, e.g. "prometheus/api/v1/query" for a Prometheus served
     * under /prometheus.
     */
    public function proxyPath(string $apiPath): string
    {
        $apiPath = ltrim($apiPath, '/');

        return $this->path === '' ? $apiPath : $this->path.'/'.$apiPath;
    }

    /**
     * Canonical in-cluster URL (…svc, resolved through the pod's cluster DNS search path) —
     * used for a direct call from RunR's own cluster only.
     */
    public function directUrl(string $apiPath): string
    {
        return "{$this->scheme}://{$this->service}.{$this->namespace}.svc:{$this->port}/".$this->proxyPath($apiPath);
    }
}
