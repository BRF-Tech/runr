<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\Setting;
use App\Services\Contracts\KnativeConstants;
use App\Services\Dns\DnsResolver;
use App\Services\Helpers\DomainName;
use App\Services\Helpers\PemHelper;
use App\Services\Manifests\ProjectDomainManifests as M;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Custom domains per project.
 *
 * Flow: add (pending, random TXT token) → verify (TXT _runr-challenge.<domain> =
 * runr-verify=<token>, and the domain points at the project: CNAME to its default host /
 * the region's external host name, or an address of the region's external IP) → activate
 * (Traefik IngressRoutes + host-rewrite Middleware in the region's project namespace,
 * see ProjectDomainManifests). TLS per domain: none, an uploaded certificate (only ever
 * stored in a Secret on the region), or a cert-manager Certificate from the region's
 * ClusterIssuer.
 *
 * Every Kubernetes call goes through the region's stored kubeconfig.
 */
class ProjectDomainService
{
    /** Service types whose deploy creates a Knative Service. */
    private const KNATIVE_SERVICE_TYPES = ['caas', 'xaas', 'faas'];

    public function __construct(private readonly DnsResolver $dns) {}

    // ── Eligibility, targets, reserved names ────────────────────────────────

    public function triggerType(Project $project): ?string
    {
        $config = match ($project->service_type) {
            'caas' => $project->containerConfig,
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            default => null,
        };

        if (! in_array($project->service_type, self::KNATIVE_SERVICE_TYPES, true)) {
            return null;
        }

        return $config?->trigger_type ?? 'http';
    }

    /**
     * Why this project can't serve custom domains right now, or null when it can: it must
     * run a public Knative Service (HTTP trigger) on a real region with a kubeconfig.
     * Schedule-triggered projects have no Knative Service; queue-triggered ones have a
     * cluster-local one — neither has a public URL.
     */
    public function ineligibilityReason(Project $project): ?string
    {
        if (! in_array($project->service_type, self::KNATIVE_SERVICE_TYPES, true)) {
            return __('api.domain.no_knative_service');
        }

        $trigger = $this->triggerType($project);
        if ($trigger !== 'http') {
            return __('api.domain.not_http', ['trigger' => $trigger]);
        }

        $region = $project->knativeRegion;
        if (! $region) {
            return __('api.domain.no_region');
        }
        if ($region->is_demo) {
            return __('api.domain.demo_region');
        }
        if (! $region->kubeconfig) {
            return __('api.project.region_no_kubeconfig');
        }

        return null;
    }

    /** The region's function domain (knative_domain, else <region>.<base_domain>). */
    public function functionDomain(?KnativeRegion $region): ?string
    {
        if (! $region) {
            return null;
        }

        $domain = $region->effective_settings['knative_domain'] ?? null;
        if (! $domain) {
            $base = Setting::get('base_domain');
            $domain = $base ? "{$region->name}.{$base}" : null;
        }

        return $domain ? DomainName::normalize($domain) : null;
    }

    /** The project's default public host: <slug>.<function domain>. */
    public function defaultHost(Project $project): ?string
    {
        $functionDomain = $this->functionDomain($project->knativeRegion);

        return $functionDomain ? "{$project->slug}.{$functionDomain}" : null;
    }

