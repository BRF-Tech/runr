<?php

namespace App\Services\Secrets\External;

use App\Models\ProjectSecret;
use App\Models\SecretStore;
use App\Services\Manifests\KnativeManifests;
use App\Services\Secrets\SecretScope;
use Illuminate\Support\Collection;

/**
 * The objects External Secrets Operator works from (docs/secret-stores.md), in a region's project
 * namespace — none of them holds a value or a credential:
 *
 *  - per store a ServiceAccount runr-ss-<store id>: the identity the store trusts (Vault / OpenBao
 *    Kubernetes or JWT auth: its name and namespace; the clouds: the annotations of their workload
 *    identity federation). ESO asks the API server for a short-lived token of it (TokenRequest)
 *    each time it logs in;
 *  - per store a SecretStore runr-ss-<store id>: the provider, its address and the auth method
 *    with that service account;
 *  - per project an ExternalSecret <slug>-ext-env: one entry per reference (store + path + key),
 *    filling the Secret <slug>-ext-env (owned by it: deleting the ExternalSecret deletes it).
 */
final class ExternalSecretManifests
{
    public const API_VERSION = 'external-secrets.io/v1';

    /** Label of everything this class renders; the status sync lists ExternalSecrets by it. */
    public const LABEL = 'runr/secret-source';

    public static function serviceAccount(SecretStore $store, string $namespace): array
    {
        $annotations = match ($store->provider) {
            // IRSA-style federation: ESO assumes the role with the account's token (AssumeRoleWithWebIdentity)
            'aws' => array_filter([
                'eks.amazonaws.com/role-arn' => (string) $store->setting('role_arn'),
                'eks.amazonaws.com/audience' => (string) $store->audience(),
            ]),
            'azure' => array_filter([
                'azure.workload.identity/client-id' => (string) $store->setting('client_id'),
                'azure.workload.identity/tenant-id' => (string) $store->setting('tenant_id'),
            ]),
            'gcp' => array_filter([
                'iam.gke.io/gcp-service-account' => (string) $store->setting('service_account_email', ''),
            ]),
            default => [],
        };

        return [
            'apiVersion' => 'v1',
            'kind' => 'ServiceAccount',
            'metadata' => array_filter([
                'name' => $store->kubernetesName(),
                'namespace' => $namespace,
                'labels' => self::storeLabels($store),
                'annotations' => $annotations ?: null,
            ]),
            // ESO requests its tokens; no pod mounts this account
            'automountServiceAccountToken' => false,
        ];
    }

    /**
     * Vault / OpenBao Kubernetes auth: the store checks each token of the store's service account
     * with the cluster's TokenReview API — with that same token when its auth method has no
     * token_reviewer_jwt, so no credential is kept anywhere. The account may create TokenReviews
     * (system:auth-delegator) through this binding, one per store and project namespace. Null for
     * every other store.
     */
    public static function tokenReviewBinding(SecretStore $store, string $namespace): ?array
    {
        if ($store->provider !== 'vault' || $store->auth !== 'kubernetes') {
            return null;
        }

        return [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'ClusterRoleBinding',
            'metadata' => [
                'name' => self::tokenReviewBindingName($store, $namespace),
                'labels' => self::storeLabels($store),
            ],
            'roleRef' => ['apiGroup' => 'rbac.authorization.k8s.io', 'kind' => 'ClusterRole', 'name' => 'system:auth-delegator'],
            'subjects' => [['kind' => 'ServiceAccount', 'name' => $store->kubernetesName(), 'namespace' => $namespace]],
        ];
    }

    public static function tokenReviewBindingName(SecretStore $store, string $namespace): string
    {
        return mb_substr($store->kubernetesName().'-'.$namespace.'-tokenreview', 0, 253);
    }

