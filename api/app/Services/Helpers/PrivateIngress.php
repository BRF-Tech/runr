<?php

namespace App\Services\Helpers;

use App\Services\Contracts\KnativeConstants;

/**
 * The private entry point of the region Traefik: where cluster-local Knative routes are served.
 *
 * Every Knative Service gets a placeholder Service of type ExternalName in its namespace, which
 * points at the KIngress status' private load balancer (the provider's privateservice). Its
 * cluster-local hosts (<ksvc>.<namespace>, <ksvc>.<namespace>.svc, <ksvc>.<namespace>.svc.cluster.local)
 * are routed on the provider's private entry points. Earlier releases served them
 * on web — the entry point the LoadBalancer Service publishes — so a client outside the cluster
 * reached any cluster-local service (queue-trigger sinks, model services) with a Host header.
 *
 * Now they are served on the entry point "internal" (container port 8081), which only the
 * ClusterIP Service traefik-internal publishes (the chart's service.additionalServices), and the
 * placeholders point there. Public routes stay on web and websecure only.
 *
 * Entry-point-level middlewares for in-cluster callers are the internal network's ForwardAuth and
 * the removal of its token header (ports.internal.http.middlewares, InternalNetworkManifests): set at
 * install, and by InternalNetworkStep once the authz runs.
 *
 * Existing regions move in two Traefik upgrades (PrivateIngressStep): first cluster-local routes on
 * web and internal with privateservice traefik-internal (STATE_TRANSITIONAL) while the KIngresses
 * are made to point at traefik-internal, then on internal only (STATE_PRIVATE).
 */
final class PrivateIngress
{
    public const ENTRYPOINT = 'internal';

    /** Container port of the internal entry point */
    public const PORT = 8081;

    /** Name of the chart's additional Service (service.additionalServices.<name>) */
    public const SERVICE_KEY = 'internal';

    /** <release>-<additional service> as the chart names it */
    public const SERVICE = KnativeConstants::TRAEFIK_RELEASE.'-'.self::SERVICE_KEY;

    /** Entry points of the public routes: the ports the LoadBalancer Service publishes */
    public const PUBLIC_ENTRYPOINTS = ['web', 'websecure'];

    /** Cluster-local routes on web (the LoadBalancer): what earlier releases installed */
    public const STATE_LEGACY = 'legacy';

    /** Cluster-local routes on web and internal, the KIngresses still moving to traefik-internal */
    public const STATE_TRANSITIONAL = 'transitional';

    /** Cluster-local routes on internal only */
    public const STATE_PRIVATE = 'private';

    /** Traefik's own arguments RunR sets next to the Knative provider's (traefikHelmValues) */
    private const BASE_ARGS = [
        '--ping=true', '--ping.entrypoint=traefik',
        '--serversTransport.insecureSkipVerify=false',
        '--entrypoints.websecure.http.tls.options=default',
    ];

    /**
     * Chart values of the internal port: not on the LoadBalancer Service, on the ClusterIP Service
     * traefik-internal as port 80 (the placeholders' ExternalName is reached on port 80). Its read /
     * write timeouts follow request_timeout like web's: queue-trigger sinks and model services are
     * called through it.
     */
    public static function portValues(int $requestTimeout): array
    {
        return [
            'port' => self::PORT,
            'expose' => ['default' => false, self::SERVICE_KEY => true],
            'exposedPort' => 80,
            'protocol' => 'TCP',
            'transport' => [
                'respondingTimeouts' => RequestTimeout::traefikTransport($requestTimeout),
                'lifeCycle' => ['requestAcceptGraceTimeout' => '10s', 'graceTimeOut' => '10s'],
            ],
        ];
    }

    /** service.additionalServices entry of traefik-internal */
    public static function serviceValues(): array
    {
        return ['type' => 'ClusterIP'];
    }

    /**
     * The chart's additionalArguments: Traefik's own plus the Knative provider's entry points and
     * private service.
     *
     * @return list<string>
     */
    public static function additionalArguments(string $namespace, string $state = self::STATE_PRIVATE): array
    {
        return array_merge(self::BASE_ARGS, self::knativeArgs($namespace, $state));
    }

    /** @return list<string> */
    public static function knativeArgs(string $namespace, string $state = self::STATE_PRIVATE): array
    {
        $private = match ($state) {
            self::STATE_LEGACY => ['web'],
            self::STATE_TRANSITIONAL => ['web', self::ENTRYPOINT],
            default => [self::ENTRYPOINT],
        };

        return array_merge(
            $state === self::STATE_LEGACY ? [] : ['--providers.knative.publicentrypoints='.implode(',', self::PUBLIC_ENTRYPOINTS)],
            [
                '--providers.knative.privateservice.name='.($state === self::STATE_LEGACY ? KnativeConstants::TRAEFIK_RELEASE : self::SERVICE),
                '--providers.knative.privateservice.namespace='.$namespace,
                '--providers.knative.privateentrypoints='.implode(',', $private),
            ],
        );
    }

    /**
     * `helm --set-json` pairs that bring a release reusing its values to $state: the internal port,
     * the traefik-internal Service and the arguments.
     *
     * @return array<string, string>
     */
    public static function helmSetJson(string $namespace, string $state, int $requestTimeout): array
    {
        return [
            'ports.'.self::ENTRYPOINT => json_encode(self::portValues($requestTimeout), JSON_UNESCAPED_SLASHES),
            'service.additionalServices.'.self::SERVICE_KEY => json_encode(self::serviceValues()),
            'additionalArguments' => json_encode(self::additionalArguments($namespace, $state), JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Where a Traefik container serves cluster-local routes, from its args: STATE_LEGACY (on web),
     * STATE_TRANSITIONAL, STATE_PRIVATE, or null when it has no private entry point of RunR's
     * (none at all: cluster-local routes are not served; another entry point: not RunR's setup).
     *
     * @param  list<string>  $args
     */
    public static function state(array $args): ?string
    {
        $private = null;
        $service = null;
        $internalPort = false;
        foreach ($args as $arg) {
            if (preg_match('/^--providers\.knative\.privateentrypoints=(.*)$/i', $arg, $m)) {
                $private = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
                sort($private);
            } elseif (preg_match('/^--providers\.knative\.privateservice\.name=(.*)$/i', $arg, $m)) {
                $service = trim($m[1]);
            } elseif (preg_match('/^--entry[pP]oints\.'.self::ENTRYPOINT.'\.address=/', $arg)) {
                $internalPort = true;
            }
        }

        if ($private === ['web']) {
            return self::STATE_LEGACY;
        }
        if (! $internalPort || $service !== self::SERVICE) {
            return null;
        }

        return match ($private) {
            [self::ENTRYPOINT, 'web'] => self::STATE_TRANSITIONAL,
            [self::ENTRYPOINT] => self::STATE_PRIVATE,
            default => null,
        };
    }

    /** In-cluster host name of a Service: what a KIngress status names as its load balancer */
    public static function serviceHostname(string $service, string $namespace): string
    {
        return "{$service}.{$namespace}.svc.cluster.local";
    }

    /**
     * The private load balancer a KIngress status names (status.privateLoadBalancer), or null
     * when the ingress controller has not written one.
     */
    public static function privateLoadBalancer(array $kingress): ?string
    {
        foreach ($kingress['status']['privateLoadBalancer']['ingress'] ?? [] as $lb) {
            $host = $lb['domainInternal'] ?? $lb['domain'] ?? null;
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        return null;
    }
}