    /**
     * DNS records a customer may create for the domain itself (any one of them):
     * CNAME to the default host, and A/AAAA (or CNAME when the load balancer has a host
     * name) to the region's external address.
     *
     * @return array<int, array{type: string, value: string}>
     */
    public function dnsTargets(Project $project): array
    {
        $targets = [];

        if ($host = $this->defaultHost($project)) {
            $targets[] = ['type' => 'CNAME', 'value' => $host];
        }

        $external = trim((string) $project->knativeRegion?->external_ip);
        if ($external !== '') {
            if (filter_var($external, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $targets[] = ['type' => 'A', 'value' => $external];
            } elseif (filter_var($external, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $targets[] = ['type' => 'AAAA', 'value' => $external];
            } else {
                $targets[] = ['type' => 'CNAME', 'value' => DomainName::normalize($external)];
            }
        }

        return $targets;
    }

    /**
     * Names a custom domain may not equal or live under: every region's function domain
     * and the console's own host names.
     *
     * @return string[]
     */
    public function reservedDomains(): array
    {
        $reserved = [];

        foreach (KnativeRegion::all() as $region) {
            $reserved[] = $this->functionDomain($region);
        }

        $reserved[] = DomainName::hostOf(config('app.url'));
        $reserved[] = DomainName::hostOf(config('app.frontend_url'));
        $reserved[] = DomainName::publicHost(request()->getHost());

        return array_values(array_unique(array_filter($reserved)));
    }

    public function isReserved(string $domain): bool
    {
        foreach ($this->reservedDomains() as $reserved) {
            if (DomainName::isWithin($domain, $reserved)) {
                return true;
            }
        }

        return false;
    }

    /** ClusterIssuer for cert-manager mode: region setting, else config. */
    public function clusterIssuer(?KnativeRegion $region): ?string
    {
        $issuer = $region?->effective_settings['cert_manager_cluster_issuer'] ?? null;
        $issuer = trim((string) ($issuer ?: config('knative.domains.cert_manager_cluster_issuer')));

        return $issuer !== '' ? $issuer : null;
    }

    // ── Add / verify / activate ─────────────────────────────────────────────

    public function add(Project $project, string $domain): ProjectDomain
    {
        /** @var ProjectDomain $record */
        $record = $project->domains()->create([
            'domain' => $domain,
            'status' => ProjectDomain::STATUS_PENDING,
            'verification_token' => bin2hex(random_bytes(16)),
            'tls_mode' => ProjectDomain::TLS_NONE,
        ]);

        return $record->setRelation('project', $project);
    }

    /**
     * Pending domain: checks DNS, then activates. Already verified domain (verified /
     * active / error): re-applies the routing and refreshes the certificate state.
     *
     * @return bool true when the domain is active afterwards
     */
    public function verify(ProjectDomain $domain): bool
    {
        $project = $domain->project;

        if ($reason = $this->ineligibilityReason($project)) {
            $domain->update(['status_message' => $reason]);

            return false;
        }

        if ($domain->status === ProjectDomain::STATUS_PENDING) {
            $problem = $this->dnsProblem($domain, $project);
            if ($problem !== null) {
                $domain->update(['status_message' => $problem]);

                return false;
            }

            $domain->update(['status' => ProjectDomain::STATUS_VERIFIED, 'status_message' => null]);
        }

        return $this->activate($domain);
    }

    /**
     * What keeps DNS from proving this domain, or null when ownership (TXT) and routing
     * (CNAME / address) both check out.
     */
    public function dnsProblem(ProjectDomain $domain, Project $project): ?string
    {
        $expected = $domain->challengeValue();

        if (! in_array($expected, $this->dns->txt($domain->challengeName()), true)) {
            return __('api.domain.txt_missing', ['name' => $domain->challengeName(), 'value' => $expected]);
        }

        if (! $this->pointsToProject($domain->domain, $project)) {
            $targets = $this->dnsTargets($project);
            if (! $targets) {
                return __('api.domain.no_target');
            }

            return __('api.domain.not_pointing', [
                'domain' => $domain->domain,
                'targets' => implode(', ', array_map(fn ($t) => "{$t['type']} {$t['value']}", $targets)),
            ]);
        }

        return null;
    }

    public function pointsToProject(string $domain, Project $project): bool
    {
        $hosts = [];
        $addresses = [];

        foreach ($this->dnsTargets($project) as $target) {
            if ($target['type'] === 'CNAME') {
                $hosts[] = $target['value'];
            } else {
                $addresses[] = $target['value'];
            }
        }

        if (! $hosts && ! $addresses) {
            return false;
        }

        if (array_intersect($this->dns->cname($domain), $hosts)) {
            return true;
        }

        foreach ($hosts as $host) {
            array_push($addresses, ...$this->dns->addresses($host));
        }

        $expected = array_filter(array_map([self::class, 'canonicalIp'], $addresses));
        $actual = array_filter(array_map([self::class, 'canonicalIp'], $this->dns->addresses($domain)));

        return (bool) array_intersect($actual, $expected);
    }

    private static function canonicalIp(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));

        return $packed === false ? null : inet_ntop($packed);
    }