    public static function secretStore(SecretStore $store, string $namespace): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'SecretStore',
            'metadata' => [
                'name' => $store->kubernetesName(),
                'namespace' => $namespace,
                'labels' => self::storeLabels($store),
            ],
            'spec' => [
                'provider' => [self::providerKey($store) => self::provider($store)],
            ],
        ];
    }

    /**
     * The project's ExternalSecret: every reference of $secrets (store relation loaded).
     *
     * @param  Collection<int, ProjectSecret>  $secrets
     */
    public static function externalSecret(SecretScope $scope, Collection $secrets): array
    {
        $data = [];
        $interval = null;
        $first = null;

        foreach ($secrets as $secret) {
            $store = $secret->store;
            if (! $store instanceof SecretStore) {
                continue;
            }
            $first ??= $store;
            $interval = $interval === null ? $store->refreshSeconds() : min($interval, $store->refreshSeconds());

            $remoteRef = array_filter([
                'key' => self::remoteKey($store, (string) $secret->remote_path),
                'property' => $secret->remote_key ?: null,
            ], fn ($v) => $v !== null && $v !== '');

            $data[] = [
                'secretKey' => $secret->name,
                'remoteRef' => $remoteRef,
                'sourceRef' => ['storeRef' => ['name' => $store->kubernetesName(), 'kind' => 'SecretStore']],
            ];
        }

        $spec = [
            'refreshInterval' => ($interval ?? (int) config('secrets.external.refresh_interval', 3600)).'s',
            'target' => [
                'name' => $scope->externalName,
                'creationPolicy' => 'Owner',
                'template' => [
                    'metadata' => ['labels' => $scope->labels([self::LABEL => 'external'])],
                ],
            ],
            'data' => $data,
        ];
        if ($first) {
            $spec['secretStoreRef'] = ['name' => $first->kubernetesName(), 'kind' => 'SecretStore'];
        }

        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'ExternalSecret',
            'metadata' => [
                'name' => $scope->externalName,
                'namespace' => $scope->namespace,
                'labels' => $scope->labels([self::LABEL => 'external']),
                'annotations' => $scope->annotations(),
            ],
            'spec' => $spec,
        ];
    }

    /** The provider block of the SecretStore spec. */
    public static function provider(SecretStore $store): array
    {
        $account = $store->kubernetesName();
        $ttl = (int) config('secrets.external.token_ttl', 600);

        return match ($store->provider) {
            'vault' => array_filter([
                'server' => (string) $store->setting('server'),
                'path' => (string) $store->setting('mount', 'secret'),
                'version' => (string) $store->setting('kv_version', 'v2'),
                'namespace' => $store->setting('vault_namespace'),
                'caBundle' => $store->setting('ca_bundle') ? base64_encode((string) $store->setting('ca_bundle')) : null,
                'auth' => $store->auth === 'jwt'
                    ? ['jwt' => [
                        'path' => (string) $store->setting('auth_mount', 'jwt'),
                        'role' => (string) $store->setting('role'),
                        'kubernetesServiceAccountToken' => [
                            'serviceAccountRef' => ['name' => $account],
                            'audiences' => [(string) $store->audience()],
                            'expirationSeconds' => $ttl,
                        ],
                    ]]
                    : ['kubernetes' => [
                        'mountPath' => (string) $store->setting('auth_mount', 'kubernetes'),
                        'role' => (string) $store->setting('role'),
                        'serviceAccountRef' => ['name' => $account],
                    ]],
            ], fn ($v) => $v !== null && $v !== ''),
            'aws' => [
                'service' => 'SecretsManager',
                'region' => (string) $store->setting('region'),
                'auth' => ['jwt' => ['serviceAccountRef' => ['name' => $account]]],
            ],
            'gcp' => [
                'projectID' => (string) $store->setting('project_id'),
                'auth' => ['workloadIdentityFederation' => [
                    'audience' => (string) $store->audience(),
                    'serviceAccountRef' => ['name' => $account, 'audiences' => [(string) $store->audience()]],
                ]],
            ],
            'azure' => [
                'authType' => 'WorkloadIdentity',
                'vaultUrl' => (string) $store->setting('vault_url'),
                'tenantId' => (string) $store->setting('tenant_id'),
                'serviceAccountRef' => ['name' => $account],
            ],
            default => throw new \InvalidArgumentException("Unknown secret store provider {$store->provider}"),
        };
    }

    /** The key of the provider block: vault, aws, gcpsm, azurekv. */
    public static function providerKey(SecretStore $store): string
    {
        return match ($store->provider) {
            'gcp' => 'gcpsm',
            'azure' => 'azurekv',
            default => $store->provider,
        };
    }

    /**
     * The remote key of a reference as the provider reads it. Azure Key Vault names the object
     * type in front ("secret/<name>"); the others take the path as it is.
     */
    public static function remoteKey(SecretStore $store, string $path): string
    {
        $path = trim($path);
        if ($store->provider === 'azure' && ! preg_match('#^(secret|key|cert)/#', $path)) {
            return 'secret/'.$path;
        }

        return $path;
    }

    /**
     * Not a project's object: no runr/project-id (a project teardown leaves the store to the
     * others); the organization's labels (runr/tenant: the tenant deletion sweep removes it).
     */
    private static function storeLabels(SecretStore $store): array
    {
        return array_merge([
            'app.kubernetes.io/managed-by' => 'runr',
            self::LABEL => 'store',
            'runr/secret-store' => strtolower((string) $store->id),
        ], KnativeManifests::contextLabels());
    }
}
