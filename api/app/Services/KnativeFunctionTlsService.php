<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Services\Concerns\RegionFunctionTlsTrait;
use App\Services\Helpers\RunrClusterIdentity;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Region-level "function-domain TLS" setting.
 *
 * Entirely opt-in: applyCustom()/applyConsole() are only ever invoked from
 * KnativeFunctionTlsController::update, i.e. only when an operator explicitly calls
 * PUT /knative-regions/{id}/function-tls. This service is not wired into
 * KnativeInstallService's install flow, cluster:patch-observability or region:reconcile —
 * a region that never calls the endpoint is completely unaffected.
 *
 * Before anything is written the certificate must fit the region (key matches, not
 * expired, SANs cover *.<knative_domain>) and the cluster's Traefik "default" TLSStore
 * must be free for RunR (RegionFunctionTlsTrait).
 */
class KnativeFunctionTlsService
{
    use RegionFunctionTlsTrait;

    private const SECRET_NAME = 'runr-function-tls';

    public function __construct(private readonly RunrClusterIdentity $clusterIdentity) {}

    /**
     * Upload-your-own-certificate mode.
     */
    public function applyCustom(KnativeRegion $region, string $certificatePem, string $privateKeyPem, bool $replaceExisting = false): KnativeRegion
    {
        $expiresAt = $this->syncOrFail($region, $certificatePem, $privateKeyPem, $replaceExisting);

        $region->update([
            'function_tls_mode' => 'custom',
            'function_tls_secret_name' => self::SECRET_NAME,
            'function_tls_expires_at' => $expiresAt,
        ]);

        return $region->fresh();
    }

    /**
     * "Reuse the console certificate" mode — only possible when the region's cluster
     * IS the console's own cluster (RunrClusterIdentity::isRunrCluster). The console's
     * own TLS Secret (knative.function_tls.console_secret_name — the chart's
     * ingress.tls.secretName, default runr-tls-custom) is read live via RunR's in-cluster
     * service account and copied into the region's runr-function-tls Secret.
     */
    public function applyConsole(KnativeRegion $region, bool $replaceExisting = false): KnativeRegion
    {
        if (! $this->clusterIdentity->isRunrCluster($region)) {
            throw ValidationException::withMessages([
                'mode' => __('api.region.function_tls_console_unavailable'),
            ]);
        }

        $k8s = $this->clusterIdentity->inClusterClient();
        $ns = $this->clusterIdentity->namespace();

        if (! $k8s || ! $ns) {
            throw ValidationException::withMessages([
                'mode' => __('api.region.function_tls_console_unavailable'),
            ]);
        }

        $secretName = (string) config('knative.function_tls.console_secret_name', 'runr-tls-custom');
        $secret = $k8s->getResourceStatus('v1', 'Secret', $secretName, $ns);
        $crtB64 = $secret['data']['tls.crt'] ?? null;
        $keyB64 = $secret['data']['tls.key'] ?? null;

        if (! $crtB64 || ! $keyB64) {
            throw ValidationException::withMessages([
                'mode' => __('api.region.function_tls_console_not_configured'),
            ]);
        }

        $expiresAt = $this->syncOrFail($region, (string) base64_decode($crtB64), (string) base64_decode($keyB64), $replaceExisting);

        $region->update([
            'function_tls_mode' => 'console',
            'function_tls_secret_name' => self::SECRET_NAME,
            'function_tls_expires_at' => $expiresAt,
        ]);

        return $region->fresh();
    }

    /**
     * Function TLS off: the region Traefik answers HTTPS with its own default certificate again.
     * RunR's Secret and its "default" TLSStore are removed; a TLSStore RunR did not create stays.
     */
    public function remove(KnativeRegion $region): KnativeRegion
    {
        $ns = $region->knative_serving_namespace ?: 'knative-serving';

        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            foreach (self::TLSSTORE_APIS as $api) {
                $store = $k8s->getResourceStatus($api, 'TLSStore', self::TLSSTORE_NAME, $ns);
                if ($store && ($store['metadata']['labels'][self::MANAGED_LABEL] ?? null) === 'runr'
                    && ! $k8s->deleteResource($api, 'TLSStore', self::TLSSTORE_NAME, $ns)) {
                    throw new \RuntimeException('Failed to remove TLSStore "'.self::TLSSTORE_NAME."\" ({$api}) in {$ns}");
                }
            }

            $secret = $k8s->getResourceStatus('v1', 'Secret', self::SECRET_NAME, $ns);
            if ($secret && ($secret['metadata']['labels'][self::MANAGED_LABEL] ?? null) === 'runr'
                && ! $k8s->deleteResource('v1', 'Secret', self::SECRET_NAME, $ns)) {
                throw new \RuntimeException('Failed to remove Secret '.self::SECRET_NAME." in {$ns}");
            }
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'mode' => __('api.region.function_tls_sync_failed', ['message' => $e->getMessage()]),
            ]);
        }

        $region->update([
            'function_tls_mode' => null,
            'function_tls_secret_name' => null,
            'function_tls_expires_at' => null,
        ]);

        return $region->fresh();
    }

    /**
     * The region's function TLS on its (new) cluster after a migration: the console's
     * certificate again (console mode, when the new cluster is RunR's own), or the uploaded one
     * read from the cluster it moved from ($sourceKubeconfig; custom mode — RunR keeps uploaded
     * certificates only in the Secret on the cluster). Nothing to do without a mode.
     *
     * @return string|null what happened, for the log; null without function TLS
     */
    public function reapply(KnativeRegion $region, ?string $sourceKubeconfig): ?string
    {
        if (! $region->function_tls_mode) {
            return null;
        }

        if ($region->function_tls_mode === 'console') {
            $this->applyConsole($region);

            return 'function TLS: the console certificate applied';
        }

        if (! $sourceKubeconfig) {
            return 'function TLS: not applied, no previous cluster to copy the uploaded certificate from';
        }

        $ns = $region->knative_serving_namespace ?: 'knative-serving';
        $secret = (new KubernetesClient($sourceKubeconfig))->getResourceStatus('v1', 'Secret', self::SECRET_NAME, $ns);
        $crt = isset($secret['data']['tls.crt']) ? base64_decode($secret['data']['tls.crt']) : null;
        $key = isset($secret['data']['tls.key']) ? base64_decode($secret['data']['tls.key']) : null;
        if (! $crt || ! $key) {
            return 'function TLS: not applied, the previous cluster has no '.self::SECRET_NAME.' Secret to copy (upload the certificate again)';
        }

        $this->applyCustom($region, $crt, $key, true);

        return 'function TLS: the uploaded certificate copied from the previous cluster';
    }

    private function syncOrFail(KnativeRegion $region, string $certificatePem, string $privateKeyPem, bool $replaceExisting)
    {
        [$cert, $key] = $this->validatedFunctionTls($region, $certificatePem, $privateKeyPem);

        try {
            return $this->syncFunctionTls($region, $cert, $key, $replaceExisting);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'mode' => __('api.region.function_tls_sync_failed', ['message' => $e->getMessage()]),
            ]);
        }
    }
}
