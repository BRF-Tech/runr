<?php

namespace App\Services\Manifests;

/**
 * NetworkPolicies that keep the Knative data path the only way to a project's pods.
 *
 * All projects of a region (every organization's) share its project namespace. Without these, a
 * pod there or anywhere in the cluster reached a revision's pods directly — the revision Services
 * <revision> and <revision>-private, a pod's address on the queue-proxy (8012) or the user
 * container (8080) — or through the activator (activator-service with Knative-Serving-Namespace /
 * -Revision headers), past the route and its visibility: a cluster-local service of another
 * organization answered.
 *
 *  - <project namespace>/runr-knative-revisions: the revision pods (label serving.knative.dev/revision)
 *    admit traffic only from the Serving namespace's Traefik (the route), activator (scale from
 *    zero, buffering) and autoscaler (it scrapes queue-proxy's metrics port; without it the
 *    revision stays in panic mode). The namespace's other pods — CronJob runs, queue consumers,
 *    build Jobs, uploads — are not selected.
 *  - <serving namespace>/runr-activator: the activator admits its proxy ports only from Traefik and
 *    the autoscaler (which probes it before a revision goes to zero); its metrics port stays open
 *    to the Prometheus that scrapes the Serving namespace.
 *
 * In-cluster callers reach a service through its cluster-local URL (the placeholder's ExternalName,
 * PrivateIngress) and RunR itself through traefik-internal: both arrive from Traefik. Policies are
 * enforced by the cluster's network plugin; one without NetworkPolicy support ignores them.
 */
final class RegionNetworkPolicyManifests
{
    public const API = 'networking.k8s.io/v1';

    public const REVISIONS = 'runr-knative-revisions';

    public const ACTIVATOR = 'runr-activator';

    /** Pod labels of the Serving namespace's components */
    public const TRAEFIK = ['app.kubernetes.io/name' => 'traefik'];

    public const ACTIVATOR_POD = ['app' => 'activator'];

    public const AUTOSCALER_POD = ['app' => 'autoscaler'];

    /** The activator's container port Prometheus scrapes (Knative names it "metrics") */
    public const METRICS_PORT = 'metrics';

    public static function revisions(string $projectNamespace, string $servingNamespace): array
    {
        $from = fn (array $pods) => [
            'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => $servingNamespace]],
            'podSelector' => ['matchLabels' => $pods],
        ];

        return [
            'apiVersion' => self::API,
            'kind' => 'NetworkPolicy',
            'metadata' => self::metadata(self::REVISIONS, $projectNamespace),
            'spec' => [
                'podSelector' => ['matchExpressions' => [['key' => 'serving.knative.dev/revision', 'operator' => 'Exists']]],
                'policyTypes' => ['Ingress'],
                'ingress' => [[
                    'from' => [$from(self::TRAEFIK), $from(self::ACTIVATOR_POD), $from(self::AUTOSCALER_POD)],
                ]],
            ],
        ];
    }

    public static function activator(string $servingNamespace): array
    {
        return [
            'apiVersion' => self::API,
            'kind' => 'NetworkPolicy',
            'metadata' => self::metadata(self::ACTIVATOR, $servingNamespace),
            'spec' => [
                'podSelector' => ['matchLabels' => self::ACTIVATOR_POD],
                'policyTypes' => ['Ingress'],
                'ingress' => [
                    ['from' => [['podSelector' => ['matchLabels' => self::TRAEFIK]], ['podSelector' => ['matchLabels' => self::AUTOSCALER_POD]]]],
                    ['ports' => [['protocol' => 'TCP', 'port' => self::METRICS_PORT]]],
                ],
            ],
        ];
    }

    /**
     * The policy as it stands on the cluster matches the wanted one (spec compared; labels only
     * for RunR's marker).
     */
    public static function upToDate(?array $current, array $wanted): bool
    {
        if (! $current) {
            return false;
        }

        return self::normalize($current['spec'] ?? []) == self::normalize($wanted['spec']);
    }

    /** Keys sorted recursively, so the API server's field order does not count */
    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => self::normalize($v), $value);
    }

    private static function metadata(string $name, string $namespace): array
    {
        return [
            'name' => $name,
            'namespace' => $namespace,
            'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/part-of' => 'runr'],
        ];
    }
}
