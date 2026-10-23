<?php

namespace App\Services\Secrets\External;

use App\Models\KnativeRegion;
use App\Models\SecretStore;
use App\Services\KubernetesClient;
use Firebase\JWT\JWK;
use RuntimeException;
use Throwable;

/**
 * What a secret store has to trust so the region can read from it without a credential
 * (docs/secret-stores.md): the region cluster's service account issuer — read from the API
 * server's discovery document (/.well-known/openid-configuration) and its keys (/openid/v1/jwks)
 * — and the service account a store logs in with, in the region's project namespace.
 *
 * The keys are public. They are shown as the JWKS and as PEM keys (Vault / OpenBao JWT auth takes
 * them as jwt_validation_pubkeys when the issuer is not reachable from the store).
 */
class ClusterIssuer
{
    /**
     * @return array{issuer: string, jwks_uri: string|null, jwks: array, pem_keys: list<string>, publicly_reachable: bool}
     *
     * @throws RuntimeException the cluster did not answer
     */
    public function of(KnativeRegion $region): array
    {
        if (! $region->kubeconfig || $region->is_demo) {
            throw new RuntimeException(__('api.region.kubeconfig_missing'));
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $discovery = $k8s->getPath('/.well-known/openid-configuration');
            $jwks = $k8s->getPath('/openid/v1/jwks');
        } catch (Throwable $e) {
            throw new RuntimeException(__('api.secret.region_unreachable', ['region' => $region->name]), 0, $e);
        }
        if (! is_array($discovery) || empty($discovery['issuer'])) {
            throw new RuntimeException(__('api.secret_store.issuer_unknown', ['region' => $region->name]));
        }

        $issuer = (string) $discovery['issuer'];

        return [
            'issuer' => $issuer,
            'jwks_uri' => isset($discovery['jwks_uri']) ? (string) $discovery['jwks_uri'] : null,
            'jwks' => is_array($jwks) ? $jwks : ['keys' => []],
            'pem_keys' => self::pemKeys(is_array($jwks) ? $jwks : []),
            'publicly_reachable' => self::looksPublic($issuer),
        ];
    }

    /**
     * Everything a store's administrator needs for the region: the issuer and keys, the service
     * account (name, namespace, subject) and the token audience.
     */
    public function trust(SecretStore $store, KnativeRegion $region): array
    {
        $issuer = $this->of($region);
        $namespace = (string) ($region->project_namespace ?? $region->name);
        $account = $store->kubernetesName();

        return $issuer + [
            'region' => ['id' => $region->id, 'name' => $region->name],
            'namespace' => $namespace,
            'service_account' => $account,
            'subject' => "system:serviceaccount:{$namespace}:{$account}",
            'audience' => $store->audience(),
        ];
    }

    /** The objects a store needs in a region, with its status, applied now (POST …/verify). */
    public function verify(SecretStore $store, KnativeRegion $region): array
    {
        $namespace = (string) ($region->project_namespace ?? $region->name);
        $k8s = new KubernetesClient((string) $region->kubeconfig);

        foreach ([ExternalSecretManifests::serviceAccount($store, $namespace), ExternalSecretManifests::secretStore($store, $namespace)] as $manifest) {
            if (! $k8s->applyResource($manifest, $namespace)) {
                throw new RuntimeException("Failed to apply {$manifest['kind']} {$manifest['metadata']['name']}");
            }
        }
        $binding = ExternalSecretManifests::tokenReviewBinding($store, $namespace);
        if ($binding !== null && ! $k8s->applyClusterResource($binding)) {
            throw new RuntimeException("Failed to apply ClusterRoleBinding {$binding['metadata']['name']}");
        }

        $live = $k8s->getResourceOrFail(ExternalSecretManifests::API_VERSION, 'SecretStore', $store->kubernetesName(), $namespace);

        return self::readyCondition($live);
    }

    /**
     * The Ready condition of an ESO object: status ready | failed | pending and its message.
     *
     * @return array{status: string, message: string|null}
     */
    public static function readyCondition(?array $object): array
    {
        foreach ((array) ($object['status']['conditions'] ?? []) as $condition) {
            if (($condition['type'] ?? null) !== 'Ready') {
                continue;
            }

            return [
                'status' => match ($condition['status'] ?? null) {
                    'True' => 'ready',
                    'False' => 'failed',
                    default => 'pending',
                },
                'message' => isset($condition['message']) ? mb_substr((string) $condition['message'], 0, 1000) : null,
            ];
        }

        return ['status' => 'pending', 'message' => null];
    }

    /**
     * The RSA / EC keys of a JWKS as PEM public keys.
     *
     * @return list<string>
     */
    public static function pemKeys(array $jwks): array
    {
        if (empty($jwks['keys'])) {
            return [];
        }

        $pems = [];
        try {
            foreach (JWK::parseKeySet($jwks, 'RS256') as $key) {
                $details = openssl_pkey_get_details($key->getKeyMaterial());
                if (is_array($details) && ! empty($details['key'])) {
                    $pems[] = trim((string) $details['key']);
                }
            }
        } catch (Throwable) {
            // A key set RunR cannot read: the JWKS is shown as it is
        }

        return $pems;
    }

    /**
     * Whether a cloud provider could fetch the issuer's discovery document: https, and not a
     * cluster-internal or private address. A hint only — the provider's own check decides.
     */
    public static function looksPublic(string $issuer): bool
    {
        $parts = parse_url($issuer);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? null) !== 'https' || $host === '') {
            return false;
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.svc')
            || str_contains($host, '.svc.') || str_ends_with($host, '.internal')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return str_contains($host, '.');
    }
}
