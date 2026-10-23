<?php

namespace App\Services\GlobalProjects;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectRegion;
use App\Models\RegionGlobalDns;
use App\Services\CertManager\CertManagerService;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalDns\GlobalDnsZone;
use App\Services\GlobalDns\GlobalRegionSetup;
use App\Services\GlobalDns\GlobalTlsManifests;
use App\Services\KubernetesClient;
use App\Services\Manifests\ProjectDomainManifests as M;
use App\Services\ProjectDomainService;
use App\Services\PublicHostRewrites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Custom domains of a global project (docs/global-projects.md, "Custom domains"): served by every
 * region, answered by the global DNS.
 *
 *  - DNS: the domain is a CNAME of the project's global host name <slug>.<zone> (or, at a zone
 *    apex, A / AAAA records of the regions' addresses); the ownership TXT record as for any domain;
 *  - routes: every region whose copy serves gets the domain's Middleware and IngressRoutes
 *    (ProjectDomainService::routeManifests, the region's own public host of the copy);
 *  - TLS (cert-manager): every region issues the domain's certificate itself, through the zone's
 *    DNS-01 Issuer runr-global-dns — the owner points _acme-challenge.<domain> at
 *    challengeTarget(), cert-manager follows the CNAME and writes the challenge into the global
 *    zone, whichever region the ACME CA would reach over HTTP. Without the global TLS (mode none, a
 *    region's cert-manager not ready) the region's ClusterIssuer is used (HTTP-01: it succeeds in
 *    the region the CA's request lands in);
 *  - TLS (custom): the uploaded certificate's Secret is copied from the primary to every region.
 *
 * The state of each region is in project_domains.region_states.
 */
class GlobalDomains
{
    public function __construct(
        private readonly ProjectDomainService $domains,
        private readonly GlobalDnsZone $zone,
    ) {}

    /**
     * The name under the global zone _acme-challenge.<domain> points at (a CNAME), null without a
     * zone: _acme-challenge.d-<20 hex of sha1(domain)>.<zone>.
     */
    public function challengeTarget(string $domain): ?string
    {
        $zone = $this->zone->name();

        return $zone === null ? null : '_acme-challenge.d-'.substr(sha1(strtolower(rtrim($domain, '.'))), 0, 20).'.'.$zone;
    }

    /**
     * Records the owner may create for the domain: CNAME to the global host name, or A / AAAA
     * records of every region's address (an apex cannot be a CNAME; the answers then do not follow
     * the health checks).
     *
     * @return list<array{type: string, value: string}>
     */
    public function dnsTargets(Project $project): array
    {
        $targets = [];
        if ($host = $this->zone->hostFor((string) $project->slug)) {
            $targets[] = ['type' => 'CNAME', 'value' => $host];
        }

        $servers = app(GlobalDnsServerService::class);
        foreach ($this->regionsOf($project) as $region) {
            foreach ($servers->addressesOf($region->external_ip) as $address) {
                $targets[] = ['type' => str_contains($address, ':') ? 'AAAA' : 'A', 'value' => $address];
            }
        }

        return array_values(array_unique($targets, SORT_REGULAR));
    }

    /**
     * Route (and certify) a verified domain in every region whose copy serves. Active when the
     * primary has it; the other regions' problems are in region_states and the status message.
     */
    public function activate(ProjectDomain $domain): bool
    {
        $project = $domain->project;
        $states = (array) ($domain->region_states ?? []);
        $primaryOk = false;
        $problems = [];
        $expiresAt = null;

        foreach ($this->regionsOf($project) as $region) {
            $primary = (string) $region->getKey() === (string) $project->knative_region_id;
            try {
                [$expires, $message] = $this->applyDomain($project, $domain, $region);
                $states[(string) $region->getKey()] = ['status' => 'active', 'message' => $message, 'tls_expires_at' => $expires?->toIso8601String()];
                if ($primary) {
                    $primaryOk = true;
                    $expiresAt = $expires;
                }
                if ($message !== null) {
                    $problems[] = "{$region->name}: {$message}";
                }
            } catch (Throwable $e) {
                Log::warning('Custom domain of a global project not applied in a region', ['project' => $project->slug, 'domain' => $domain->domain, 'region' => $region->name, 'error' => $e->getMessage()]);
                $states[(string) $region->getKey()] = ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 500), 'tls_expires_at' => null];
                $problems[] = "{$region->name}: ".mb_substr($e->getMessage(), 0, 300);
            }
        }

        if ($domain->tls_mode === ProjectDomain::TLS_CERT_MANAGER && ($target = $this->challengeTarget($domain->domain))) {
            array_unshift($problems, __('api.global.domain_challenge_hint', ['name' => '_acme-challenge.'.$domain->domain, 'target' => $target]));
        }

        $domain->update([
            'status' => $primaryOk ? ProjectDomain::STATUS_ACTIVE : ProjectDomain::STATUS_ERROR,
            'status_message' => $problems === [] ? null : mb_substr(implode(' ', $problems), 0, 2000),
            'tls_expires_at' => $expiresAt ?? $domain->tls_expires_at,
            'region_states' => $states,
        ]);

        return $primaryOk;
    }

    /** A region's copy became ready: the project's routed domains answer there too. */
    public function applyRegion(Project $project, KnativeRegion $region, ?KubernetesClient $k8s = null): void
    {
        // the project's own domains (an environment's runs in its own region)
        $domains = ProjectDomain::query()->where('project_id', $project->getKey())->whereNull('environment_id')
            ->whereIn('status', [ProjectDomain::STATUS_ACTIVE, ProjectDomain::STATUS_ERROR])->get();
        foreach ($domains as $domain) {
            $domain->setRelation('project', $project);
            $states = (array) ($domain->region_states ?? []);
            try {
                [$expires, $message] = $this->applyDomain($project, $domain, $region, $k8s);
                $states[(string) $region->getKey()] = ['status' => 'active', 'message' => $message, 'tls_expires_at' => $expires?->toIso8601String()];
            } catch (Throwable $e) {
                $states[(string) $region->getKey()] = ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 500), 'tls_expires_at' => null];
            }
            $domain->update(['region_states' => $states]);
        }
    }

    /** The project left a region: its domains' objects there go. */
    public function removeRegion(Project $project, KnativeRegion $region, ?KubernetesClient $k8s = null): bool
    {
        $ok = true;
        foreach (ProjectDomain::query()->where('project_id', $project->getKey())->whereNull('environment_id')->get() as $domain) {
            $domain->setRelation('project', $project);
            $ok = $this->domains->removeFromRegion($domain, $region) === [] && $ok;
            $states = (array) ($domain->region_states ?? []);
            unset($states[(string) $region->getKey()]);
            $domain->update(['region_states' => $states === [] ? null : $states]);
        }

        return $ok;
    }

    /**
     * Every region of the project (removed domain, project deleted): the objects of the domain.
     *
     * @return list<string> what could not be removed
     */
    public function removeEverywhere(ProjectDomain $domain): array
    {
        $failed = [];
        foreach ($this->regionsOf($domain->project, true) as $region) {
            array_push($failed, ...$this->domains->removeFromRegion($domain, $region));
        }

        return $failed;
    }

    /**
     * An uploaded certificate: written by ProjectDomainService::setTls into the primary, copied
     * from there into every other region.
     */
    public function copyCustomTls(ProjectDomain $domain): void
    {
        $project = $domain->project;
        $primary = $project->knativeRegion;
        if (! $primary?->kubeconfig) {
            return;
        }
        $name = M::resourceName($project->id, $domain->domain);
        $source = (new KubernetesClient((string) $primary->kubeconfig))->getResourceStatus('v1', 'Secret', $name, GlobalRegionSetup::projectNamespace($primary));
        if (! isset($source['data']['tls.crt'], $source['data']['tls.key'])) {
            return;
        }

        foreach ($this->regionsOf($project) as $region) {
            if ((string) $region->getKey() === (string) $primary->getKey()) {
                continue;
            }
            $this->copySecret($source, $region, $name);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Route and certify the domain in one region. Returns [the certificate's notAfter, why it is
     * not issued yet].
     *
     * @return array{0: ?Carbon, 1: ?string}
     */
    private function applyDomain(Project $project, ProjectDomain $domain, KnativeRegion $region, ?KubernetesClient $k8s = null): array
    {
        if ($region->is_demo || ! $region->kubeconfig) {
            throw new RuntimeException(__('api.global.region_unavailable'));
        }

        $k8s ??= new KubernetesClient((string) $region->kubeconfig);
        $ns = GlobalRegionSetup::projectNamespace($region);
        $name = M::resourceName($project->id, $domain->domain);
        $regional = (clone $project)->setRelation('knativeRegion', $region);
        $regional->setAttribute('knative_region_id', $region->getKey());
        // two projects sharing the region's namespace never both route one host
        $this->domains->assertNotRoutedByAnotherProject($k8s, $ns, $domain, $project);

        $expires = null;
        $message = null;
        if ($domain->tls_mode === ProjectDomain::TLS_CERT_MANAGER) {
            $certificate = $this->certificate($region, $k8s, $ns, $name, $project, $domain);
            if (! $k8s->applyResource($certificate, $ns)) {
                throw new RuntimeException("Certificate/{$name} was rejected in {$ns}");
            }
            [$status, $reason, $notAfter] = GlobalTlsManifests::state($k8s->getResourceStatus(M::CERT_MANAGER_API, 'Certificate', $name, $ns));
            $expires = $notAfter ? Carbon::parse($notAfter) : null;
            $message = $status === 'ready' ? null : __('api.domain.certificate_pending', ['reason' => (string) $reason]);
        } elseif ($domain->tls_mode === ProjectDomain::TLS_CUSTOM && (string) $region->getKey() !== (string) $project->knative_region_id) {
            $primary = $project->knativeRegion;
            $source = $primary?->kubeconfig ? (new KubernetesClient((string) $primary->kubeconfig))->getResourceStatus('v1', 'Secret', $name, GlobalRegionSetup::projectNamespace($primary)) : null;
            if ($source !== null) {
                $this->copySecret($source, $region, $name, $k8s);
            }
        }

        $publicHost = PublicHostRewrites::lookup($k8s, $ns, (string) $project->slug, $this->domains->defaultHost($regional));
        foreach ($this->domains->routeManifests($region, $regional, $domain, $ns, $name, $publicHost) as $manifest) {
            if (! $k8s->applyResource($manifest, $ns)) {
                throw new RuntimeException("{$manifest['kind']}/{$manifest['metadata']['name']} was rejected in {$ns}");
            }
        }

        return [$expires, $message];
    }

    /**
     * The domain's Certificate in a region: the zone's DNS-01 Issuer when the region runs the
     * global TLS (it follows the owner's _acme-challenge CNAME), else the region's ClusterIssuer.
     */
    private function certificate(KnativeRegion $region, KubernetesClient $k8s, string $ns, string $name, Project $project, ProjectDomain $domain): array
    {
        $global = config('global_dns.tls.mode', 'acme') === 'acme'
            && RegionGlobalDns::forRegion($region)?->tls_status === 'ready'
            && $k8s->getResourceStatus(GlobalTlsManifests::API, 'Issuer', GlobalTlsManifests::ISSUER, $ns) !== null;

        if (! $global) {
            $issuer = app(CertManagerService::class)->effectiveIssuer($region)
                ?? throw new RuntimeException(__('api.domain.cert_manager_not_configured'));

            return M::certificate($name, $ns, (string) $project->id, $domain->domain, $issuer);
        }

        $certificate = M::certificate($name, $ns, (string) $project->id, $domain->domain, GlobalTlsManifests::ISSUER);
        $certificate['spec']['issuerRef']['kind'] = 'Issuer';

        return $certificate;
    }

    private function copySecret(array $source, KnativeRegion $region, string $name, ?KubernetesClient $k8s = null): void
    {
        $k8s ??= new KubernetesClient((string) $region->kubeconfig);
        $ns = GlobalRegionSetup::projectNamespace($region);
        $k8s->applyResource([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => $name, 'namespace' => $ns, 'labels' => (array) ($source['metadata']['labels'] ?? [])],
            'type' => 'kubernetes.io/tls',
            'data' => ['tls.crt' => $source['data']['tls.crt'], 'tls.key' => $source['data']['tls.key']],
        ], $ns);
    }

    /**
     * The regions a global project's domains are served in: the primary and every region whose
     * copy serves ($all: every region of the project).
     *
     * @return list<KnativeRegion>
     */
    private function regionsOf(Project $project, bool $all = false): array
    {
        $regions = [];
        foreach (GlobalProjectRegions::of($project) as $region) {
            $primary = (string) $region->getKey() === (string) $project->knative_region_id;
            if (! $all && ! $primary) {
                $row = ProjectRegion::query()->where('project_id', $project->getKey())->where('knative_region_id', $region->getKey())->first();
                if (! $row || ! $row->isServing()) {
                    continue;
                }
            }
            $regions[] = $region;
        }

        return $regions;
    }
}
