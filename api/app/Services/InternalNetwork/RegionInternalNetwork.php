<?php

namespace App\Services\InternalNetwork;

use App\Models\KnativeRegion;
use App\Services\Helpers\PrivateIngress;
use App\Services\InternalNetwork\InternalNetworkManifests as M;
use App\Services\KubernetesClient;
use App\Services\Manifests\ProjectDomainManifests;
use RuntimeException;

/**
 * The internal network of a region's cluster (docs/internal-network.md): what the Serving
 * install puts there and region:reconcile keeps (InternalNetworkStep).
 *
 *  - the authz (Deployment runr-internal-auth, the region agent in authz mode) and its two
 *    entry-point Middlewares in the Serving namespace;
 *  - its Secret runr-internal-network (key.<region id>: each region's signing key — regions on one
 *    cluster share the authz) and ConfigMap (mode.<project namespace>, cross_tenant);
 *  - CoreDNS: *.runr.internal is the private entry point's Service (the Corefile of kube-system's
 *    coredns ConfigMap, or the coredns-custom ConfigMap where the Corefile imports it, as k3s
 *    and some managed clusters do).
 *
 * The region Traefik's entry point refers to the Middlewares (ports.internal.http.middlewares,
 * KnativeTraefikTrait / InternalNetworkStep).
 */
final class RegionInternalNetwork
{
    public const COREDNS_CUSTOM = 'coredns-custom';

    public const COREDNS_OVERRIDE_KEY = 'runr-internal.override';

