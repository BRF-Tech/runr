<?php

namespace App\Services\GlobalDns;

use App\Models\KnativeRegion;
use App\Models\RegionGlobalDns;
use App\Services\CertManager\CertManagerService;
use App\Services\Contracts\KnativeConstants;
use App\Services\GlobalProjects\GlobalRouteManifests;
use App\Services\KubernetesClient;
use App\Services\Manifests\ProjectDomainManifests;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What every global-DNS region has in its project namespace besides the project routes
 * (docs/global-projects.md): the health route of PowerDNS' checks (GlobalRouteManifests::
 * healthRoute) and the zone's wildcard certificate (GlobalTlsManifests) — applied again only when
 * what they should be changed (tls_spec_hash), their state read back on every sync.
 *
 * TLS needs the region's cert-manager, which the Serving install sets up (CertManagerService, as
 * for custom domains); while it is not ready the routes answer HTTPS with the region Traefik's
 * default certificate and the server's TLS status says why. The ACME account's e-mail is
 * global_dns.tls.acme_email, else the region's cert-manager ACME e-mail — one address serves both.
 */
class GlobalRegionSetup
{
    public function __construct(
        private readonly GlobalDnsZone $zone,
        private readonly GlobalDnsServerService $servers,
    ) {}

    /**
     * @param  RegionGlobalDns|null  $updateServer  the server cert-manager sends its updates to
     */
    public function sync(KnativeRegion $region, RegionGlobalDns $server, ?RegionGlobalDns $updateServer, ?KubernetesClient $k8s = null): void
    {
        $zone = $this->zone->name();
        if ($zone === null) {
            return;
        }

        $k8s ??= new KubernetesClient((string) $region->kubeconfig);
        $namespace = self::projectNamespace($region);

        $k8s->applyResource(GlobalRouteManifests::healthRoute(
            (string) $this->zone->healthHost($zone),
            $namespace,
            GlobalRouteManifests::AGENT_HEALTH_SERVICE,
            GlobalDnsServerService::AGENT_NAMESPACE,
            (int) config('global_dns.health.agent_port', 8086),
        ), $namespace);

        $this->syncTls($region, $server, $updateServer, $k8s, $namespace, $zone);
    }

    /** The region's health route and certificate objects go (the server is being removed). */
    public function remove(KnativeRegion $region, KubernetesClient $k8s): void
    {
        $namespace = self::projectNamespace($region);

        $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', GlobalRouteManifests::HEALTH_ROUTE, $namespace);
        if ($k8s->hasApiVersion(GlobalTlsManifests::API)) {
            $k8s->deleteResource(GlobalTlsManifests::API, 'Certificate', GlobalTlsManifests::CERTIFICATE, $namespace);
            $k8s->deleteResource(GlobalTlsManifests::API, 'Issuer', GlobalTlsManifests::ISSUER, $namespace);
        }
        $k8s->deleteResource('v1', 'Secret', GlobalTlsManifests::CERTIFICATE, $namespace);
        $k8s->deleteResource('v1', 'Secret', GlobalTlsManifests::TSIG_SECRET, $namespace);
        $k8s->deleteResource('v1', 'Secret', GlobalTlsManifests::ISSUER.'-account', $namespace);
    }

    public static function projectNamespace(KnativeRegion $region): string
    {
        return $region->project_namespace ?? $region->name;
    }

    public static function traefikNamespace(KnativeRegion $region): string
    {
        return $region->knative_serving_namespace ?: 'knative-serving';
    }

    public static function traefikService(): string
    {
        return KnativeConstants::TRAEFIK_RELEASE;
    }

    private function syncTls(KnativeRegion $region, RegionGlobalDns $server, ?RegionGlobalDns $updateServer, KubernetesClient $k8s, string $namespace, string $zone): void
    {
        if (config('global_dns.tls.mode', 'acme') !== 'acme') {
            $server->update(['tls_status' => 'none', 'tls_message' => null, 'tls_expires_at' => null]);

            return;
        }

        if (! app(CertManagerService::class)->isReady($region)) {
            $server->update(['tls_status' => 'error', 'tls_message' => __('api.global_dns.no_cert_manager'), 'tls_expires_at' => null]);

            return;
        }

        $nameserver = $updateServer ? ($this->servers->addressesOf($updateServer->address)[0] ?? null) : null;
        if ($nameserver === null) {
            $server->update(['tls_status' => 'pending', 'tls_message' => __('api.global_dns.no_update_server')]);

            return;
        }

        $options = [
            'zone' => $zone,
            'nameserver' => (str_contains($nameserver, ':') ? "[{$nameserver}]" : $nameserver).':53',
            'tsig_key_name' => $this->zone->tsigKeyName(),
            'tsig_secret' => $this->zone->tsigSecret(),
            'acme_server' => (string) config('global_dns.tls.acme_server'),
            'acme_email' => config('global_dns.tls.acme_email') ?: app(CertManagerService::class)->acmeEmail($region)['email'],
        ];
        $hash = GlobalTlsManifests::specHash($namespace, $options);

        try {
            if ($server->tls_spec_hash !== $hash) {
                foreach (GlobalTlsManifests::all($namespace, $options) as $manifest) {
                    if (! $k8s->applyResource($manifest, $namespace)) {
                        throw new \RuntimeException("{$manifest['kind']} {$manifest['metadata']['name']} could not be applied in {$namespace}");
                    }
                }
                $server->tls_spec_hash = $hash;
            }

            [$status, $message, $notAfter] = GlobalTlsManifests::state(
                $k8s->getResourceStatus(GlobalTlsManifests::API, 'Certificate', GlobalTlsManifests::CERTIFICATE, $namespace),
            );
            $server->fill([
                'tls_status' => $status,
                'tls_message' => $message !== null ? mb_substr($message, 0, 2000) : null,
                'tls_expires_at' => $notAfter ? Carbon::parse($notAfter) : null,
            ])->save();
        } catch (Throwable $e) {
            $server->update(['tls_status' => 'error', 'tls_message' => mb_substr($e->getMessage(), 0, 2000)]);
        }
    }
}