    /**
     * Apply the routing (+ cert-manager Certificate) for a verified domain.
     *
     * @return bool true when the domain is active afterwards
     */
    public function activate(ProjectDomain $domain): bool
    {
        $project = $domain->project;

        if ($reason = $this->ineligibilityReason($project)) {
            // Ownership stays proven; the domain is routed again once the project can serve it
            $domain->update(['status' => ProjectDomain::STATUS_VERIFIED, 'status_message' => $reason]);

            return false;
        }

        $region = $project->knativeRegion;
        $name = M::resourceName($project->id, $domain->domain);

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $ns = $this->namespace($region);

            $this->assertNotRoutedByAnotherProject($k8s, $ns, $domain, $project);

            $message = null;
            $expiresAt = $domain->tls_expires_at;

            if ($domain->tls_mode === ProjectDomain::TLS_CERT_MANAGER) {
                $issuer = $this->clusterIssuer($region);
                if (! $issuer) {
                    throw new RuntimeException(__('api.domain.cert_manager_not_configured'));
                }
                $this->applyOrFail($k8s, M::certificate($name, $ns, $project->id, $domain->domain, $issuer), $ns);
            }

            foreach ($this->routeManifests($region, $project, $domain, $ns, $name) as $manifest) {
                $this->applyOrFail($k8s, $manifest, $ns);
            }

            if ($domain->tls_mode === ProjectDomain::TLS_CERT_MANAGER) {
                [$expiresAt, $message] = $this->certificateState($k8s, $ns, $name);
            }

            $domain->update([
                'status' => ProjectDomain::STATUS_ACTIVE,
                'status_message' => $message,
                'tls_expires_at' => $expiresAt,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('Custom domain activation failed', [
                'project' => $project->slug,
                'domain' => $domain->domain,
                'error' => $e->getMessage(),
            ]);

            $domain->update([
                'status' => ProjectDomain::STATUS_ERROR,
                'status_message' => mb_substr(__('api.domain.apply_failed', ['message' => $e->getMessage()]), 0, 2000),
            ]);

            return false;
        }
    }

    /**
     * @return array<int, array> Middleware + HTTP IngressRoute + HTTPS IngressRoute
     */
    public function routeManifests(KnativeRegion $region, Project $project, ProjectDomain $domain, string $ns, string $name): array
    {
        $traefikNs = $region->knative_serving_namespace ?? 'knative-serving';
        $secret = $domain->tls_mode === ProjectDomain::TLS_NONE ? null : ($domain->tls_secret_name ?: $name);

        return [
            M::hostRewriteMiddleware($name, $ns, $project->id, $domain->domain, M::serviceHost($project->slug, $ns)),
            M::ingressRoute($name, $ns, $project->id, $domain->domain, false, null, KnativeConstants::TRAEFIK_RELEASE, $traefikNs),
            M::ingressRoute($name, $ns, $project->id, $domain->domain, true, $secret, KnativeConstants::TRAEFIK_RELEASE, $traefikNs),
        ];
    }

    /**
     * Two projects sharing a region namespace (e.g. two tenants) must never both route one
     * host: refuse when an IngressRoute for this host belongs to another project.
     */
    private function assertNotRoutedByAnotherProject(KubernetesClient $k8s, string $ns, ProjectDomain $domain, Project $project): void
    {
        $routes = $k8s->listResources(M::TRAEFIK_API, 'IngressRoute', $ns, [M::LABEL_DOMAIN_HASH => M::domainHash($domain->domain)]);

        foreach ($routes['items'] ?? [] as $route) {
            $owner = $route['metadata']['labels'][M::LABEL_PROJECT] ?? null;
            if ($owner !== null && $owner !== $project->id) {
                throw new RuntimeException(__('api.domain.routed_elsewhere', ['domain' => $domain->domain]));
            }
        }
    }

    /**
     * @return array{0: ?Carbon, 1: ?string} [notAfter when issued, message while not ready]
     */
    private function certificateState(KubernetesClient $k8s, string $ns, string $name): array
    {
        $certificate = $k8s->getResourceStatus(M::CERT_MANAGER_API, 'Certificate', $name, $ns);

        foreach ($certificate['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') !== 'Ready') {
                continue;
            }

            if (($condition['status'] ?? '') === 'True') {
                $notAfter = $certificate['status']['notAfter'] ?? null;

                return [$notAfter ? Carbon::parse($notAfter) : null, null];
            }

            return [null, __('api.domain.certificate_pending', ['reason' => $condition['message'] ?? ''])];
        }

        return [null, __('api.domain.certificate_pending', ['reason' => ''])];
    }

