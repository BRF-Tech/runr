<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use App\Services\Helpers\PemHelper;
use App\Services\KubernetesClient;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Applies a TLS certificate for the *function*-domain Traefik — the region's own
 * Traefik running in its own knative-serving namespace, which can be a different
 * cluster than the console. Reached only through the region's kubeconfig, never
 * through the Helm chart (a chart release can't reach a different cluster, and can't
 * own something it doesn't install).
 *
 * 100% opt-in: syncFunctionTls() is only ever called from
 * KnativeFunctionTlsService, itself only reachable from the explicit
 * PUT /knative-regions/{id}/function-tls endpoint. It must never run as a side effect
 * of install/patch/reconcile (KnativeInstallService, cluster:patch-observability,
 * region:reconcile, …) — a region that never calls that endpoint must have its Traefik
 * TLSStore "default" completely untouched by this code.
 *
 * Secret name "runr-function-tls" is deliberate: it must not collide with the
 * console's own Ingress TLS Secrets ("runr-tls" / "runr-tls-custom", rendered by
 * helm/templates/tls-secret.yaml into the chart's release namespace) — those are
 * owned by the Helm release; this Secret lives in the region's knative-serving
 * namespace and is owned by RunR's own apply calls, which may be a different cluster
 * entirely.
 *
 * TLSStore name "default" is not a choice — it's the Traefik convention for the
 * fallback certificate used when no other TLSStore/Router matches by SNI. Traefik's
 * CRD provider watches every namespace and honours one "default" TLSStore per cluster,
 * so RunR only writes it when no other namespace has one, and only replaces one it did
 * not create (label app.kubernetes.io/managed-by=runr) when the caller says so.
 */
trait RegionFunctionTlsTrait
{
    private const SECRET_NAME = 'runr-function-tls';

    private const TLSSTORE_NAME = 'default';

    /** Current and legacy (Traefik v2) API groups of the TLSStore CRD. */
    private const TLSSTORE_APIS = ['traefik.io/v1alpha1', 'traefik.containo.us/v1alpha1'];

    private const MANAGED_LABEL = 'app.kubernetes.io/managed-by';

    /**
     * Normalized PEMs, once they are shown to serve the region's function domain: the key
     * belongs to the certificate, the certificate has not expired, and its SANs contain
     * *.<knative_domain> (functions are served at <name>.<knative_domain>).
     *
     * @return array{0: string, 1: string} [certificate PEM, private key PEM]
     *
     * @throws ValidationException
     */
    protected function validatedFunctionTls(KnativeRegion $region, string $certificatePem, string $privateKeyPem): array
    {
        try {
            $cert = PemHelper::normalize($certificatePem, 'CERTIFICATE');
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['certificate' => $e->getMessage()]);
        }

        try {
            $key = PemHelper::normalize($privateKeyPem, 'PRIVATE KEY');
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['privateKey' => $e->getMessage()]);
        }

        $parsed = @openssl_x509_parse($cert);
        if (! $parsed) {
            throw ValidationException::withMessages([
                'certificate' => __('api.region.function_tls_pem_invalid', ['marker' => 'CERTIFICATE']),
            ]);
        }

        if (! @openssl_x509_check_private_key($cert, $key)) {
            throw ValidationException::withMessages(['privateKey' => __('api.region.function_tls_key_mismatch')]);
        }

        $notAfter = (int) ($parsed['validTo_time_t'] ?? 0);
        if ($notAfter <= time()) {
            throw ValidationException::withMessages([
                'certificate' => __('api.region.function_tls_expired', ['date' => Carbon::createFromTimestamp($notAfter, 'UTC')->toDateString()]),
            ]);
        }

        $domain = strtolower(trim((string) ($region->effective_settings['knative_domain'] ?? ''), '. '));
        if ($domain === '') {
            throw ValidationException::withMessages(['certificate' => __('api.region.function_tls_no_domain')]);
        }

        $names = self::certificateDnsNames($parsed);
        if (! in_array('*.'.$domain, $names, true)) {
            throw ValidationException::withMessages([
                'certificate' => __('api.region.function_tls_domain_mismatch', [
                    'domain' => $domain,
                    'names' => $names ? implode(', ', $names) : '-',
                ]),
            ]);
        }

        return [$cert, $key];
    }

    /**
     * DNS names in the certificate's subjectAltName, lower-cased.
     *
     * @return list<string>
     */
    public static function certificateDnsNames(array $parsedCertificate): array
    {
        $san = (string) ($parsedCertificate['extensions']['subjectAltName'] ?? '');
        preg_match_all('/DNS:([^,\s]+)/', $san, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /**
     * @param  string  $certificatePem  normalized, validated (validatedFunctionTls)
     * @param  string  $privateKeyPem  normalized, validated
     * @return Carbon|null the certificate's notAfter, parsed from the PEM
     *
     * @throws ValidationException a "default" TLSStore elsewhere, or a foreign one without $replaceExisting
     * @throws RuntimeException the region's cluster refused the apply
     */
    protected function syncFunctionTls(KnativeRegion $region, string $certificatePem, string $privateKeyPem, bool $replaceExisting = false): ?Carbon
    {
        $ns = $region->knative_serving_namespace ?? 'knative-serving';
        $k8s = new KubernetesClient($region->kubeconfig);

        $foreignApis = $this->foreignDefaultTlsStores($k8s, $ns, $replaceExisting);

        if (! $k8s->applyResource($this->functionTlsSecret($ns, $certificatePem, $privateKeyPem), $ns)) {
            throw new RuntimeException('Failed to apply '.self::SECRET_NAME." Secret in {$ns}");
        }

        // Replacing a TLSStore RunR did not create: remove it, so none of its other fields
        // (certificates, defaultGeneratedCert, a legacy-group twin) stays merged into ours
        foreach ($foreignApis as $api) {
            if (! $k8s->deleteResource($api, 'TLSStore', self::TLSSTORE_NAME, $ns)) {
                throw new RuntimeException('Failed to replace TLSStore "'.self::TLSSTORE_NAME."\" ({$api}) in {$ns}");
            }
        }

        if (! $k8s->applyResource($this->functionTlsStore($ns), $ns)) {
            throw new RuntimeException('Failed to apply TLSStore "'.self::TLSSTORE_NAME."\" in {$ns}");
        }

        return PemHelper::certificateExpiresAt($certificatePem);
    }

    /**
     * Every "default" TLSStore in the cluster (both API groups). One in another namespace is a
     * conflict; one in $ns without RunR's label needs $replaceExisting.
     *
     * @return list<string> API versions of the foreign "default" TLSStores in $ns to replace
     *
     * @throws ValidationException
     */
    private function foreignDefaultTlsStores(KubernetesClient $k8s, string $ns, bool $replaceExisting): array
    {
        $foreign = [];

        foreach (self::TLSSTORE_APIS as $api) {
            $group = explode('/', $api)[0];
            // Group not served (e.g. no legacy traefik.containo.us CRDs): nothing to find there
            if (! $k8s->getClusterResource('apiextensions.k8s.io/v1', 'CustomResourceDefinition', "tlsstores.{$group}")) {
                continue;
            }

            foreach ($k8s->listClusterResources($api, 'TLSStore')['items'] ?? [] as $store) {
                if (($store['metadata']['name'] ?? null) !== self::TLSSTORE_NAME) {
                    continue;
                }

                $storeNs = $store['metadata']['namespace'] ?? '';
                if ($storeNs !== $ns) {
                    throw ValidationException::withMessages([
                        'mode' => __('api.region.function_tls_store_conflict', ['namespace' => $storeNs]),
                    ]);
                }

                if (($store['metadata']['labels'][self::MANAGED_LABEL] ?? null) !== 'runr') {
                    if (! $replaceExisting) {
                        throw ValidationException::withMessages([
                            'replace_existing' => __('api.region.function_tls_store_replace_required', ['namespace' => $ns]),
                        ]);
                    }
                    $foreign[] = $api;
                }
            }
        }

        return $foreign;
    }

    private function functionTlsSecret(string $ns, string $certPem, string $keyPem): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => self::SECRET_NAME,
                'namespace' => $ns,
                'labels' => [self::MANAGED_LABEL => 'runr'],
            ],
            'type' => 'kubernetes.io/tls',
            'data' => [
                'tls.crt' => base64_encode($certPem),
                'tls.key' => base64_encode($keyPem),
            ],
        ];
    }

    private function functionTlsStore(string $ns): array
    {
        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'TLSStore',
            'metadata' => [
                'name' => self::TLSSTORE_NAME,
                'namespace' => $ns,
                'labels' => [self::MANAGED_LABEL => 'runr'],
            ],
            'spec' => [
                'defaultCertificate' => ['secretName' => self::SECRET_NAME],
            ],
        ];
    }
}
