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
