<?php

namespace App\Services\CertManager;

use App\Services\Helpers\DedicatedWorkers;
use App\Support\Images\RegistryMove;

/**
 * cert-manager in a region (docs/regions.md#cert-manager): what RunR installs and the default
 * ClusterIssuers it creates, as pure values and manifests (CertManagerService applies them).
 *
 *   Helm release   cert-manager in namespace cert-manager, chart jetstack/cert-manager CHART_VERSION
 *                  with its CRDs (kept when the release goes), every image from the reqs mirror
 *                  (ghcr.io/brf-tech/runr/reqs/cert-manager-<component>, the mirror of
 *                  quay.io/jetstack/cert-manager-<component>), no startupapicheck Job (RunR waits
 *                  for the Deployments itself), label app.kubernetes.io/part-of=runr on everything
 *                  it renders — the mark of RunR's own installation (detect()).
 *   ClusterIssuer  runr-acme        ACME (Let's Encrypt by default), HTTP-01 through the region's
 *                                   Traefik: the solver Ingress takes the class that Traefik serves
 *   ClusterIssuer  runr-selfsigned  signs the region CA only
 *   Certificate    runr-region-ca   the region CA (Secret runr-region-ca, in cert-manager's
 *                                   namespace — the cluster resource namespace of ClusterIssuers)
 *   ClusterIssuer  runr-ca          issues from the region CA
 *
 * The issuers are cluster-wide: regions on one cluster share them, as they share cert-manager.
 */
final class CertManager
{
    public const API = 'cert-manager.io/v1';

    public const NAMESPACE = 'cert-manager';

    public const RELEASE = 'cert-manager';

    public const REPO = 'jetstack';

    public const REPO_URL = 'https://charts.jetstack.io';

    public const CHART = 'jetstack/cert-manager';

    /** Chart name: the workloads' helm.sh/chart label is "<name>-<version>" */
    public const CHART_NAME = 'cert-manager';

    // Pinned chart version; its images (appVersion, the same tag) come from the reqs mirror of
    // quay.io/jetstack/cert-manager-<component> (REQS_IMAGES of lib/mirror.sh in
    // BRF-Tech/runr-runtimes) and are loaded into Kind by the e2e. A chart bump changes the tag
    // of every image here and their REQS_IMAGES entries; region:reconcile (CertManagerStep)
    // brings RunR's installations to it. RUNR_CERT_MANAGER_CHART_VERSION / _IMAGE_PREFIX (Helm
    // values knative.certManager.chartVersion / imagePrefix) override them.
    public const CHART_VERSION = 'v1.21.2';

    public const IMAGE_PREFIX = 'ghcr.io/brf-tech/runr/reqs';

    /** Components of the release and the images they run (<prefix>/cert-manager-<component>:<version>) */
    public const COMPONENTS = ['controller', 'webhook', 'cainjector', 'acmesolver'];

    /** Deployments of the release (the acmesolver image runs in the HTTP-01 solver pods only) */
    public const DEPLOYMENTS = ['controller' => 'cert-manager', 'webhook' => 'cert-manager-webhook', 'cainjector' => 'cert-manager-cainjector'];

    /**
     * CPU / memory requests, in line with RunR's other region add-ons: the region capacity
     * subtracts them like any other platform pod. cert-manager is light; the limits are the chart's (none).
     */
    public const REQUESTS = [
        'controller' => ['cpu' => '10m', 'memory' => '64Mi'],
        'webhook' => ['cpu' => '10m', 'memory' => '32Mi'],
        'cainjector' => ['cpu' => '10m', 'memory' => '64Mi'],
    ];

    /** The mark of RunR's installation on every object the chart renders (global.commonLabels) */
    public const PART_OF = ['app.kubernetes.io/part-of' => 'runr'];

    public const ACME_ISSUER = 'runr-acme';

    public const ACME_ACCOUNT_SECRET = 'runr-acme-account';

    public const SELFSIGNED_ISSUER = 'runr-selfsigned';

    public const CA_ISSUER = 'runr-ca';

    /** Certificate and Secret of the region CA, in cert-manager's namespace */
    public const CA_CERTIFICATE = 'runr-region-ca';

    /** Ten years: the CA outlives every certificate it signs (90 days each, cert-manager's default) */
    public const CA_DURATION = '87600h';

    public const LETSENCRYPT = 'https://acme-v02.api.letsencrypt.org/directory';

    /** Issuer modes of the region setting cert_manager_issuer_mode */
    public const MODES = ['auto', 'acme', 'ca'];

    public static function chartVersion(): string
    {
        return (string) (config('knative.cert_manager.chart_version') ?: self::CHART_VERSION);
    }

    /** Where the images come from: the reqs mirror, or RUNR_CERT_MANAGER_IMAGE_PREFIX */
    public static function imagePrefix(): string
    {
        return rtrim((string) (RegistryMove::ref((string) config('knative.cert_manager.image_prefix')) ?: self::IMAGE_PREFIX), '/');
    }

    /** The image of one component at the version of the chart (its appVersion is the chart version) */
    public static function image(string $component): string
    {
        return self::imagePrefix().'/cert-manager-'.$component.':'.self::chartVersion();
    }

    /** @return array<string, string> component => image */
    public static function images(): array
    {
        $images = [];
        foreach (self::COMPONENTS as $component) {
            $images[$component] = self::image($component);
        }

        return $images;
    }

    /** Whether the region install sets cert-manager up where the cluster has none (Helm knative.certManager.install). */
    public static function installEnabled(): bool
    {
        return (bool) config('knative.cert_manager.install', true);
    }

