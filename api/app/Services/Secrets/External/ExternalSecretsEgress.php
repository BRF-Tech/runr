<?php

namespace App\Services\Secrets\External;

use App\Models\KnativeRegion;
use App\Models\RegionSecretSetting;
use App\Services\KubernetesClient;
use App\Services\Observability\OutboundUrlGuard;
use App\Services\Observability\TenantScope;
use App\Services\Secrets\OpenBao\OpenBaoClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The egress of the External Secrets Operator controller on a platform region of the
 * multi-tenant edition (docs/secret-stores.md#network): the controller connects to the stores of
 * every organization on the region, and resolves their names itself — RunR checks a store's
 * address when it is saved, verified and deployed (SecretStore::addressProblem()), the policy keeps
 * a name that answers another address by the time the controller connects from reaching the
 * cluster's network. Allowed:
 *
 *  - DNS to the cluster DNS (kube-system, k8s-app: kube-dns) and NodeLocal DNSCache's address
 *    (NODE_LOCAL_DNS), UDP and TCP 53;
 *  - the Kubernetes API server (its Service address and endpoints; on Cilium the kube-apiserver
 *    entity, as Cilium's CIDR rules do not select the cluster's nodes);
 *  - the region's own OpenBao (namespace runr-openbao, 8200);
 *  - public addresses: everything outside the outbound guard's ranges (OutboundUrlGuard), IPv4
 *    and IPv6 — global unicast without Teredo, benchmarking, ORCHID, documentation, and 6to4 and
 *    NAT64 addresses only where the IPv4 address they carry is public;
 *  - the CIDRs of the platform's own private stores (secrets.external.private_egress_cidrs).
 *
 * Only the controller is selected (the webhook and the cert controller talk to the API server
 * only). A NetworkPolicy, or a CiliumNetworkPolicy where the cluster runs Cilium. Not on a single-
 * tenant installation, a tenant's own region (its own cluster and network) or with
 * secrets.external.allow_private_targets: there the policy is removed.
 */
final class ExternalSecretsEgress
{
    public const NAME = 'runr-eso-egress';

    public const NETWORK_API = 'networking.k8s.io/v1';

    public const CILIUM_API = 'cilium.io/v2';

    private const LABELS = ['app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/part-of' => 'runr'];

    /** The link-local address NodeLocal DNSCache answers on (a cluster running it resolves there) */
    public const NODE_LOCAL_DNS = '169.254.20.10/32';

    /** The pod template annotation of `kubectl rollout restart` */
    public const RESTARTED_AT = 'kubectl.kubernetes.io/restartedAt';

    /** The controller's pods (the chart's selector labels; the webhook and cert controller have other names) */
    public static function controller(): array
    {
        return ['app.kubernetes.io/name' => 'external-secrets', 'app.kubernetes.io/instance' => (string) config('secrets.external.release', 'external-secrets')];
    }

    /**
     * The policy is wanted for these regions of one cluster: the multi-tenant edition, a platform
     * region among them with the operator RunR installed, private targets not allowed.
     *
     * @param  iterable<KnativeRegion>  $regions
     */
    public static function wanted(iterable $regions): bool
    {
        if (! TenantScope::enabled() || config('secrets.external.allow_private_targets', false)) {
            return false;
        }
        foreach ($regions as $region) {
            $settings = RegionSecretSetting::forRegion($region);
            if ($settings->owner_tenant_id === null && empty($region->getAttributes()['owner_tenant_id'] ?? null)
                && in_array($settings->eso_status, [RegionSecretSetting::ESO_READY, RegionSecretSetting::ESO_INSTALLING], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Address blocks the controller may reach: cidr => the blocked ranges inside it.
     *
     * @return array<string, list<string>>
     */
    public static function publicBlocks(): array
    {
        $ipv4 = OutboundUrlGuard::BLOCKED_IPV4;

        return [
            '0.0.0.0/0' => $ipv4,
            OutboundUrlGuard::GLOBAL_IPV6 => array_merge(OutboundUrlGuard::BLOCKED_IPV6, array_map(fn (string $cidr) => self::embedded('2002::', 16, $cidr), $ipv4)),
            '64:ff9b::/96' => array_map(fn (string $cidr) => self::embedded('64:ff9b::', 96, $cidr), $ipv4),
        ];
    }

    /** The IPv6 range of $prefix/$bits whose next bits carry the IPv4 range $cidr (6to4, NAT64). */
    public static function embedded(string $prefix, int $bits, string $cidr): string
    {
        [$ip, $length] = explode('/', $cidr);
        $bytes = (string) inet_pton($prefix);
        $v4 = (string) inet_pton($ip);
        $offset = intdiv($bits, 8);
        $bytes = substr($bytes, 0, $offset).$v4.substr($bytes, $offset + 4);

        return inet_ntop($bytes).'/'.($bits + (int) $length);
    }

    /** @return list<string> valid CIDRs of secrets.external.private_egress_cidrs */
    public static function privateCidrs(): array
    {
        $out = [];
        foreach ((array) config('secrets.external.private_egress_cidrs', []) as $cidr) {
            $cidr = trim((string) $cidr);
            if (preg_match('~^([0-9a-fA-F:.]+)/(\d{1,3})$~', $cidr, $m) && ($bin = @inet_pton($m[1])) !== false && (int) $m[2] <= strlen($bin) * 8) {
                $out[] = $cidr;
            } elseif ($cidr !== '') {
                Log::warning('secrets.external.private_egress_cidrs: not a CIDR, left out', ['value' => $cidr]);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The API server as the controller reaches it: its Service address on 443 and its endpoints.
     *
     * @return array{addresses: list<string>, ports: list<int>}
     */
    public static function apiServer(KubernetesClient $k8s): array
    {
        $addresses = [];
        $ports = [];
        $service = $k8s->getPath('/api/v1/namespaces/default/services/kubernetes');
        foreach ((array) ($service['spec']['clusterIPs'] ?? [$service['spec']['clusterIP'] ?? null]) as $ip) {
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                $addresses[] = $ip;
            }
        }
        foreach ((array) ($service['spec']['ports'] ?? []) as $port) {
            if (isset($port['port'])) {
                $ports[] = (int) $port['port'];
            }
        }
        $endpoints = $k8s->getPath('/api/v1/namespaces/default/endpoints/kubernetes');
        foreach ((array) ($endpoints['subsets'] ?? []) as $subset) {
            foreach ((array) ($subset['addresses'] ?? []) as $address) {
                if (is_string($address['ip'] ?? null) && filter_var($address['ip'], FILTER_VALIDATE_IP)) {
                    $addresses[] = $address['ip'];
                }
            }
            foreach ((array) ($subset['ports'] ?? []) as $port) {
                if (isset($port['port'])) {
                    $ports[] = (int) $port['port'];
                }
            }
        }
        $addresses = array_values(array_unique($addresses));
        $ports = array_values(array_unique($ports ?: [443]));
        sort($addresses);
        sort($ports);

        return ['addresses' => $addresses, 'ports' => $ports];
    }

    /**
     * @param  array{addresses: list<string>, ports: list<int>}  $apiServer
     * @param  list<string>  $privateCidrs
     */
    public static function networkPolicy(string $namespace, array $apiServer, array $privateCidrs): array
    {
        $block = fn (string $cidr, array $except = []) => ['ipBlock' => $except === [] ? ['cidr' => $cidr] : ['cidr' => $cidr, 'except' => $except]];
        $host = fn (string $ip) => $block($ip.(str_contains($ip, ':') ? '/128' : '/32'));

        $egress = [
            [
                'to' => [
                    ['namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => 'kube-system']], 'podSelector' => ['matchLabels' => ['k8s-app' => 'kube-dns']]],
                    $block(self::NODE_LOCAL_DNS),
                ],
                'ports' => [['protocol' => 'UDP', 'port' => 53], ['protocol' => 'TCP', 'port' => 53]],
            ],
            [
                'to' => [['namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => OpenBaoClient::NAMESPACE]]]],
                'ports' => [['protocol' => 'TCP', 'port' => OpenBaoClient::PORT]],
            ],
        ];
        if ($apiServer['addresses'] !== []) {
            $egress[] = [
                'to' => array_map($host, $apiServer['addresses']),
                'ports' => array_map(fn (int $port) => ['protocol' => 'TCP', 'port' => $port], $apiServer['ports']),
            ];
        }
        $public = [];
        foreach (self::publicBlocks() as $cidr => $except) {
            $public[] = $block($cidr, $except);
        }
        $egress[] = ['to' => $public];
        if ($privateCidrs !== []) {
            $egress[] = ['to' => array_map(fn (string $cidr) => $block($cidr), $privateCidrs)];
        }

        return [
            'apiVersion' => self::NETWORK_API,
            'kind' => 'NetworkPolicy',
            'metadata' => ['name' => self::NAME, 'namespace' => $namespace, 'labels' => self::LABELS],
            'spec' => [
                'podSelector' => ['matchLabels' => self::controller()],
                'policyTypes' => ['Egress'],
                'egress' => $egress,
            ],
        ];
    }

    /** @param  list<string>  $privateCidrs */
    public static function ciliumPolicy(string $namespace, array $privateCidrs): array
    {
        $cidrSet = [];
        foreach (self::publicBlocks() as $cidr => $except) {
            $cidrSet[] = ['cidr' => $cidr, 'except' => $except];
        }
        $egress = [
            ['toEntities' => ['kube-apiserver']],
            [
                'toEndpoints' => [['matchLabels' => ['k8s:io.kubernetes.pod.namespace' => 'kube-system', 'k8s-app' => 'kube-dns']]],
                'toPorts' => [['ports' => [['port' => '53', 'protocol' => 'ANY']]]],
            ],
            [
                'toCIDR' => [self::NODE_LOCAL_DNS],
                'toPorts' => [['ports' => [['port' => '53', 'protocol' => 'ANY']]]],
            ],
            [
                'toEndpoints' => [['matchLabels' => ['k8s:io.kubernetes.pod.namespace' => OpenBaoClient::NAMESPACE]]],
                'toPorts' => [['ports' => [['port' => (string) OpenBaoClient::PORT, 'protocol' => 'TCP']]]],
            ],
            ['toCIDRSet' => $cidrSet],
        ];
        if ($privateCidrs !== []) {
            $egress[] = ['toCIDR' => $privateCidrs];
        }

        return [
            'apiVersion' => self::CILIUM_API,
            'kind' => 'CiliumNetworkPolicy',
            'metadata' => ['name' => self::NAME, 'namespace' => $namespace, 'labels' => self::LABELS],
            'spec' => [
                'endpointSelector' => ['matchLabels' => self::controller()],
                'egress' => $egress,
            ],
        ];
    }

    /**
     * Apply the policy of the cluster (Cilium's or Kubernetes'; the other kind removed) where it
     * is missing or different. Nothing when the operator's namespace does not exist.
     *
     * @return list<string> what was applied (or, in a dry run, would be)
     */
    public static function ensure(KubernetesClient $k8s, bool $dryRun = false): array
    {
        $namespace = (string) config('secrets.external.namespace', 'external-secrets');
        if (! $k8s->getClusterResource('v1', 'Namespace', $namespace)) {
            return [];
        }

        $cilium = $k8s->hasApiVersion(self::CILIUM_API);
        $policy = $cilium ? self::ciliumPolicy($namespace, self::privateCidrs()) : self::networkPolicy($namespace, self::apiServer($k8s), self::privateCidrs());
        $lines = [];

        $current = $k8s->getResourceStatus($policy['apiVersion'], $policy['kind'], self::NAME, $namespace);
        $applied = false;
        if (! self::upToDate($current, $policy)) {
            if (! $dryRun && ! $k8s->applyResource($policy, $namespace)) {
                throw new RuntimeException("{$policy['kind']} {$namespace}/".self::NAME.' was rejected');
            }
            $applied = true;
            $lines[] = ($dryRun ? 'would apply' : 'applied')." {$policy['kind']} {$namespace}/".self::NAME
                .' (the External Secrets Operator controller reaches DNS, the API server, the region\'s OpenBao and public addresses only)';
        }

        // A cluster that moved to Cilium: the Kubernetes policy of before goes
        if ($cilium && $k8s->getResourceStatus(self::NETWORK_API, 'NetworkPolicy', self::NAME, $namespace)) {
            if (! $dryRun) {
                $k8s->deleteResource(self::NETWORK_API, 'NetworkPolicy', self::NAME, $namespace);
            }
            $lines[] = ($dryRun ? 'would remove' : 'removed')." NetworkPolicy {$namespace}/".self::NAME.' (Cilium: the CiliumNetworkPolicy replaces it)';
        }

        if ($applied && ($restart = self::restartController($k8s, $namespace, $dryRun)) !== null) {
            $lines[] = $restart;
        }

        return $lines;
    }

    /**
     * A new or changed policy holds for the connections the controller opens from then on: the
     * network plugin lets a connection it admitted before go on, and the controller keeps its
     * connections to the stores open. Its Deployment is restarted (the annotation of `kubectl
     * rollout restart`), so every connection it holds afterwards is one the policy admitted.
     * Nothing when the controller's Deployment is not there.
     */
    private static function restartController(KubernetesClient $k8s, string $namespace, bool $dryRun): ?string
    {
        $name = (string) config('secrets.external.release', 'external-secrets');
        if (! $k8s->getResourceStatus('apps/v1', 'Deployment', $name, $namespace)) {
            return null;
        }
        if ($dryRun) {
            return "would restart Deployment {$namespace}/{$name} (its connections of before the policy end)";
        }

        $patch = ['spec' => ['template' => ['metadata' => ['annotations' => [self::RESTARTED_AT => now()->utc()->format('Y-m-d\TH:i:s\Z')]]]]];
        if (! $k8s->mergePatch('apps/v1', 'Deployment', $name, $namespace, $patch)) {
            throw new RuntimeException("the egress policy is applied, but the restart of Deployment {$namespace}/{$name} was rejected: restart it, so its open connections end");
        }

        return "restarted Deployment {$namespace}/{$name} (its connections of before the policy end)";
    }

    /**
     * Remove the policy (no longer wanted: single-tenant, no platform region with the operator,
     * private targets allowed).
     *
     * @return list<string>
     */
    public static function remove(KubernetesClient $k8s, bool $dryRun = false): array
    {
        $namespace = (string) config('secrets.external.namespace', 'external-secrets');
        $lines = [];
        foreach ([[self::NETWORK_API, 'NetworkPolicy'], [self::CILIUM_API, 'CiliumNetworkPolicy']] as [$api, $kind]) {
            if ($kind === 'CiliumNetworkPolicy' && ! $k8s->hasApiVersion($api)) {
                continue;
            }
            if (! $k8s->getResourceStatus($api, $kind, self::NAME, $namespace)) {
                continue;
            }
            if (! $dryRun) {
                $k8s->deleteResource($api, $kind, self::NAME, $namespace);
            }
            $lines[] = ($dryRun ? 'would remove' : 'removed')." {$kind} {$namespace}/".self::NAME.' (no longer needed)';
        }

        return $lines;
    }

    /** The policy on the cluster matches the wanted one (spec compared, key order aside). */
    public static function upToDate(?array $current, array $wanted): bool
    {
        if (! $current) {
            return false;
        }

        return self::normalize($current['spec'] ?? []) == self::normalize($wanted['spec']);
    }

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
}
