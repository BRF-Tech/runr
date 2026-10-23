<?php

namespace App\Services\CertManager;

use App\Events\PlatformEvent;
use App\Jobs\InstallCertManagerJob;
use App\Models\KnativeRegion;
use App\Models\ProjectDomain;
use App\Services\Dns\DnsResolver;
use App\Services\HelmResult;
use App\Services\HelmService;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KnativeInstallService;
use App\Services\KubernetesClient;
use App\Services\Manifests\ProjectDomainManifests;
use App\Services\Observability\OutboundUrlGuard;
use App\Support\Images\ImageReference;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * cert-manager of a region (docs/regions.md#cert-manager) and the ClusterIssuer the custom
 * domains in TLS mode cert-manager are issued by.
 *
 * Install (install()): with Knative Serving (installWithServing(), never fails it), from the
 * console / API (POST /knative-regions/{id}/cert-manager), from region:reconcile (CertManagerStep).
 *  - a cert-manager RunR did not install (any namespace, its ClusterIssuer CRD or its controller
 *    Deployment) is reused as it is: never a second one, never removed by RunR (managed false);
 *  - none: RunR's Helm release (CertManager::helmValues(), the pinned chart, images from the reqs
 *    mirror) — unless Helm knative.certManager.install is false; RunR's own release of another
 *    chart version or images is brought to this release's;
 *  - once its Deployments are available: the default ClusterIssuer (ensureIssuer()); a cert-manager
 *    still starting leaves the region "installing", and refresh() (the cert-manager status read,
 *    the region status check) finishes it.
 *
 * Issuer (ensureIssuer()): the operator's ClusterIssuer when the region setting
 * cert_manager_cluster_issuer names one (or RUNR_CERT_MANAGER_CLUSTER_ISSUER, Helm extraEnv) —
 * RunR then creates none; otherwise RunR's, recorded in that setting:
 *  - runr-acme (ACME, Let's Encrypt, HTTP-01 through the region's Traefik) when the region is
 *    reachable from the internet (its external address is public) and an ACME e-mail is
 *    configured — region setting cert_manager_acme_email, else Helm knative.certManager.acmeEmail;
 *    the e-mail is the operator's agreement to the ACME server's terms, so RunR never takes one on
 *    its own (not the administrator's account address);
 *  - runr-ca (a region CA: selfSigned → CA certificate → CA ClusterIssuer) otherwise, so internal
 *    regions still get certificates from a CA the operator can trust (caCertificate());
 *  - cert_manager_issuer_mode acme / ca forces one; in auto an ACME account the ACME server refuses
 *    falls back to the CA (the reason is logged).
 * The effective issuer is also stored in cert_manager_issuer: the tenants' copies of a platform
 * region read it (they do not carry the region's settings).
 *
 * Removal (uninstall()): refused while the region's custom domains have cert-manager certificates;
 * RunR's issuers and RunR's release go only when nothing else on the cluster uses them; a
 * cert-manager RunR did not install always stays.
 */
class CertManagerService
{
    public const STATUS_NOT_INSTALLED = 'not_installed';

    public const STATUS_INSTALLING = 'installing';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    /**
     * Tries (5 s apart) of an apply the webhook may refuse while its CA is injected: measured on
     * Kind, a reinstall (new webhook CA, kept CRDs) took over 30 s.
     */
    private const WEBHOOK_TRIES = 24;

    /** Between DELETE …/cert-manager and the end of its job */
    public const STATUS_UNINSTALLING = 'uninstalling';

    /** Seconds an install waits for cert-manager's Deployments before refresh() takes over */
    public const WAIT_SECONDS = 150;

    /** Seconds the default issuer gets to report Ready (an ACME account registers in that time) */
    public const ISSUER_WAIT_SECONDS = 30;

    /** Seconds between two background refreshes of an "installing" region (queueRefresh()) */
    public const REFRESH_EVERY = 20;

    /** @var (callable(string): void)|null */
    private $log = null;

    /** The region whose step log (cert_manager_status_message) the lines go to */
    private ?KnativeRegion $logRegion = null;

    public function __construct(private readonly DnsResolver $dns) {}

    // ── Detection ───────────────────────────────────────────────────────────

    /**
     * cert-manager on the cluster: present (a controller Deployment, or cert-manager's CRDs that
     * are not RunR's — RunR's stay when its release goes, crds.keep), RunR's own release (label
     * app.kubernetes.io/part-of=runr on the controller), whether a controller runs, where, its
     * version (the controller image's tag) and whether its Deployments are all available.
     *
     * @return array{present: bool, runr: bool, controller: bool, namespace: ?string, version: ?string, available: bool}
     */
    public function detect(KubernetesClient $k8s): array
    {
        $crd = $k8s->getClusterResource('apiextensions.k8s.io/v1', 'CustomResourceDefinition', 'clusterissuers.cert-manager.io');
        $controllers = $k8s->listClusterResources('apps/v1', 'Deployment', [
            'app.kubernetes.io/name' => 'cert-manager',
            'app.kubernetes.io/component' => 'controller',
        ])['items'] ?? [];

        $controller = null;
        foreach ($controllers as $deployment) {
            if (self::isRunr($deployment)) {
                $controller = $deployment;
                break;
            }
        }
        $controller ??= $controllers[0] ?? null;

        if ($controller === null) {
            // CRDs left by RunR's removed release do not count; others mean a cert-manager without
            // a controller RunR can find (install() says so rather than installing a second one)
            $runrCrds = ($crd['metadata']['labels']['app.kubernetes.io/part-of'] ?? null) === 'runr';

            return ['present' => $crd !== null && ! $runrCrds, 'runr' => false, 'controller' => false, 'namespace' => null, 'version' => null, 'available' => false];
        }

        $namespace = (string) ($controller['metadata']['namespace'] ?? CertManager::NAMESPACE);
        $instance = $controller['metadata']['labels']['app.kubernetes.io/instance'] ?? null;
        $deployments = $k8s->listResources('apps/v1', 'Deployment', $namespace, $instance ? ['app.kubernetes.io/instance' => $instance] : []);

        return [
            'present' => true,
            'runr' => self::isRunr($controller),
            'controller' => true,
            'namespace' => $namespace,
            'version' => self::version($controller),
            'available' => KnativeInstallService::deploymentsAvailable($deployments),
        ];
    }

    /**
     * Whether cert-manager runs in the region as RunR set it up or reuses it — what other features
     * that issue certificates there (their own namespaced Issuers) rely on, instead of asking the
     * cluster whether cert-manager.io/v1 is served.
     */
    public function isReady(?KnativeRegion $region): bool
    {
        return $region !== null && ($region->cert_manager_status ?? null) === self::STATUS_READY;
    }

    /** RunR's release: the chart renders global.commonLabels (app.kubernetes.io/part-of=runr) on everything */
    public static function isRunr(array $deployment): bool
    {
        return ($deployment['metadata']['labels']['app.kubernetes.io/part-of'] ?? null) === 'runr';
    }

    /** The tag of the Deployment's first container image (cert-manager tags its images with its version) */
    public static function version(array $deployment): ?string
    {
        $image = (string) ($deployment['spec']['template']['spec']['containers'][0]['image'] ?? '');
        $tag = $image !== '' ? (ImageReference::split($image)['tag'] ?? '') : '';
        $tag = explode('@', $tag)[0];

        return $tag !== '' ? $tag : ($deployment['metadata']['labels']['app.kubernetes.io/version'] ?? null);
    }

    /**
     * What RunR's release runs of another image than this release's (the pinned chart's images from
     * the mirror, or the overrides): "controller image a → b", or a Deployment that is missing.
     *
     * @return list<string>
     */
    public function drift(KubernetesClient $k8s, string $namespace = CertManager::NAMESPACE): array
    {
        $drift = [];
        foreach (CertManager::DEPLOYMENTS as $component => $name) {
            $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', $name, $namespace);
            if (! $deployment) {
                $drift[] = "{$component} missing";

                continue;
            }
            $image = (string) ($deployment['spec']['template']['spec']['containers'][0]['image'] ?? '');
            $wanted = CertManager::image($component);
            if ($image === '' || ! ImageReference::same($image, $wanted)) {
                $drift[] = "{$component} image ".($image !== '' ? $image : '(unknown)')." → {$wanted}";
            }
        }

        return $drift;
    }

    // ── Install ─────────────────────────────────────────────────────────────

    /**
     * Hook of KnativeInstallService::installServing(): cert-manager with Knative Serving, without
     * waiting for it to start — the region's status check (CheckKnativeStatusJob, every minute)
     * and the cert-manager status read finish it (refresh()). Never fails the Serving install: an
     * error is logged and stored on the region's cert-manager status.
     */
    public function installWithServing(KnativeRegion $region, KubernetesClient $k8s, ?callable $log = null): void
    {
        try {
            $this->install($region, $log, $k8s, 0);
        } catch (Throwable $e) {
            if ($log) {
                $log('cert-manager install failed: '.$e->getMessage());
            }
            Log::warning('cert-manager install failed', ['region' => $region->name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Install cert-manager or reuse the one the cluster runs, then the default issuer. The region
     * stays "installing" while cert-manager starts (refresh() finishes it).
     *
     * @throws Throwable the install failed (status error is stored)
     */
    public function install(KnativeRegion $region, ?callable $log = null, ?KubernetesClient $k8s = null, int $waitSeconds = self::WAIT_SECONDS): KnativeRegion
    {
        if (! $region->kubeconfig || $region->is_demo) {
            throw new RuntimeException(__('api.cert_manager.no_cluster'));
        }

        $k8s ??= new KubernetesClient($region->kubeconfig);
        $region->update(['cert_manager_status' => self::STATUS_INSTALLING, 'cert_manager_status_message' => '']);
        $this->logTo($region, $log);

        try {
            $found = $this->detect($k8s);

            if ($found['present'] && ! $found['controller']) {
                throw new RuntimeException(__('api.cert_manager.crds_without_controller'));
            }

            if ($found['present'] && ! $found['runr']) {
                $this->say('cert-manager '.($found['version'] ?? '').' already runs'.($found['namespace'] ? " in {$found['namespace']}" : '')
                    .' and was not installed by RunR: reused as it is (RunR installs no second one and never removes it).');
                $region->update([
                    'cert_manager_managed' => false,
                    'cert_manager_namespace' => $found['namespace'] ?? CertManager::NAMESPACE,
                    'cert_manager_version' => $found['version'],
                ]);
            } elseif (! $found['present'] && ! CertManager::installEnabled()) {
                $this->say('The cluster runs no cert-manager and this platform does not install it (Helm value knative.certManager.install is false). Install cert-manager in the cluster and choose Install again to use it.');
                $region->update(['cert_manager_status' => self::STATUS_NOT_INSTALLED, 'cert_manager_managed' => null, 'cert_manager_version' => null]);

                return $region->fresh();
            } else {
                $drift = $found['runr'] ? $this->drift($k8s, $found['namespace'] ?? CertManager::NAMESPACE) : [];
                if (! $found['runr'] || $drift !== []) {
                    if ($drift !== []) {
                        $this->say("RunR's cert-manager differs from this release: ".implode('; ', $drift).'.');
                    }
                    $this->helmInstall($region, $k8s);
                } else {
                    $this->say("RunR's cert-manager ".($found['version'] ?? CertManager::chartVersion())." already runs in {$found['namespace']}: nothing to install.");
                }
                $region->update([
                    'cert_manager_managed' => true,
                    'cert_manager_namespace' => CertManager::NAMESPACE,
                    'cert_manager_version' => CertManager::chartVersion(),
                ]);
            }

            if (! $this->waitAvailable($k8s, $waitSeconds)) {
                $this->say('cert-manager is starting (its images are being pulled): the default ClusterIssuer follows as soon as it runs.');

                return $region->fresh();
            }

            return $this->finish($region, $k8s);
        } catch (Throwable $e) {
            $this->say('ERROR: '.$e->getMessage());
            $region->update(['cert_manager_status' => self::STATUS_ERROR]);
            Log::warning('cert-manager install failed', ['region' => $region->name, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * refresh() of an "installing" region in the background (InstallCertManagerJob), at most once
     * every REFRESH_EVERY seconds per region: the status check and the status read call it.
     */
    public function queueRefresh(KnativeRegion $region): void
    {
        if (($region->cert_manager_status ?? null) !== self::STATUS_INSTALLING || ! $region->kubeconfig || $region->is_demo) {
            return;
        }

        if (Cache::add('cert-manager:refresh:'.$region->id, true, self::REFRESH_EVERY)) {
            InstallCertManagerJob::dispatch($region, InstallCertManagerJob::REFRESH);
        }
    }

    /**
     * An "installing" region whose cert-manager now runs gets its issuer and becomes ready. Quiet:
     * a cluster that does not answer leaves the region as it is.
     */
    public function refresh(KnativeRegion $region, ?KubernetesClient $k8s = null): KnativeRegion
    {
        if ($region->cert_manager_status !== self::STATUS_INSTALLING || ! $region->kubeconfig || $region->is_demo) {
            return $region;
        }

        try {
            $k8s ??= new KubernetesClient($region->kubeconfig);
            if (! $this->detect($k8s)['available']) {
                return $region;
            }
            $this->logTo($region, null);
            $this->say('cert-manager runs.');

            return $this->finish($region, $k8s);
        } catch (Throwable $e) {
            Log::info('cert-manager status refresh skipped', ['region' => $region->name, 'error' => $e->getMessage()]);

            return $region;
        }
    }

    /**
     * An issuer setting changed (cert_manager_cluster_issuer, cert_manager_acme_email,
     * cert_manager_issuer_mode): the effective issuer again, created where it is RunR's. A region
     * whose cert-manager does not run yet only records an operator's issuer; its install creates
     * the rest.
     */
    public function applyIssuer(KnativeRegion $region, ?KubernetesClient $k8s = null, ?callable $log = null): KnativeRegion
    {
        $region = $region->fresh() ?? $region;

        if ($region->cert_manager_status !== self::STATUS_READY || ! $region->kubeconfig) {
            return $this->recordIssuer($region);
        }

        $this->logTo($region, $log);
        $this->say('The issuer settings changed: choosing the ClusterIssuer again.');

        return $this->finish($region, $k8s ?? new KubernetesClient($region->kubeconfig));
    }

    /**
     * The issuer column after a settings change, without the cluster: an operator's issuer as it
     * is set; RunR's (or none) as it was — applyIssuer() chooses RunR's again where cert-manager runs.
     * An operator's issuer that is no longer set gives way to the region CA's where cert-manager
     * runs (until the job chooses again), none elsewhere.
     */
    public function recordIssuer(KnativeRegion $region): KnativeRegion
    {
        $own = $this->operatorIssuer($region);
        $current = $region->cert_manager_issuer;
        $runr = match (true) {
            CertManager::isRunrIssuer($current) => $current,
            $current !== null && $current !== '' && $region->cert_manager_status === self::STATUS_READY => CertManager::CA_ISSUER,
            default => null,
        };
        $region->update(['cert_manager_issuer' => $own ?? $runr]);

        return $region->fresh() ?? $region;
    }

    private function helmInstall(KnativeRegion $region, KubernetesClient $k8s): void
    {
        $helm = app(HelmService::class);

        if (! $k8s->getClusterResource('v1', 'Namespace', CertManager::NAMESPACE)) {
            $k8s->applyClusterResource([
                'apiVersion' => 'v1',
                'kind' => 'Namespace',
                'metadata' => ['name' => CertManager::NAMESPACE, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            ]);
        }

        $result = $helm->repoAdd(CertManager::REPO, CertManager::REPO_URL);
        if (! $result->success) {
            throw new RuntimeException('Helm repo add failed: '.$result->error);
        }
        $helm->repoUpdate();

        $this->say('Installing cert-manager '.CertManager::chartVersion().' (chart '.CertManager::CHART.', images from '.CertManager::imagePrefix().')...');
        $result = HelmService::retryTransient(
            fn () => $helm->install(
                release: CertManager::RELEASE,
                chart: CertManager::CHART,
                namespace: CertManager::NAMESPACE,
                setValues: [],
                kubeconfigYaml: (string) $region->kubeconfig,
                version: CertManager::chartVersion(),
                valuesArray: CertManager::helmValues(),
                wait: false,
            ),
            fn (HelmResult $failed, int $next, int $wait) => $this->say(
                "Helm install hit a transient Kubernetes API error ({$failed->errorLine()}): try {$next} of ".HelmService::TRANSIENT_TRIES." in {$wait} s...",
            ),
        );
        if (! $result->success) {
            throw new RuntimeException('cert-manager: '.$result->error);
        }
        $this->say('cert-manager Helm release applied (namespace '.CertManager::NAMESPACE.', CRDs kept when the release is removed).');
    }

    private function waitAvailable(KubernetesClient $k8s, int $seconds): bool
    {
        for ($waited = 0; ; $waited += 5) {
            try {
                if ($this->detect($k8s)['available']) {
                    return true;
                }
            } catch (Throwable) {
                // the API server may answer slowly while the CRDs register
            }
            if ($waited >= $seconds) {
                return false;
            }
            Sleep::for(5)->seconds();
        }
    }

    /**
     * cert-manager runs: the issuer, then the region's status (ready, or error when the issuer
     * cannot be used).
     */
    private function finish(KnativeRegion $region, KubernetesClient $k8s): KnativeRegion
    {
        [$issuer, $problem] = $this->ensureIssuer($region, $k8s);

        if ($problem !== null) {
            $this->say('ERROR: '.$problem);
        } else {
            $this->say("cert-manager is ready; custom domains in TLS mode cert-manager are issued by ClusterIssuer {$issuer}.");
        }

        $region->update(['cert_manager_status' => $problem === null ? self::STATUS_READY : self::STATUS_ERROR]);

        return $region->fresh();
    }

    // ── Issuer ──────────────────────────────────────────────────────────────

    /**
     * The ClusterIssuer of the region's custom domains as configured: the region setting, else the
     * issuer RunR recorded for the region (the tenants' copies of a platform region carry only
     * that), else RUNR_CERT_MANAGER_CLUSTER_ISSUER.
     */
    public function effectiveIssuer(?KnativeRegion $region): ?string
    {
        $issuer = $region?->effective_settings['cert_manager_cluster_issuer'] ?? null;
        $issuer = $issuer ?: ($region->cert_manager_issuer ?? null);
        $issuer = trim((string) ($issuer ?: config('knative.domains.cert_manager_cluster_issuer')));

        return $issuer !== '' ? $issuer : null;
    }

    /** The operator's own ClusterIssuer: the region setting unless it names RunR's, else the platform's. */
    public function operatorIssuer(KnativeRegion $region): ?string
    {
        $setting = trim((string) ($region->effective_settings['cert_manager_cluster_issuer'] ?? ''));
        if ($setting !== '' && ! CertManager::isRunrIssuer($setting)) {
            return $setting;
        }

        $platform = trim((string) config('knative.domains.cert_manager_cluster_issuer'));

        return $platform !== '' ? $platform : null;
    }

    /**
     * Where the issuer choice comes from: region (its own setting), platform (RUNR_CERT_MANAGER_CLUSTER_ISSUER)
     * or runr (RunR's default issuer); null without one.
     */
    public function issuerSource(KnativeRegion $region): ?string
    {
        $setting = trim((string) ($region->effective_settings['cert_manager_cluster_issuer'] ?? ''));
        if ($setting !== '' && ! CertManager::isRunrIssuer($setting)) {
            return 'region';
        }
        if (trim((string) config('knative.domains.cert_manager_cluster_issuer')) !== '') {
            return 'platform';
        }

        return CertManager::isRunrIssuer($this->effectiveIssuer($region)) ? 'runr' : null;
    }

    /** auto (default), acme or ca: region setting cert_manager_issuer_mode */
    public function issuerMode(KnativeRegion $region): string
    {
        $mode = (string) ($region->effective_settings['cert_manager_issuer_mode'] ?? 'auto');

        return in_array($mode, CertManager::MODES, true) ? $mode : 'auto';
    }

    /**
     * The ACME account e-mail: the region setting cert_manager_acme_email, else Helm
     * knative.certManager.acmeEmail (RUNR_CERT_MANAGER_ACME_EMAIL).
     *
     * @return array{email: ?string, source: ?string} source region | platform
     */
    public function acmeEmail(KnativeRegion $region): array
    {
        $own = trim((string) ($region->effective_settings['cert_manager_acme_email'] ?? ''));
        if ($own !== '') {
            return ['email' => $own, 'source' => 'region'];
        }

        $platform = trim((string) config('knative.cert_manager.acme_email'));

        return $platform !== '' ? ['email' => $platform, 'source' => 'platform'] : ['email' => null, 'source' => null];
    }

    /**
     * Whether ACME's HTTP-01 check can reach the region from the internet, judged by its external
     * address (the region Traefik's load balancer): a public IP address, or a host name that
     * resolves to one.
     *
     * @return array{0: bool, 1: string} [public, why]
     */
    public function publiclyReachable(KnativeRegion $region): array
    {
        $address = trim((string) $region->external_ip);
        if ($address === '') {
            return [false, __('api.cert_manager.reach_no_address')];
        }

        if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
            return OutboundUrlGuard::isPublicIp($address)
                ? [true, __('api.cert_manager.reach_public', ['address' => $address])]
                : [false, __('api.cert_manager.reach_private', ['address' => $address])];
        }

        $addresses = $this->dns->addresses($address);
        if ($addresses === []) {
            return [false, __('api.cert_manager.reach_unresolved', ['address' => $address])];
        }
        foreach ($addresses as $ip) {
            if (OutboundUrlGuard::isPublicIp($ip)) {
                return [true, __('api.cert_manager.reach_public', ['address' => "{$address} ({$ip})"])];
            }
        }

        return [false, __('api.cert_manager.reach_private', ['address' => $address])];
    }

    /**
     * RunR's default issuer for the region and why: acme or ca.
     *
     * @return array{kind: string, reason: string, email: ?string}
     */
    public function plan(KnativeRegion $region): array
    {
        $mode = $this->issuerMode($region);
        $email = $this->acmeEmail($region)['email'];

        if ($mode === 'ca') {
            return ['kind' => 'ca', 'reason' => __('api.cert_manager.plan_mode_ca'), 'email' => $email];
        }
        if ($mode === 'acme') {
            return $email
                ? ['kind' => 'acme', 'reason' => __('api.cert_manager.plan_mode_acme'), 'email' => $email]
                : ['kind' => 'ca', 'reason' => __('api.cert_manager.plan_acme_no_email'), 'email' => null];
        }

        [$public, $why] = $this->publiclyReachable($region);
        if (! $public) {
            return ['kind' => 'ca', 'reason' => $why, 'email' => $email];
        }
        if (! $email) {
            return ['kind' => 'ca', 'reason' => __('api.cert_manager.plan_no_email'), 'email' => null];
        }

        return ['kind' => 'acme', 'reason' => $why, 'email' => $email];
    }

    /**
     * The issuer the region's custom domains use from now on: the operator's (checked to exist),
     * else RunR's default, created or updated. Records it (setting, column).
     *
     * @return array{0: ?string, 1: ?string} [issuer, problem]
     */
    public function ensureIssuer(KnativeRegion $region, KubernetesClient $k8s): array
    {
        $region = $region->fresh() ?? $region;

        if ($own = $this->operatorIssuer($region)) {
            $this->record($region, $own, false);
            if ($k8s->getClusterResource(CertManager::API, 'ClusterIssuer', $own) === null) {
                return [$own, __('api.cert_manager.own_issuer_missing', ['issuer' => $own])];
            }
            $this->say("ClusterIssuer {$own} (".($this->issuerSource($region) === 'platform' ? 'RUNR_CERT_MANAGER_CLUSTER_ISSUER' : 'region setting cert_manager_cluster_issuer').') is used: RunR creates no issuer of its own.');

            return [$own, null];
        }

        $plan = $this->plan($region);
        $this->say('Default issuer: '.($plan['kind'] === 'acme' ? 'ACME ('.CertManager::acmeServer().')' : 'the region CA').' — '.$plan['reason']);

        if ($plan['kind'] === 'acme') {
            $this->applyAcme($region, $k8s, (string) $plan['email']);
            [$ready, $message] = $this->waitIssuer($k8s, CertManager::ACME_ISSUER);
            if ($ready || $message === null) {
                $this->record($region, CertManager::ACME_ISSUER, true);

                return [CertManager::ACME_ISSUER, null];
            }
            if ($this->issuerMode($region) === 'acme') {
                $this->record($region, CertManager::ACME_ISSUER, true);

                return [CertManager::ACME_ISSUER, __('api.cert_manager.acme_failed', ['message' => $message])];
            }
            $this->say("The ACME account was not registered ({$message}): the region CA issues the certificates instead.");
        }

        $this->applyCa($region, $k8s);
        [$ready, $message] = $this->waitIssuer($k8s, CertManager::CA_ISSUER);
        $this->record($region, CertManager::CA_ISSUER, true);
        if (! $ready) {
            // cert-manager keeps working on it; a certificate it cannot issue says why on its domain
            $this->say('ClusterIssuer '.CertManager::CA_ISSUER.' is not Ready yet'.($message !== null ? " ({$message})" : '').'.');
        }

        return [CertManager::CA_ISSUER, null];
    }

    private function applyAcme(KnativeRegion $region, KubernetesClient $k8s, string $email): void
    {
        $servingNs = $region->knative_serving_namespace ?: 'knative-serving';
        $classes = $k8s->listClusterResources('networking.k8s.io/v1', 'IngressClass')['items'] ?? [];
        $traefik = KnativeInstallService::resolveTraefikIngressClassValues($classes, $servingNs);
        $class = $traefik['providers']['kubernetesIngress']['ingressClass'] ?? null;
        $solver = $class ? ['class' => $class] : ['ingressClassName' => KnativeInstallService::TRAEFIK_INGRESS_CLASS];

        $this->applyRetrying($k8s, CertManager::acmeIssuer($email, CertManager::acmeServer(), $solver));
        $this->say('ClusterIssuer '.CertManager::ACME_ISSUER." applied (account {$email}, HTTP-01 through the region Traefik, Ingress ".($class ? "class {$class}" : 'class '.KnativeInstallService::TRAEFIK_INGRESS_CLASS).').');
    }

    private function applyCa(KnativeRegion $region, KubernetesClient $k8s): void
    {
        $namespace = $region->cert_manager_namespace ?: CertManager::NAMESPACE;

        $this->applyRetrying($k8s, CertManager::selfSignedIssuer());
        if ($k8s->getResourceStatus(CertManager::API, 'Certificate', CertManager::CA_CERTIFICATE, $namespace) === null) {
            // the webhook validates it too: right after a (re)install it refuses for a few seconds
            $this->applyRetrying($k8s, CertManager::caCertificate($namespace, CertManager::caCommonName($region->name)), $namespace);
        }
        $this->applyRetrying($k8s, CertManager::caIssuer());
        $this->say('Region CA '.CertManager::CA_CERTIFICATE." (namespace {$namespace}) and ClusterIssuer ".CertManager::CA_ISSUER.' applied.');
    }

    /**
     * Apply a ClusterIssuer (or, with $namespace, a namespaced resource such as the region CA's
     * Certificate); cert-manager's webhook validates it and answers errors for a few seconds after
     * its Deployment became available (its serving certificate is being injected) — longest right
     * after a reinstall, whose images are already on the nodes.
     */
    private function applyRetrying(KubernetesClient $k8s, array $manifest, ?string $namespace = null): void
    {
        $last = null;
        for ($try = 1; $try <= self::WEBHOOK_TRIES; $try++) {
            try {
                if ($namespace === null ? $k8s->applyClusterResource($manifest) : $k8s->applyResource($manifest, $namespace)) {
                    return;
                }
            } catch (Throwable $e) {
                $last = $e;
            }
            if ($try < self::WEBHOOK_TRIES) {
                Sleep::for(5)->seconds();
            }
        }

        throw new RuntimeException("{$manifest['kind']} {$manifest['metadata']['name']} was not applied".($namespace !== null ? " in {$namespace}" : '').($last ? ': '.$last->getMessage() : ''));
    }

    /**
     * Waits for a ClusterIssuer's Ready condition: [ready, message] — the last message it reported
     * while not Ready (a CA issuer reports its missing key pair until the CA certificate is
     * issued), null when it never reported.
     *
     * @return array{0: bool, 1: ?string}
     */
    private function waitIssuer(KubernetesClient $k8s, string $name): array
    {
        $message = null;
        for ($waited = 0; ; $waited += 3) {
            [$ready, $now] = CertManager::readyCondition($k8s->getClusterResource(CertManager::API, 'ClusterIssuer', $name));
            if ($ready) {
                return [true, null];
            }
            $message = $now ?? $message;
            if ($waited >= self::ISSUER_WAIT_SECONDS) {
                return [false, $message];
            }
            Sleep::for(3)->seconds();
        }
    }

    /** The issuer in the column (and in the region setting where it is RunR's). */
    private function record(KnativeRegion $region, string $issuer, bool $runrDefault): void
    {
        $region = $region->fresh() ?? $region;
        $settings = $region->knative_settings ?? [];
        if ($runrDefault) {
            $settings['cert_manager_cluster_issuer'] = $issuer;
        } elseif (CertManager::isRunrIssuer($settings['cert_manager_cluster_issuer'] ?? null)) {
            // the operator's issuer is the platform's: RunR's name in the setting would hide it
            unset($settings['cert_manager_cluster_issuer']);
        }

        $region->update(['knative_settings' => $settings, 'cert_manager_issuer' => $issuer]);
    }

    // ── Custom domains ──────────────────────────────────────────────────────

    /**
     * Whether custom domains of the region can use TLS mode cert-manager, and if not why and what
     * to do: ready with an issuer (also an operator's issuer on a region whose cert-manager RunR
     * has not looked at yet — setting the mode checks the cluster).
     *
     * @return array{available: bool, status: string, issuer: ?string, kind: ?string, reason: ?string, ca: bool}
     */
    public function forDomains(?KnativeRegion $region): array
    {
        $status = (string) ($region->cert_manager_status ?? self::STATUS_NOT_INSTALLED);
        $issuer = $this->effectiveIssuer($region);
        $kind = CertManager::issuerKind($issuer);

        $reason = match (true) {
            $region === null || $region->is_demo || ! $region->kubeconfig => __('api.domain.region_unavailable'),
            $status === self::STATUS_INSTALLING => __('api.cert_manager.domains_installing'),
            $status === self::STATUS_UNINSTALLING => __('api.cert_manager.domains_uninstalling'),
            $status === self::STATUS_ERROR => __('api.cert_manager.domains_error', ['message' => self::lastLine($region->cert_manager_status_message) ?? '-']),
            $status === self::STATUS_READY && $issuer === null => __('api.cert_manager.domains_no_issuer'),
            $status === self::STATUS_READY => null,
            $issuer !== null && $kind === 'own' => null,
            default => __('api.cert_manager.domains_not_installed'),
        };

        return [
            'available' => $reason === null,
            'status' => $status,
            'issuer' => $issuer,
            'kind' => $kind,
            'reason' => $reason,
            'ca' => $reason === null && $kind === 'ca',
        ];
    }

    /** The TLS mode of a new custom domain: cert-manager where the region issues certificates, else none. */
    public function defaultTlsMode(?KnativeRegion $region): string
    {
        $domains = $this->forDomains($region);

        return $domains['available'] && $domains['status'] === self::STATUS_READY
            ? ProjectDomain::TLS_CERT_MANAGER
            : ProjectDomain::TLS_NONE;
    }

    /**
     * The region CA's certificate (PEM) — never its key — or null when the region has no CA.
     */
    public function caCertificate(KnativeRegion $region, ?KubernetesClient $k8s = null): ?string
    {
        if (! $region->kubeconfig || $region->is_demo) {
            return null;
        }

        $k8s ??= new KubernetesClient($region->kubeconfig);
        $secret = $k8s->getResourceStatus('v1', 'Secret', CertManager::CA_CERTIFICATE, $region->cert_manager_namespace ?: CertManager::NAMESPACE);
        if (! $secret) {
            return null;
        }

        foreach (['ca.crt', 'tls.crt'] as $key) {
            $pem = base64_decode((string) ($secret['data'][$key] ?? ''), true);
            if ($pem !== false && preg_match('/-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $pem, $m)) {
                return $m[0]."\n";
            }
        }

        return null;
    }

    // ── Removal ─────────────────────────────────────────────────────────────

    /**
     * Custom-domain certificates of the region (RunR's Certificates in its project namespace).
     *
     * @return list<string> their names
     */
    public function domainCertificates(KnativeRegion $region, KubernetesClient $k8s): array
    {
        if (! $k8s->hasApiVersion(CertManager::API)) {
            return [];
        }

        $namespace = $region->project_namespace ?? $region->name;
        $list = $k8s->listResources(CertManager::API, 'Certificate', $namespace, ['app.kubernetes.io/managed-by' => 'runr']);

        return array_values(array_filter(array_map(
            fn (array $c) => isset($c['metadata']['labels'][ProjectDomainManifests::LABEL_PROJECT]) ? (string) $c['metadata']['name'] : null,
            $list['items'] ?? [],
        )));
    }

    /**
     * The region stops using cert-manager. RunR's issuers, the region CA and RunR's release go
     * only when nothing else on the cluster uses cert-manager; a cert-manager RunR did not install
     * stays as it is. Its CRDs always stay (crds.keep): removing them deletes every certificate
     * resource of the cluster.
     *
     * @return list<string> what was done
     *
     * @throws CertManagerInUseException the region's custom domains still have certificates
     */
    public function uninstall(KnativeRegion $region, ?KubernetesClient $k8s = null): array
    {
        $k8s ??= new KubernetesClient((string) $region->kubeconfig);

        $inUse = $this->domainCertificates($region, $k8s);
        if ($inUse !== []) {
            throw new CertManagerInUseException(__('api.cert_manager.in_use', ['count' => count($inUse)]));
        }

        $lines = [];
        $found = $this->detect($k8s);
        $others = $this->otherUsers($region, $k8s);

        if ($others !== []) {
            $lines[] = 'cert-manager stays: '.implode('; ', $others).'.';
        } else {
            foreach ([CertManager::ACME_ISSUER, CertManager::CA_ISSUER, CertManager::SELFSIGNED_ISSUER] as $issuer) {
                if ($k8s->hasApiVersion(CertManager::API)) {
                    $k8s->deleteClusterResource(CertManager::API, 'ClusterIssuer', $issuer);
                }
            }
            $namespace = $region->cert_manager_namespace ?: ($found['namespace'] ?? CertManager::NAMESPACE);
            if ($k8s->hasApiVersion(CertManager::API)) {
                $k8s->deleteResource(CertManager::API, 'Certificate', CertManager::CA_CERTIFICATE, $namespace);
            }
            $k8s->deleteResource('v1', 'Secret', CertManager::CA_CERTIFICATE, $namespace);
            $k8s->deleteResource('v1', 'Secret', CertManager::ACME_ACCOUNT_SECRET, $namespace);
            $lines[] = "RunR's ClusterIssuers (".CertManager::ACME_ISSUER.', '.CertManager::CA_ISSUER.', '.CertManager::SELFSIGNED_ISSUER.') and the region CA removed.';

            if ($found['present'] && $found['runr']) {
                $result = app(HelmService::class)->uninstall(CertManager::RELEASE, CertManager::NAMESPACE, (string) $region->kubeconfig);
                if (! $result->success && ! str_contains($result->error, 'not found')) {
                    throw new RuntimeException('cert-manager: '.$result->error);
                }
                $this->deleteNamespaceIfRunrs($k8s);
                $lines[] = "RunR's cert-manager release removed; its CRDs stay (kubectl delete crd -l app.kubernetes.io/part-of=runr removes them, and with them every certificate resource of the cluster).";
            }
        }

        if ($found['present'] && ! $found['runr']) {
            $lines[] = 'cert-manager was not installed by RunR: it stays on the cluster.';
        }

        $settings = $region->knative_settings ?? [];
        if (CertManager::isRunrIssuer($settings['cert_manager_cluster_issuer'] ?? null)) {
            unset($settings['cert_manager_cluster_issuer']);
        }
        $region->update([
            'knative_settings' => $settings,
            'cert_manager_status' => self::STATUS_NOT_INSTALLED,
            'cert_manager_status_message' => null,
            'cert_manager_managed' => null,
            'cert_manager_version' => null,
            'cert_manager_namespace' => null,
            'cert_manager_issuer' => $this->operatorIssuer($region),
        ]);

        return $lines;
    }

    /**
     * What else uses cert-manager on the region's cluster: other regions there (this database
     * context) and Certificates outside RunR's region CA (custom domains of other regions or
     * organizations, the console's own certificate, anything of the operator's).
     *
     * @return list<string>
     */
    private function otherUsers(KnativeRegion $region, KubernetesClient $k8s): array
    {
        $users = [];

        $identity = app(RunrClusterIdentity::class);
        $uid = $identity->regionClusterUid($region);
        if ($uid !== null) {
            $siblings = $region->newQuery()
                ->whereKeyNot($region->getKey())
                ->whereNotNull('kubeconfig')
                ->where('cert_manager_status', '!=', self::STATUS_NOT_INSTALLED)
                ->get()
                ->filter(fn (KnativeRegion $other) => $identity->regionClusterUid($other) === $uid)
                ->map(fn (KnativeRegion $other) => $other->name)
                ->values()
                ->all();
            if ($siblings !== []) {
                $users[] = 'region(s) '.implode(', ', $siblings).' on the same cluster use it';
            }
        }

        if ($k8s->hasApiVersion(CertManager::API)) {
            $certificates = $k8s->listClusterResources(CertManager::API, 'Certificate')['items'] ?? [];
            $foreign = array_filter($certificates, fn (array $c) => ($c['metadata']['name'] ?? '') !== CertManager::CA_CERTIFICATE);
            if ($foreign !== []) {
                $users[] = count($foreign).' certificate(s) on the cluster ('.implode(', ', array_slice(array_map(
                    fn (array $c) => ($c['metadata']['namespace'] ?? '').'/'.($c['metadata']['name'] ?? ''),
                    array_values($foreign),
                ), 0, 5)).')';
            }
        }

        return $users;
    }

    private function deleteNamespaceIfRunrs(KubernetesClient $k8s): void
    {
        $namespace = $k8s->getClusterResource('v1', 'Namespace', CertManager::NAMESPACE);
        if (($namespace['metadata']['labels']['app.kubernetes.io/managed-by'] ?? null) === 'runr') {
            $k8s->deleteClusterResource('v1', 'Namespace', CertManager::NAMESPACE);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** The last line of a step log. */
    public static function lastLine(?string $log): ?string
    {
        if ($log === null || trim($log) === '') {
            return null;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $log) ?: [])));

        return $lines === [] ? null : mb_substr((string) preg_replace('/^\[\d{2}:\d{2}:\d{2}\]\s*/', '', (string) end($lines)), 0, 500);
    }

    /** Lines go to the region's step log (and to $log, e.g. the Serving install's log). */
    private function logTo(KnativeRegion $region, ?callable $log): void
    {
        $this->logRegion = $region;
        $this->log = $log;
    }

    private function say(string $message): void
    {
        if ($region = $this->logRegion) {
            $log = ($region->cert_manager_status_message ?? '').'['.now()->format('H:i:s')."] {$message}\n";
            $region->forceFill(['cert_manager_status_message' => mb_substr($log, -20000)])->save();

            event(new PlatformEvent('install.progress', [
                'region_id' => $region->id,
                'component' => 'cert-manager',
                'message' => $region->cert_manager_status_message,
            ]));
        }
        if ($this->log) {
            ($this->log)($message);
        }
    }
}
