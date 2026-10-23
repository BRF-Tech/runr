<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\KnativeRegionResource;
use App\Jobs\InstallCertManagerJob;
use App\Models\KnativeRegion;
use App\Services\CertManager\CertManager;
use App\Services\CertManager\CertManagerService;
use App\Services\KubernetesClient;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * cert-manager of a region and the ClusterIssuer of its custom domains (docs/regions.md#cert-manager).
 * Route rules: GET regions.view, POST / DELETE regions.manage (plus region.mutable).
 *
 * @tags Knative Regions
 */
class CertManagerController extends Controller
{
    public function __construct(private readonly CertManagerService $service) {}

    /**
     * cert-manager of the region: status and step log, whether RunR installed it, the issuer of
     * custom domains and why RunR chose it, the ACME e-mail, whether custom domains can use it and
     * — unless ?cluster=0 — what the cluster runs (the issuer's Ready condition, the region CA).
     * A region still "installing" is checked again in the background (its issuer follows once
     * cert-manager runs).
     */
    #[QueryParameter('cluster', '0: leave out what the cluster runs (cluster is null)', type: 'string', default: '1')]
    #[OpenApiResponse(200, 'cert-manager of the region; cluster: what the cluster runs (null with ?cluster=0, without a kubeconfig or when it did not answer: cluster_error)', type: 'array{status: "not_installed"|"installing"|"ready"|"error"|"uninstalling", message: string|null, managed: bool|null, version: string|null, namespace: string|null, chart_version: string, install_enabled: bool, issuer: array{name: string|null, kind: "acme"|"ca"|"own"|null, source: "region"|"platform"|"runr"|null}, issuer_mode: "auto"|"acme"|"ca", plan: array{kind: "acme"|"ca", reason: string}, acme: array{email: string|null, email_source: "region"|"platform"|null, server: string, public: bool, public_reason: string}, domains: array{available: bool, status: string, issuer: string|null, kind: string|null, reason: string|null, ca: bool}, can_manage: bool, cluster: array{present: bool, runr: bool, namespace: string|null, version: string|null, available: bool, drift: list<string>, issuer_exists: bool, issuer_ready: bool, issuer_message: string|null, ca_available: bool, domain_certificates: int}|null, cluster_error: string|null}')]
    public function show(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $region = $knativeRegion;
        $this->service->queueRefresh($region);
        $cluster = null;
        $error = null;

        if ($request->query('cluster', '1') !== '0' && $region->kubeconfig && ! $region->is_demo) {
            try {
                $cluster = $this->clusterState($region);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $issuer = $this->service->effectiveIssuer($region);
        $email = $this->service->acmeEmail($region);
        [$public, $why] = $this->service->publiclyReachable($region);
        $plan = $this->service->plan($region);

        return response()->json([
            'status' => $region->cert_manager_status ?? CertManagerService::STATUS_NOT_INSTALLED,
            'message' => $region->cert_manager_status_message,
            'managed' => $region->cert_manager_managed,
            'version' => $region->cert_manager_version,
            'namespace' => $region->cert_manager_namespace,
            'chart_version' => CertManager::chartVersion(),
            'install_enabled' => CertManager::installEnabled(),
            'issuer' => [
                'name' => $issuer,
                'kind' => CertManager::issuerKind($issuer),
                'source' => $this->service->issuerSource($region),
            ],
            'issuer_mode' => $this->service->issuerMode($region),
            // RunR's default issuer for the region as things are now (used unless an operator's issuer is set)
            'plan' => ['kind' => $plan['kind'], 'reason' => $plan['reason']],
            'acme' => [
                'email' => $email['email'],
                'email_source' => $email['source'],
                'server' => CertManager::acmeServer(),
                'public' => $public,
                'public_reason' => $why,
            ],
            'domains' => $this->service->forDomains($region),
            // POST / DELETE require regions.manage (config/route_permissions.php)
            'can_manage' => Gate::allows('regions.manage'),
            'cluster' => $cluster,
            'cluster_error' => $error,
        ]);
    }

    /**
     * Install cert-manager (or reuse the cluster's own) and create the default issuer; on a
     * working install it brings RunR's release to this release's chart and creates a missing
     * issuer again. Queued: progress in the step log (message) and install.progress events with
     * component "cert-manager".
     *
     * @response JsonResponse<array{message: string, region: array<string, mixed>}, 202>
     */
    #[OpenApiResponse(202, 'Queued; the region as it is now', type: 'array{message: string, region: KnativeRegionResource}')]
    #[OpenApiResponse(422, 'Knative Serving is not installed, a demo region, a region without a kubeconfig, or a removal under way', type: 'array{message: string}')]
    public function install(KnativeRegion $knativeRegion): JsonResponse
    {
        // again while "installing" too: a cert-manager that never started (its images not pulled)
        // is retried; a second job while one runs does nothing (InstallCertManagerJob's lock)
        if ($blocked = $this->blocked($knativeRegion, [CertManagerService::STATUS_UNINSTALLING])) {
            return $blocked;
        }
        if (! $knativeRegion->has_serving) {
            return response()->json(['message' => __('api.cert_manager.serving_required')], 422);
        }

        $knativeRegion->update([
            'cert_manager_status' => CertManagerService::STATUS_INSTALLING,
            'cert_manager_status_message' => '['.now()->format('H:i:s')."] Installation queued...\n",
        ]);
        InstallCertManagerJob::dispatch($knativeRegion, InstallCertManagerJob::INSTALL, auth()->id());

        return response()->json([
            'message' => __('api.cert_manager.install_queued'),
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ], 202);
    }

    /**
     * The region stops using cert-manager: refused while its custom domains have cert-manager
     * certificates. RunR's issuers and RunR's cert-manager go only when nothing else on the cluster
     * uses them; a cert-manager RunR did not install stays.
     *
     * @response JsonResponse<array{message: string, region: array<string, mixed>}, 202>
     */
    #[OpenApiResponse(202, 'Queued; the region as it is now', type: 'array{message: string, region: KnativeRegionResource}')]
    #[OpenApiResponse(422, 'Not installed, custom domains still have its certificates, the cluster did not answer, a demo region, a region without a kubeconfig, or an operation under way', type: 'array{message: string, certificates?: list<string>}')]
    public function uninstall(KnativeRegion $knativeRegion): JsonResponse
    {
        if ($blocked = $this->blocked($knativeRegion)) {
            return $blocked;
        }
        if (($knativeRegion->cert_manager_status ?? CertManagerService::STATUS_NOT_INSTALLED) === CertManagerService::STATUS_NOT_INSTALLED) {
            return response()->json(['message' => __('api.cert_manager.not_installed')], 422);
        }

        try {
            $inUse = $this->service->domainCertificates($knativeRegion, new KubernetesClient($knativeRegion->kubeconfig));
        } catch (Throwable $e) {
            return response()->json(['message' => __('api.cert_manager.cluster_unreachable', ['error' => $e->getMessage()])], 422);
        }
        if ($inUse !== []) {
            return response()->json(['message' => __('api.cert_manager.in_use', ['count' => count($inUse)]), 'certificates' => $inUse], 422);
        }

        $previous = $knativeRegion->cert_manager_status;
        $knativeRegion->update(['cert_manager_status' => CertManagerService::STATUS_UNINSTALLING]);
        InstallCertManagerJob::dispatch($knativeRegion, InstallCertManagerJob::UNINSTALL, auth()->id(), $previous);

        return response()->json([
            'message' => __('api.cert_manager.uninstall_queued'),
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ], 202);
    }

    /**
     * The region CA's certificate (PEM): what browsers and clients trust to accept the custom-domain
     * certificates of a region whose issuer is the region CA (runr-ca). Never the key.
     *
     * @response Response<"", 200>
     */
    #[OpenApiResponse(200, 'The region CA\'s certificate (PEM, as an attachment)', mediaType: 'application/x-pem-file', type: 'string')]
    #[OpenApiResponse(404, 'The region has no CA', type: 'array{message: string}')]
    #[OpenApiResponse(422, 'The region\'s cluster did not answer', type: 'array{message: string}')]
    public function caCertificate(KnativeRegion $knativeRegion): Response|JsonResponse
    {
        return self::pemDownload($this->service, $knativeRegion);
    }

    /** The PEM download of a region's CA (also for a project's custom domains: ProjectDomainController). */
    public static function pemDownload(CertManagerService $service, ?KnativeRegion $region): Response|JsonResponse
    {
        if (! $region) {
            return response()->json(['message' => __('api.cert_manager.ca_missing')], 404);
        }

        try {
            $pem = $service->caCertificate($region);
        } catch (Throwable $e) {
            return response()->json(['message' => __('api.cert_manager.cluster_unreachable', ['error' => $e->getMessage()])], 422);
        }
        if ($pem === null) {
            return response()->json(['message' => __('api.cert_manager.ca_missing')], 404);
        }

        return response($pem, 200, [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => 'attachment; filename="'.$region->name.'-runr-ca.crt"',
        ]);
    }

    /** @return array<string, mixed> */
    private function clusterState(KnativeRegion $region): array
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $found = $this->service->detect($k8s);
        $issuer = $this->service->effectiveIssuer($region);
        $served = $found['present'] && $k8s->hasApiVersion(CertManager::API);
        $object = $issuer && $served ? $k8s->getClusterResource(CertManager::API, 'ClusterIssuer', $issuer) : null;
        [$ready, $message] = CertManager::readyCondition($object);

        return [
            'present' => $found['present'],
            'runr' => $found['runr'],
            'namespace' => $found['namespace'],
            'version' => $found['version'],
            'available' => $found['available'],
            'drift' => $found['runr'] ? $this->service->drift($k8s, $found['namespace'] ?? CertManager::NAMESPACE) : [],
            'issuer_exists' => $object !== null,
            'issuer_ready' => $ready,
            'issuer_message' => $ready ? null : $message,
            'ca_available' => $served && $this->service->caCertificate($region, $k8s) !== null,
            'domain_certificates' => $served ? count($this->service->domainCertificates($region, $k8s)) : 0,
        ];
    }

    /**
     * @param  list<string>  $busy  statuses that refuse the change
     */
    private function blocked(KnativeRegion $region, array $busy = [CertManagerService::STATUS_INSTALLING, CertManagerService::STATUS_UNINSTALLING]): ?JsonResponse
    {
        if ($region->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }
        if (! $region->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }
        if (in_array($region->cert_manager_status, $busy, true)) {
            return response()->json(['message' => __('api.region.operation_in_progress')], 422);
        }

        return null;
    }
}