    // ── TLS ─────────────────────────────────────────────────────────────────

    /**
     * Switch a domain's TLS mode. An uploaded certificate/key is written straight into the
     * domain's Secret on the region and never stored in the database.
     *
     * @throws ValidationException
     */
    public function setTls(ProjectDomain $domain, string $mode, ?string $certificatePem, ?string $privateKeyPem): ProjectDomain
    {
        $project = $domain->project;
        $region = $project->knativeRegion;

        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            throw ValidationException::withMessages(['mode' => __('api.domain.region_unavailable')]);
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $ns = $this->namespace($region);
        $name = M::resourceName($project->id, $domain->domain);
        $previous = $domain->tls_mode;

        if ($mode === ProjectDomain::TLS_CUSTOM) {
            [$certificate, $key] = $this->validatedPemPair($domain, (string) $certificatePem, (string) $privateKeyPem);

            $this->onCluster(function () use ($k8s, $ns, $name, $project, $domain, $certificate, $key, $previous) {
                if ($previous === ProjectDomain::TLS_CERT_MANAGER) {
                    $k8s->deleteResource(M::CERT_MANAGER_API, 'Certificate', $name, $ns);
                }
                $this->applyOrFail($k8s, M::tlsSecret($name, $ns, $project->id, $domain->domain, $certificate, $key), $ns);
            });

            $domain->update([
                'tls_mode' => ProjectDomain::TLS_CUSTOM,
                'tls_secret_name' => $name,
                'tls_expires_at' => PemHelper::certificateExpiresAt($certificate),
            ]);
        } elseif ($mode === ProjectDomain::TLS_CERT_MANAGER) {
            $issuer = $this->clusterIssuer($region);
            if (! $issuer) {
                throw ValidationException::withMessages(['mode' => __('api.domain.cert_manager_not_configured')]);
            }

            $available = $this->onCluster(fn () => $k8s->hasApiVersion(M::CERT_MANAGER_API)
                && $k8s->getClusterResource(M::CERT_MANAGER_API, 'ClusterIssuer', $issuer) !== null);
            if (! $available) {
                throw ValidationException::withMessages(['mode' => __('api.domain.cert_manager_unavailable', ['issuer' => $issuer])]);
            }

            if ($previous === ProjectDomain::TLS_CUSTOM) {
                // cert-manager writes a fresh Secret under the same name
                $this->onCluster(fn () => $k8s->deleteResource('v1', 'Secret', $name, $ns));
            }

            $domain->update([
                'tls_mode' => ProjectDomain::TLS_CERT_MANAGER,
                'tls_secret_name' => $name,
                'tls_expires_at' => null,
            ]);
        } else {
            $domain->update([
                'tls_mode' => ProjectDomain::TLS_NONE,
                'tls_secret_name' => null,
                'tls_expires_at' => null,
            ]);
        }

        if ($domain->status === ProjectDomain::STATUS_ACTIVE || $domain->status === ProjectDomain::STATUS_ERROR) {
            $this->activate($domain);
        }

        if ($mode === ProjectDomain::TLS_NONE && $previous !== ProjectDomain::TLS_NONE) {
            // After the routes stopped referencing the Secret
            $this->onCluster(function () use ($k8s, $ns, $name) {
                $k8s->deleteResource(M::CERT_MANAGER_API, 'Certificate', $name, $ns);
                $k8s->deleteResource('v1', 'Secret', $name, $ns);
            });
        }

        return $domain;
    }

