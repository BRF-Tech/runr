<?php

namespace App\Services\RegionReconcile\Steps;

use App\Models\KnativeRegion;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\IngressTrust;
use App\Services\Helpers\PrivateIngress;
use App\Services\Helpers\RegionDomains;
use App\Services\Helpers\RequestTimeout;
use App\Services\KubernetesClient;
use App\Services\PublicHostRewrites;
use App\Services\RegionDomainAliasService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\HelmReleaseUpgrade;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use RuntimeException;

/**
 * Cluster-local Knative routes move off the LoadBalancer (PrivateIngress): the region Traefik of
 * earlier releases served them on web, so a client outside the cluster reached any cluster-local
 * service (queue-trigger sinks, model services) with its Host header.
 *
 * In this order, each part skipped once done, so a run that stops halfway goes on where it stopped:
 *
 *  1. RunR's routes that re-entered Traefik with a cluster-local host move to the public host,
 *     which web serves before and after: custom-domain Middlewares (PublicHostRewrites) and
 *     domain-alias proxies (RegionDomainAliasService, SERVICE_SUFFIX).
 *  2. Traefik (RunR's Helm release only, values reused, at the chart version it runs): the
 *     private entry point internal and its ClusterIP Service traefik-internal, cluster-local
 *     routes on web and internal, traefik-internal as the private load balancer
 *     (STATE_TRANSITIONAL); it returns once every Traefik pod runs that way.
 *  3. The KIngresses that still name traefik as their private load balancer: Traefik writes a
 *     KIngress status only when its generation changed, so the placeholders' ExternalName would
 *     keep pointing at traefik. Their status.observedGeneration is set back (status subresource;
 *     the routes are not touched), Traefik writes the status again with traefik-internal, and
 *     Knative points the placeholder at it — all of them at once, nothing is deleted. Callers
 *     that still resolve the old name meanwhile are served on web. Then DNS caches of the old name
 *     expire.
 *  4. Traefik serves cluster-local routes on internal only (STATE_PRIVATE).
 *
 * The Traefik pods roll twice; public routes stay on web and websecure throughout.
 */
final class PrivateIngressStep implements ReconcileStep
{
    private const KINGRESS_API = 'networking.internal.knative.dev/v1alpha1';

    /** Seconds the Traefik pods get to run the new arguments after the Helm upgrade */
    private const PODS_TIMEOUT = 300;

    public function name(): string
    {
        return 'private-ingress';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];
        $seen = [];

        foreach ($cluster->targets as $target) {
            $ns = $target->region->project_namespace ?: $target->region->name;
            if (isset($seen[$ns])) {
                continue;
            }
            $seen[$ns] = true;

            array_push($lines, ...PublicHostRewrites::sync($cluster->k8s, $ns, $dryRun));
            array_push($lines, ...$this->aliases($cluster->k8s, $target->region, $ns, $dryRun));
        }

        foreach ($cluster->servingNamespaces() as $namespace) {
            array_push($lines, ...$this->traefik($cluster, $namespace, $dryRun));
        }