    public static function acmeServer(): string
    {
        return (string) (config('knative.cert_manager.acme_server') ?: self::LETSENCRYPT);
    }

    /** RunR's issuer names: a region setting naming one of them is RunR's default, not the operator's choice */
    public static function isRunrIssuer(?string $name): bool
    {
        return in_array($name, [self::ACME_ISSUER, self::CA_ISSUER], true);
    }

    /** acme, ca (RunR's issuers) or own (an issuer of the operator); null without one */
    public static function issuerKind(?string $name): ?string
    {
        return match (true) {
            $name === null || $name === '' => null,
            $name === self::ACME_ISSUER => 'acme',
            $name === self::CA_ISSUER => 'ca',
            default => 'own',
        };
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return ['app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/component' => 'cert-manager'] + self::PART_OF;
    }

    /**
     * Values of the Helm release: CRDs with the release (kept when it goes), the images from the
     * mirror, the requests above, RunR's label, the placement of a system component (tolerates the
     * dedicated-worker taint, prefers the other nodes) and no startupapicheck Job.
     */
    public static function helmValues(): array
    {
        $placement = DedicatedWorkers::systemPlacement();
        $component = fn (string $name) => [
            'image' => self::imageValues($name),
            'resources' => ['requests' => self::REQUESTS[$name]],
        ] + $placement;

        return [
            'crds' => ['enabled' => true, 'keep' => true],
            'global' => ['commonLabels' => self::PART_OF],
            'image' => self::imageValues('controller'),
            'resources' => ['requests' => self::REQUESTS['controller']],
            'webhook' => $component('webhook'),
            'cainjector' => $component('cainjector'),
            'acmesolver' => ['image' => self::imageValues('acmesolver')],
            'startupapicheck' => ['enabled' => false],
        ] + $placement;
    }

    /** @return array{repository: string, tag: string} */
    private static function imageValues(string $component): array
    {
        return ['repository' => self::imagePrefix().'/cert-manager-'.$component, 'tag' => self::chartVersion()];
    }

    /**
     * The ACME ClusterIssuer: account e-mail, server, HTTP-01 through the region's Traefik.
     * $solverIngress: ['ingressClassName' => 'traefik'] when that Traefik owns the IngressClass
     * "traefik", else ['class' => 'traefik-knative'] (the annotation its Ingress provider watches,
     * KnativeTraefikTrait::resolveTraefikIngressClassValues()). The solver pods tolerate the
     * dedicated-worker taint: they run in the project namespace, possibly on a dedicated worker.
     *
     * @param  array<string, string>  $solverIngress
     */
    public static function acmeIssuer(string $email, string $server, array $solverIngress): array
    {
        return [
            'apiVersion' => self::API,
            'kind' => 'ClusterIssuer',
            'metadata' => ['name' => self::ACME_ISSUER, 'labels' => self::labels()],
            'spec' => [
                'acme' => [
                    'server' => $server,
                    'email' => $email,
                    'privateKeySecretRef' => ['name' => self::ACME_ACCOUNT_SECRET],
                    'solvers' => [[
                        'http01' => [
                            'ingress' => $solverIngress + [
                                'podTemplate' => ['spec' => ['tolerations' => [DedicatedWorkers::toleration()]]],
                            ],
                        ],
                    ]],
                ],
            ],
        ];
    }

    public static function selfSignedIssuer(): array
    {
        return [
            'apiVersion' => self::API,
            'kind' => 'ClusterIssuer',
            'metadata' => ['name' => self::SELFSIGNED_ISSUER, 'labels' => self::labels()],
            'spec' => ['selfSigned' => new \stdClass],
        ];
    }

    /** The region CA: an ECDSA P-256 CA certificate for ten years, signed by itself. */
    public static function caCertificate(string $namespace, string $commonName): array
    {
        return [
            'apiVersion' => self::API,
            'kind' => 'Certificate',
            'metadata' => ['name' => self::CA_CERTIFICATE, 'namespace' => $namespace, 'labels' => self::labels()],
            'spec' => [
                'isCA' => true,
                'commonName' => $commonName,
                'subject' => ['organizations' => ['RunR']],
                'secretName' => self::CA_CERTIFICATE,
                'duration' => self::CA_DURATION,
                'privateKey' => ['algorithm' => 'ECDSA', 'size' => 256],
                'issuerRef' => ['name' => self::SELFSIGNED_ISSUER, 'kind' => 'ClusterIssuer', 'group' => 'cert-manager.io'],
                'secretTemplate' => ['labels' => self::labels()],
            ],
        ];
    }

    public static function caIssuer(): array
    {
        return [
            'apiVersion' => self::API,
            'kind' => 'ClusterIssuer',
            'metadata' => ['name' => self::CA_ISSUER, 'labels' => self::labels()],
            'spec' => ['ca' => ['secretName' => self::CA_CERTIFICATE]],
        ];
    }

    /** Common name of the region CA: the cluster's regions share it, so it names the region that created it */
    public static function caCommonName(string $regionName): string
    {
        return mb_substr("RunR region CA ({$regionName})", 0, 64);
    }

    /**
     * The Ready condition of a cert-manager object (ClusterIssuer, Certificate): [ready, message].
     *
     * @return array{0: bool, 1: ?string}
     */
    public static function readyCondition(?array $object): array
    {
        if ($object === null) {
            return [false, null];
        }

        foreach ($object['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Ready') {
                $message = trim((string) ($condition['message'] ?? $condition['reason'] ?? ''));

                return [($condition['status'] ?? '') === 'True', $message !== '' ? $message : null];
            }
        }

        return [false, null];
    }
}