    /**
     * @return array{0: string, 1: string} normalized certificate + key PEM
     *
     * @throws ValidationException
     */
    private function validatedPemPair(ProjectDomain $domain, string $certificatePem, string $privateKeyPem): array
    {
        try {
            $certificate = PemHelper::normalize($certificatePem, 'CERTIFICATE');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['certificate' => __('api.region.function_tls_pem_invalid', ['marker' => 'CERTIFICATE'])]);
        }

        try {
            $key = PemHelper::normalize($privateKeyPem, 'PRIVATE KEY');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['privateKey' => __('api.region.function_tls_pem_invalid', ['marker' => 'PRIVATE KEY'])]);
        }

        if (! PemHelper::keyMatchesCertificate($certificate, $key)) {
            throw ValidationException::withMessages(['privateKey' => __('api.domain.key_mismatch')]);
        }

        if (! PemHelper::certificateCoversHost($certificate, $domain->domain)) {
            throw ValidationException::withMessages(['certificate' => __('api.domain.certificate_host_mismatch', ['domain' => $domain->domain])]);
        }

        return [$certificate, $key];
    }

    // ── Removal & lifecycle ─────────────────────────────────────────────────

    /**
     * Delete everything RunR created on the region for this domain: both IngressRoutes, the
     * Middleware, the cert-manager Certificate and the TLS Secret (names derived from
     * project id + domain, so nothing of another project is touched). 404 = already gone.
     *
     * @return string[] objects that could not be deleted (empty = success)
     */
    public function removeFromRegion(ProjectDomain $domain, ?KnativeRegion $region = null): array
    {
        $project = $domain->project;
        $region ??= $project?->knativeRegion;

        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return [];
        }

        // Never applied anywhere: added (or moved to another region) and not verified since
        if ($domain->status === ProjectDomain::STATUS_PENDING && ! $domain->tls_secret_name) {
            return [];
        }

        $name = M::resourceName($domain->project_id, $domain->domain);

        return $this->deleteAll($region, [
            [M::TRAEFIK_API, 'IngressRoute', M::secureRouteName($name)],
            [M::TRAEFIK_API, 'IngressRoute', $name],
            [M::TRAEFIK_API, 'Middleware', $name],
            [M::CERT_MANAGER_API, 'Certificate', $name],
            ['v1', 'Secret', $name],
        ]);
    }

    /**
     * Project deletion: remove every domain's objects from the region. Failures are
     * logged; the rows go with the project (FK cascade).
     */
    /**
     * @return list<string> domains whose region objects could not all be removed
     */
    public function removeAllForProject(Project $project): array
    {
        $incomplete = [];

        foreach ($project->domains()->get() as $domain) {
            $domain->setRelation('project', $project);
            $failed = $this->removeFromRegion($domain);
            if ($failed) {
                $incomplete[] = $domain->domain;
                Log::warning('Custom domain cleanup incomplete during project delete', [
                    'project' => $project->slug,
                    'domain' => $domain->domain,
                    'failed' => $failed,
                ]);
            }
        }

        return $incomplete;
    }

    /**
     * The project moved to another region (or was detached): remove its domains from the
     * old region and send them back to pending — the DNS target changed. An uploaded
     * certificate lived only in the old region's Secret, so custom TLS falls back to none.
     */
    public function regionChanged(Project $project, ?string $oldRegionId): void
    {
        $domains = $project->domains()->get();
        if ($domains->isEmpty()) {
            return;
        }

        $oldRegion = $oldRegionId ? KnativeRegion::find($oldRegionId) : null;

        foreach ($domains as $domain) {
            $domain->setRelation('project', $project);

            if ($oldRegion) {
                $failed = $this->removeFromRegion($domain, $oldRegion);
                if ($failed) {
                    Log::warning('Custom domain cleanup incomplete after region change', [
                        'project' => $project->slug,
                        'domain' => $domain->domain,
                        'failed' => $failed,
                    ]);
                }
            }

            $custom = $domain->tls_mode === ProjectDomain::TLS_CUSTOM;

            $domain->update([
                'status' => ProjectDomain::STATUS_PENDING,
                'status_message' => __('api.domain.region_changed'),
                'tls_mode' => $custom ? ProjectDomain::TLS_NONE : $domain->tls_mode,
                'tls_secret_name' => $custom ? null : $domain->tls_secret_name,
                'tls_expires_at' => null,
            ]);
        }
    }

    /**
     * After a deploy: a project that is no longer HTTP-triggered stops being routed (a queue
     * trigger makes its Knative Service cluster-local — it must not stay reachable through a
     * custom domain); an HTTP-triggered one gets its verified domains routed again.
     */
    public function syncAfterDeploy(Project $project): void
    {
        $domains = $project->domains()->get();
        if ($domains->isEmpty()) {
            return;
        }

        $reason = $this->ineligibilityReason($project);

        foreach ($domains as $domain) {
            $domain->setRelation('project', $project);

            if ($reason !== null) {
                if ($domain->status === ProjectDomain::STATUS_ACTIVE || $domain->status === ProjectDomain::STATUS_ERROR) {
                    $name = M::resourceName($project->id, $domain->domain);
                    $failed = $project->knativeRegion ? $this->deleteAll($project->knativeRegion, [
                        [M::TRAEFIK_API, 'IngressRoute', M::secureRouteName($name)],
                        [M::TRAEFIK_API, 'IngressRoute', $name],
                        [M::TRAEFIK_API, 'Middleware', $name],
                    ]) : [];

                    $domain->update([
                        'status' => $failed ? ProjectDomain::STATUS_ERROR : ProjectDomain::STATUS_VERIFIED,
                        'status_message' => $failed
                            ? __('api.domain.remove_failed', ['objects' => implode(', ', $failed)])
                            : $reason,
                    ]);
                }

                continue;
            }

            if ($domain->status === ProjectDomain::STATUS_VERIFIED || $domain->status === ProjectDomain::STATUS_ERROR) {
                $this->activate($domain);
            }
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    public function namespace(KnativeRegion $region): string
    {
        return $region->project_namespace ?? $region->name;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $targets  [apiVersion, kind, name]
     * @return string[] failures
     */
    private function deleteAll(KnativeRegion $region, array $targets): array
    {
        $failed = [];

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $ns = $this->namespace($region);

            foreach ($targets as [$apiVersion, $kind, $name]) {
                try {
                    if (! $k8s->deleteResource($apiVersion, $kind, $name, $ns)) {
                        $failed[] = "{$kind}/{$name}";
                    }
                } catch (Throwable $e) {
                    $failed[] = "{$kind}/{$name}: {$e->getMessage()}";
                }
            }
        } catch (Throwable $e) {
            $failed[] = $e->getMessage();
        }

        return $failed;
    }

    private function applyOrFail(KubernetesClient $k8s, array $manifest, string $ns): void
    {
        if (! $k8s->applyResource($manifest, $ns)) {
            throw new RuntimeException("{$manifest['kind']}/{$manifest['metadata']['name']} was rejected in {$ns}");
        }
    }

    /**
     * Run a cluster call; a failure becomes a validation error on "mode".
     *
     * @throws ValidationException
     */
    private function onCluster(callable $call): mixed
    {
        try {
            return $call();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['mode' => __('api.domain.tls_apply_failed', ['message' => $e->getMessage()])]);
        }
    }
}