        return $lines;
    }

    /** @return list<string> */
    private function traefik(ClusterGroup $cluster, string $namespace, bool $dryRun): array
    {
        $k8s = $cluster->k8s;
        $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', KnativeConstants::TRAEFIK_RELEASE, $namespace);
        // no Traefik, or not RunR's (ingress-trust reports that one)
        if (! $deployment || ! IngressTrust::isRunrRelease($deployment, $namespace)) {
            return [];
        }

        $release = $namespace.'/'.KnativeConstants::TRAEFIK_RELEASE;
        $state = PrivateIngress::state(self::traefikArgs($deployment));
        if ($state === null) {
            return ["left alone: Traefik {$release} serves cluster-local Knative routes on no entry point of RunR's"];
        }

        $members = array_filter(
            $cluster->targets,
            fn (ReconcileTarget $t) => ($t->region->knative_serving_namespace ?: 'knative-serving') === $namespace,
        );
        $timeout = RequestTimeout::longest(array_map(fn (ReconcileTarget $t) => $t->region, $members));
        $lines = [];

        if ($state === PrivateIngress::STATE_LEGACY) {
            $line = $this->upgrade($cluster, $namespace, PrivateIngress::STATE_TRANSITIONAL, $timeout, $dryRun,
                'private entry point internal (Service '.PrivateIngress::SERVICE.') next to web for cluster-local Knative routes, '
                .PrivateIngress::SERVICE.' as their load balancer, public routes on web, websecure only');
            $lines[] = $line;
            if ($dryRun) {
                $lines[] = 'would point the KIngresses at '.PrivateIngress::SERVICE.' (status written again, routes untouched), then serve cluster-local routes on internal only';

                return $lines;
            }
            if (str_starts_with($line, 'left alone')) {
                return $lines;
            }
            $this->waitForPods($k8s, $namespace, PrivateIngress::STATE_TRANSITIONAL);
            $state = PrivateIngress::STATE_TRANSITIONAL;
        }

        $stale = $this->staleIngresses($k8s, $namespace);
        if ($stale !== []) {
            $lines[] = $dryRun
                ? 'would point '.count($stale).' KIngress(es) still naming '.self::legacyHost($namespace).' at '.PrivateIngress::SERVICE.' (status written again, routes untouched)'
                : $this->regenerate($k8s, $namespace, $stale);
        }

        if ($state === PrivateIngress::STATE_TRANSITIONAL) {
            if ($dryRun) {
                $lines[] = "would serve cluster-local Knative routes of {$release} on internal only";

                return $lines;
            }
            // the placeholders' previous ExternalName leaves DNS caches
            $grace = (int) config('knative.private_ingress.dns_grace_seconds', 35);
            if ($stale !== [] && $grace > 0) {
                sleep($grace);
            }
            $lines[] = $this->upgrade($cluster, $namespace, PrivateIngress::STATE_PRIVATE, $timeout, false,
                'cluster-local Knative routes on internal only — no longer on web, which the LoadBalancer publishes');
        }

        return $lines;
    }

    private function upgrade(ClusterGroup $cluster, string $namespace, string $state, int $timeout, bool $dryRun, string $change): string
    {
        return HelmReleaseUpgrade::apply(
            $cluster, $dryRun,
            KnativeConstants::TRAEFIK_RELEASE, $namespace, KnativeConstants::TRAEFIK_CHART,
            KnativeConstants::TRAEFIK_REPO_NAME, KnativeConstants::TRAEFIK_REPO_URL,
            PrivateIngress::helmSetJson($namespace, $state, $timeout),
            $change,
            'the Traefik pods roll once',
            wait: true,
        );
    }

    /**
     * Every Traefik pod — terminating ones too, they still write KIngress statuses — runs the
     * arguments of $state.
     */
    private function waitForPods(KubernetesClient $k8s, string $namespace, string $state): void
    {
        $deadline = time() + self::PODS_TIMEOUT;
        do {
            $pods = array_filter(
                $k8s->listResources('v1', 'Pod', $namespace, ['app.kubernetes.io/name' => 'traefik'])['items'] ?? [],
                fn (array $pod) => ! in_array($pod['status']['phase'] ?? '', ['Succeeded', 'Failed'], true),   // evicted ones run nothing
            );
            $behind = array_filter($pods, fn (array $pod) => PrivateIngress::state(self::traefikArgs($pod)) !== $state);
            if ($pods !== [] && $behind === []) {
                return;
            }
            sleep(3);
        } while (time() < $deadline);

        throw new RuntimeException('Traefik pods of '.$namespace.' still run the previous arguments after '.self::PODS_TIMEOUT.' s: '
            .implode(', ', array_map(fn (array $pod) => $pod['metadata']['name'] ?? '?', $behind)));
    }

    /**
     * KIngresses that name this Traefik's earlier private load balancer (traefik.<namespace>).
     *
     * @return list<array>
     */
    private function staleIngresses(KubernetesClient $k8s, string $namespace): array
    {
        $legacy = self::legacyHost($namespace);

        return array_values(array_filter(
            $k8s->listClusterResources(self::KINGRESS_API, 'Ingress')['items'] ?? [],
            fn (array $ingress) => PrivateIngress::privateLoadBalancer($ingress) === $legacy,
        ));
    }

    /**
     * @param  list<array>  $stale
     */
    private function regenerate(KubernetesClient $k8s, string $namespace, array $stale): string
    {
        $wanted = PrivateIngress::serviceHostname(PrivateIngress::SERVICE, $namespace);
        $pending = [];
        $started = microtime(true);

        foreach ($stale as $ingress) {
            $ns = (string) ($ingress['metadata']['namespace'] ?? '');
            $name = (string) ($ingress['metadata']['name'] ?? '');
            // Traefik writes a status again when its observedGeneration lags the generation
            $observed = max(0, (int) ($ingress['metadata']['generation'] ?? 1) - 1);
            if (! $k8s->mergePatchStatus(self::KINGRESS_API, 'Ingress', $name, $ns, ['status' => ['observedGeneration' => $observed]])) {
                throw new RuntimeException("status patch of KIngress {$ns}/{$name} was rejected");
            }
            $pending["{$ns}/{$name}"] = [$ns, $name, $ingress['metadata']['labels']['serving.knative.dev/route'] ?? $name];
        }

        $timeout = (int) config('knative.private_ingress.regenerate_timeout_seconds', 180);
        $deadline = time() + $timeout;
        while (true) {
            foreach ($pending as $key => [$ns, $name, $route]) {
                $ingress = $k8s->getResourceStatus(self::KINGRESS_API, 'Ingress', $name, $ns);
                if (! $ingress || (PrivateIngress::privateLoadBalancer($ingress) === $wanted && $this->placeholdersAt($k8s, $ns, $route, $wanted))) {
                    unset($pending[$key]);
                }
            }
            if ($pending === [] || time() >= $deadline) {
                break;
            }
            sleep(2);
        }

        if ($pending !== []) {
            throw new RuntimeException(count($pending).' KIngress(es) still name '.self::legacyHost($namespace).' after '.$timeout
                .' s ('.implode(', ', array_keys($pending)).'); the next region:reconcile tries again, and Traefik keeps serving cluster-local routes on web until it completes');
        }

        return 'pointed '.count($stale).' KIngress(es) at '.PrivateIngress::SERVICE.' in '.round(microtime(true) - $started, 1)
            .' s: Traefik wrote their status again and Knative moved the placeholders\' ExternalName (routes untouched)';
    }

    /** Every ExternalName placeholder of the route names $host */
    private function placeholdersAt(KubernetesClient $k8s, string $namespace, string $route, string $host): bool
    {
        foreach ($k8s->listResources('v1', 'Service', $namespace, ['serving.knative.dev/route' => $route])['items'] ?? [] as $service) {
            if (($service['spec']['type'] ?? '') === 'ExternalName' && ($service['spec']['externalName'] ?? '') !== $host) {
                return false;
            }
        }

        return true;
    }

    /**
     * Domain-alias proxies of earlier releases sent <service>.<alias> to the cluster-local host
     * <service>.<namespace>.svc: they move to the public host the services answer on now.
     *
     * @return list<string>
     */
    private function aliases(KubernetesClient $k8s, KnativeRegion $region, string $ns, bool $dryRun): array
    {
        $lines = [];
        foreach ($k8s->listResources('apps/v1', 'Deployment', $ns, ['app.kubernetes.io/managed-by' => 'runr'])['items'] ?? [] as $deployment) {
            $name = (string) ($deployment['metadata']['name'] ?? '');
            $container = collect($deployment['spec']['template']['spec']['containers'] ?? [])->firstWhere('name', 'proxy');
            $suffix = collect($container['env'] ?? [])->firstWhere('name', 'SERVICE_SUFFIX')['value'] ?? null;
            if (! str_starts_with($name, RegionDomainAliasService::NAME) || $suffix !== ".{$ns}.svc") {
                continue;
            }

            $domain = $this->aliasPublicDomain($k8s, $ns, $region, $deployment['metadata']['labels']['runr/tenant'] ?? null);
            if (! $domain) {
                $lines[] = "left alone: domain alias {$ns}/{$name} sends to cluster-local hosts, and region {$region->name} has no function domain";

                continue;
            }

            $patch = ['spec' => ['template' => ['spec' => ['containers' => [[
                'name' => 'proxy', 'env' => [['name' => 'SERVICE_SUFFIX', 'value' => ".{$domain}"]],
            ]]]]]];
            if (! $dryRun && ! $k8s->strategicMergePatch('apps/v1', 'Deployment', $name, $ns, $patch)) {
                throw new RuntimeException("patch of Deployment {$ns}/{$name} was rejected");
            }
            $lines[] = ($dryRun ? 'would send' : 'sent')." domain alias {$ns}/{$name} to the services' public hosts: *{$suffix} → *.{$domain}";
        }

        return $lines;
    }

    /**
     * The domain an alias' services answer on: an organization's alias (runr/tenant), the domain of
     * its services' URLs; the platform's, the region's function domain.
     */
    private function aliasPublicDomain(KubernetesClient $k8s, string $ns, KnativeRegion $region, ?string $tenant): ?string
    {
        if ($tenant !== null) {
            foreach ($k8s->listResources(PublicHostRewrites::KSVC_API, 'Service', $ns, ['runr/tenant' => $tenant])['items'] ?? [] as $ksvc) {
                if ($host = PublicHostRewrites::hostOf($ksvc)) {
                    return substr($host, strpos($host, '.') + 1);
                }
            }
        }

        return RegionDomains::functionDomain($region);
    }

    /** @return list<string> args of the traefik container of a Deployment or Pod */
    private static function traefikArgs(array $workload): array
    {
        $spec = $workload['spec']['template']['spec'] ?? $workload['spec'] ?? [];
        $containers = $spec['containers'] ?? [];
        $container = collect($containers)->firstWhere('name', 'traefik') ?? ($containers[0] ?? []);

        return $container['args'] ?? [];
    }

    private static function legacyHost(string $namespace): string
    {
        return PrivateIngress::serviceHostname(KnativeConstants::TRAEFIK_RELEASE, $namespace);
    }
}
