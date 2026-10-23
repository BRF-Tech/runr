<?php

namespace App\Services;

use App\Services\Manifests\ProjectDomainManifests;
use RuntimeException;

/**
 * RunR's own routes that hand a request on to a project's Knative Service — a custom domain
 * (ProjectDomainManifests) and a gateway route (GatewayManifestTrait) — rewrite its Host to the
 * service's public host <ksvc>.<function domain> and re-enter the region Traefik on web (Service
 * traefik:80), where Knative's public route answers: these are public HTTP projects, and only a
 * public service has that route. Earlier releases rewrote it to the cluster-local host
 * <ksvc>.<namespace>.svc, which the LoadBalancer no longer serves (PrivateIngress).
 *
 * The public host is the Knative Service's URL (status.url), which a base-domain move or an
 * organization's own domain changes. Each rewrite Middleware names its service (annotation
 * runr.dev/service, label runr.dev/host-rewrite=public), and sync() points it at the service's
 * current host — run by the region status check (every minute) and by region:reconcile, which also
 * moves the custom-domain Middlewares of earlier releases.
 *
 * A domain move that completes or rolls back does not wait for that check: repoint() moves the
 * rewrites of the services it covers to the new domain at once (PublicHostMove), before Knative
 * switches over — the domain alias still answers on the new domain until then — and sync() runs
 * right after a rollback. Until Knative has reconciled a moved service, its status still names
 * the domain it left; sync() and lookup() do not follow a status.url on a domain the region no
 * longer serves (the keys of Knative's config-domain, servedDomains()).
 */
final class PublicHostRewrites
{
    public const LABEL = 'runr.dev/host-rewrite';

    public const LABEL_VALUE = 'public';

    public const ANNOTATION_SERVICE = 'runr.dev/service';

    public const KSVC_API = 'serving.knative.dev/v1';

    /** Labels and annotations of a rewrite Middleware for Knative Service $service */
    public static function metadata(string $service): array
    {
        return [
            'labels' => [self::LABEL => self::LABEL_VALUE],
            'annotations' => [self::ANNOTATION_SERVICE => $service],
        ];
    }

    /**
     * The public host of a Knative Service (its status.url), or null when it has none: not ready
     * yet, or cluster-local (<ksvc>.<namespace>.svc.cluster.local).
     */
    public static function hostOf(?array $ksvc): ?string
    {
        $host = strtolower((string) parse_url((string) ($ksvc['status']['url'] ?? ''), PHP_URL_HOST));
        if ($host === '' || ! str_contains($host, '.') || str_ends_with($host, '.svc.cluster.local') || str_ends_with($host, '.svc')) {
            return null;
        }

        return $host;
    }

    /**
     * The service's public host on the cluster, else $fallback (the host its project's URL names:
     * a service not deployed yet). With $served (servedDomains()): a status.url on a domain the
     * region no longer serves — Knative has not reconciled a completed domain move yet — gives way
     * to $fallback when that one is served.
     *
     * @param  list<string>|null  $served
     *
     * @throws RuntimeException without either
     */
    public static function lookup(KubernetesClient $k8s, string $namespace, string $service, ?string $fallback, ?array $served = null): string
    {
        $host = self::hostOf($k8s->getResourceStatus(self::KSVC_API, 'Service', $service, $namespace));
        if ($host !== null && $fallback && $served !== null && ! self::isServed($host, $served) && self::isServed($fallback, $served)) {
            $host = strtolower($fallback);
        }
        $host ??= $fallback;
        if (! $host) {
            throw new RuntimeException("Knative Service {$namespace}/{$service} has no public URL");
        }

        return $host;
    }

    /**
     * The domains Knative serves public routes on in the region: the keys of its config-domain
     * (the function domain, organizations' domains with their selectors). Null when it cannot be
     * read: nothing is filtered then.
     *
     * @return list<string>|null
     */
    public static function servedDomains(KubernetesClient $k8s, string $servingNamespace): ?array
    {
        try {
            $data = $k8s->getResourceStatus('v1', 'ConfigMap', 'config-domain', $servingNamespace)['data'] ?? null;
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data)) {
            return null;
        }

        $domains = [];
        foreach (array_keys($data) as $key) {
            $key = strtolower(trim((string) $key, '.'));
            if ($key !== '' && $key !== '_example' && str_contains($key, '.')) {
                $domains[] = $key;
            }
        }