    /**
     * @param  list<KnativeRegion>  $regions  the regions whose Serving namespace is $servingNs
     * @param  bool  $wait  wait for the authz to be ready (knative.internal_network.authz_ready_timeout_seconds)
     * @return list<string> what changed (or, in a dry run, would)
     */
    public static function ensure(KubernetesClient $k8s, string $servingNs, array $regions, bool $dryRun = false, bool $wait = true): array
    {
        $lines = [];
        foreach ([self::keys($k8s, $servingNs, $regions, $dryRun), self::config($k8s, $servingNs, $regions, $dryRun), self::authz($k8s, $servingNs, $dryRun, $wait)] as $line) {
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($line = self::coreDns($k8s, $servingNs, $dryRun)) {
            $lines[] = $line;
        }

        return $lines;
    }

    /** The signing key of every region on the cluster (key.<region id>), others' kept */
    private static function keys(KubernetesClient $k8s, string $ns, array $regions, bool $dryRun): ?string
    {
        $current = $k8s->getResourceStatus('v1', 'Secret', M::CONFIG, $ns)['data'] ?? [];
        $data = $current;
        foreach ($regions as $region) {
            $data['key.'.$region->id] = base64_encode(InternalNetwork::regionKey((string) $region->id));
        }
        if ($data == $current) {
            return null;
        }
        if (! $dryRun && ! $k8s->applyResource([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => M::CONFIG, 'namespace' => $ns, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            'type' => 'Opaque',
            'data' => $data,
        ], $ns)) {
            throw new RuntimeException("Secret {$ns}/".M::CONFIG.' was rejected');
        }

        return ($dryRun ? 'would write' : 'wrote').' the signing key of '.implode(', ', array_map(fn (KnativeRegion $r) => $r->name, $regions)).' (Secret '.$ns.'/'.M::CONFIG.')';
    }

    /** enforce / audit of each region's project namespace and the platform's cross-organization switch */
    private static function config(KubernetesClient $k8s, string $ns, array $regions, bool $dryRun): ?string
    {
        $current = $k8s->getResourceStatus('v1', 'ConfigMap', M::CONFIG, $ns)['data'] ?? null;
        $modes = [];
        foreach ((array) $current as $key => $value) {
            if (str_starts_with((string) $key, 'mode.')) {
                $modes[substr((string) $key, 5)] = (string) $value;
            }
        }
        foreach ($regions as $region) {
            $modes[$region->project_namespace ?: $region->name] = InternalNetwork::modeOf($region);
        }
        ksort($modes);
        $wanted = M::configMap($ns, $modes, InternalNetwork::crossTenantGrantsEnabled());
        if ($current !== null && $current == $wanted['data']) {
            return null;
        }
        if (! $dryRun && ! $k8s->applyResource($wanted, $ns)) {
            throw new RuntimeException("ConfigMap {$ns}/".M::CONFIG.' was rejected');
        }

        $describe = implode(', ', array_map(fn ($n, $m) => "{$n} {$m}", array_keys($modes), $modes));

        return ($dryRun ? 'would set' : 'set')." the internal network's modes ({$describe}), cross-organization grants "
            .($wanted['data']['cross_tenant'] === 'true' ? 'allowed' : 'off');
    }

    /** The authz and the entry-point Middlewares; waits for the authz to be ready */
    private static function authz(KubernetesClient $k8s, string $ns, bool $dryRun, bool $wait): ?string
    {
        $manifests = array_merge(M::authz($ns, (string) config('knative.internal_network.authz_image')), M::entryPointMiddlewareManifests($ns));
        $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', M::AUTHZ, $ns);
        $middleware = $k8s->getResourceStatus(ProjectDomainManifests::TRAEFIK_API, 'Middleware', M::AUTHZ, $ns);
        $wanted = collect($manifests)->firstWhere('kind', 'Deployment');
        $current = $deployment['spec']['template']['spec']['containers'][0] ?? null;
        $upToDate = $deployment && $middleware
            && ($current['image'] ?? null) === $wanted['spec']['template']['spec']['containers'][0]['image']
            && ($current['env'] ?? null) == $wanted['spec']['template']['spec']['containers'][0]['env'];
        if ($upToDate) {
            return null;
        }
        if ($dryRun) {
            return "would install the internal network's authz ({$ns}/".M::AUTHZ.', two pods) and its entry-point Middlewares';
        }

        foreach ($manifests as $manifest) {
            $applied = in_array($manifest['kind'], ['ClusterRole', 'ClusterRoleBinding'], true)
                ? $k8s->applyClusterResource($manifest)
                : $k8s->applyResource($manifest, $ns);
            if (! $applied) {
                throw new RuntimeException("{$manifest['kind']} {$ns}/{$manifest['metadata']['name']} was rejected");
            }
        }
        $line = "installed the internal network's authz ({$ns}/".M::AUTHZ.', two pods) and its entry-point Middlewares';
        if ($wait && ! self::waitReady($k8s, $ns)) {
            $line .= ' — not ready yet: the private entry point gets its ForwardAuth once it is (the next region:reconcile)';
        }

        return $line;
    }

    /** Whether a pod of the authz is ready (the entry point may refer to it) */
    public static function authzReady(KubernetesClient $k8s, string $ns): bool
    {
        $status = $k8s->getResourceStatus('apps/v1', 'Deployment', M::AUTHZ, $ns)['status'] ?? [];

        return (int) ($status['readyReplicas'] ?? 0) >= 1;
    }

    private static function waitReady(KubernetesClient $k8s, string $ns): bool
    {
        $deadline = time() + (int) config('knative.internal_network.authz_ready_timeout_seconds', 180);
        while (! self::authzReady($k8s, $ns)) {
            if (time() >= $deadline) {
                return false;
            }
            sleep(3);
        }

        return true;
    }

    /**
     * *.runr.internal → the private entry point's Service. Left alone (and said) where CoreDNS is
     * not RunR's to change.
     */
    public static function coreDns(KubernetesClient $k8s, string $servingNs, bool $dryRun): ?string
    {
        $cm = $k8s->getResourceStatus('v1', 'ConfigMap', 'coredns', 'kube-system');
        if (! $cm) {
            return 'left alone: no ConfigMap kube-system/coredns — resolve *.'.InternalNetwork::DOMAIN.' to '
                .PrivateIngress::serviceHostname(PrivateIngress::SERVICE, $servingNs).' in the cluster DNS by hand';
        }
        $corefile = (string) ($cm['data']['Corefile'] ?? '');

        // k3s and some managed clusters import their customizations from coredns-custom
        if (preg_match('/^\s*import\s+\S*custom\/\*\.override\s*$/m', $corefile)) {
            $custom = $k8s->getResourceStatus('v1', 'ConfigMap', self::COREDNS_CUSTOM, 'kube-system');
            $want = M::corednsRewrite($servingNs, '');
            if (($custom['data'][self::COREDNS_OVERRIDE_KEY] ?? null) === $want) {
                return null;
            }
            if (! $dryRun && ! $k8s->applyResource([
                'apiVersion' => 'v1',
                'kind' => 'ConfigMap',
                'metadata' => ['name' => self::COREDNS_CUSTOM, 'namespace' => 'kube-system'],
                'data' => array_merge($custom['data'] ?? [], [self::COREDNS_OVERRIDE_KEY => $want]),
            ], 'kube-system')) {
                throw new RuntimeException('ConfigMap kube-system/'.self::COREDNS_CUSTOM.' was rejected');
            }

            return ($dryRun ? 'would add' : 'added').' the '.InternalNetwork::DOMAIN.' rewrite to kube-system/'.self::COREDNS_CUSTOM.' ('.self::COREDNS_OVERRIDE_KEY.')';
        }

        $new = M::corefileWith($corefile, $servingNs);
        if ($new === null) {
            return 'left alone: the Corefile of kube-system/coredns has no kubernetes plugin to put the '.InternalNetwork::DOMAIN.' rewrite before';
        }
        if ($new === $corefile) {
            return null;
        }
        if (! $dryRun && ! $k8s->mergePatch('v1', 'ConfigMap', 'coredns', 'kube-system', ['data' => ['Corefile' => $new]])) {
            throw new RuntimeException('ConfigMap kube-system/coredns was rejected');
        }

        return ($dryRun ? 'would add' : 'added').' the '.InternalNetwork::DOMAIN.' rewrite to kube-system/coredns (CoreDNS reloads its Corefile)';
    }
}