        return $domains === [] ? null : $domains;
    }

    /** Whether $host is on one of the $served domains. */
    public static function isServed(string $host, array $served): bool
    {
        $host = strtolower($host);
        foreach ($served as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A domain move completed: every rewrite Middleware of a service the move covers that points
     * at <…>.<from> points at <…>.<to> now. Custom domains, gateway routes and global routes alike.
     *
     * @return list<string> what changed (or, in a dry run, would change)
     */
    public static function repoint(KubernetesClient $k8s, string $namespace, PublicHostMove $move, bool $dryRun = false): array
    {
        $middlewares = $k8s->listResources(ProjectDomainManifests::TRAEFIK_API, 'Middleware', $namespace, [self::LABEL => self::LABEL_VALUE])['items'] ?? [];
        $lines = [];
        foreach ($middlewares as $middleware) {
            $name = (string) ($middleware['metadata']['name'] ?? '');
            $current = $middleware['spec']['headers']['customRequestHeaders']['Host'] ?? null;
            $service = $middleware['metadata']['annotations'][self::ANNOTATION_SERVICE] ?? null;
            if ($name === '' || ! is_string($current) || ! is_string($service)) {
                continue;
            }

            $wanted = $move->map($service, $current);
            if ($wanted === strtolower($current)) {
                continue;
            }
            if (! $dryRun && ! $k8s->mergePatch(ProjectDomainManifests::TRAEFIK_API, 'Middleware', $name, $namespace, [
                'spec' => ['headers' => ['customRequestHeaders' => ['Host' => $wanted]]],
            ])) {
                throw new RuntimeException("merge patch of Middleware {$namespace}/{$name} was rejected");
            }
            $lines[] = ($dryRun ? 'would point' : 'pointed')." Middleware {$namespace}/{$name} at the moved domain: Host {$current} → {$wanted}";
        }

        return $lines;
    }

    /**
     * Points every rewrite Middleware of the namespace at its service's current public host; a
     * custom-domain Middleware of an earlier release (Host <ksvc>.<namespace>.svc) becomes one.
     * A service without a public URL keeps its Middleware as it is (an earlier release's one is
     * named: it stops answering once the LoadBalancer no longer serves cluster-local hosts).
     * With $servingNamespace a service whose status.url is on a domain the region no longer
     * serves (a domain move Knative has not reconciled yet) is left as it is.
     *
     * @return list<string> what changed (or, in a dry run, would change)
     */
    public static function sync(KubernetesClient $k8s, string $namespace, bool $dryRun = false, ?string $servingNamespace = null): array
    {
        $middlewares = $k8s->listResources(ProjectDomainManifests::TRAEFIK_API, 'Middleware', $namespace, ['app.kubernetes.io/managed-by' => 'runr'])['items'] ?? [];
        $lines = [];
        $services = null;
        $served = false;

        foreach ($middlewares as $middleware) {
            $name = (string) ($middleware['metadata']['name'] ?? '');
            $current = $middleware['spec']['headers']['customRequestHeaders']['Host'] ?? null;
            [$service, $legacy] = self::serviceOf($middleware, $namespace);
            if ($name === '' || ! is_string($current) || $service === null) {
                continue;
            }

            if ($services === null) {
                $services = [];
                foreach ($k8s->listResources(self::KSVC_API, 'Service', $namespace)['items'] ?? [] as $ksvc) {
                    $services[$ksvc['metadata']['name'] ?? ''] = self::hostOf($ksvc);
                }
            }

            $wanted = $services[$service] ?? null;
            if ($wanted === null) {
                if ($legacy) {
                    $lines[] = "left alone: Middleware {$namespace}/{$name} rewrites to {$current}, and Knative Service {$service} has no public URL";
                }

                continue;
            }
            if ($wanted === $current && ! $legacy) {
                continue;
            }
            if ($served === false) {
                $served = $servingNamespace !== null ? self::servedDomains($k8s, $servingNamespace) : null;
            }
            if ($served !== null && ! self::isServed($wanted, $served)) {
                // Knative has not caught up with a domain move yet: its status names a domain the
                // region no longer serves (repoint() moved the rewrite already)
                continue;
            }

            if (! $dryRun && ! $k8s->mergePatch(ProjectDomainManifests::TRAEFIK_API, 'Middleware', $name, $namespace, self::metadataPatch($service) + [
                'spec' => ['headers' => ['customRequestHeaders' => ['Host' => $wanted]]],
            ])) {
                throw new RuntimeException("merge patch of Middleware {$namespace}/{$name} was rejected");
            }
            $lines[] = ($dryRun ? 'would point' : 'pointed')." Middleware {$namespace}/{$name} at the public host: Host {$current} → {$wanted}";
        }

        return $lines;
    }

    /**
     * The Knative Service a Middleware rewrites to, and whether it is a custom-domain Middleware of
     * an earlier release (Host <ksvc>.<namespace>.svc, no service annotation).
     *
     * @return array{0: ?string, 1: bool}
     */
    private static function serviceOf(array $middleware, string $namespace): array
    {
        $labels = $middleware['metadata']['labels'] ?? [];
        $annotations = $middleware['metadata']['annotations'] ?? [];
        if (($labels[self::LABEL] ?? null) === self::LABEL_VALUE && is_string($annotations[self::ANNOTATION_SERVICE] ?? null)) {
            return [$annotations[self::ANNOTATION_SERVICE], false];
        }

        $host = $middleware['spec']['headers']['customRequestHeaders']['Host'] ?? null;
        $suffix = ".{$namespace}.svc";
        if (isset($annotations[ProjectDomainManifests::ANNOTATION_DOMAIN]) && is_string($host) && str_ends_with($host, $suffix)) {
            $service = substr($host, 0, -strlen($suffix));

            return [$service !== '' && ! str_contains($service, '.') ? $service : null, true];
        }

        return [null, false];
    }

    private static function metadataPatch(string $service): array
    {
        return ['metadata' => self::metadata($service)];
    }
}
