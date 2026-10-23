<?php

namespace App\Services\Secrets\OpenBao;

use App\Models\Project;
use App\Models\RegionSecretSetting;
use App\Services\Manifests\KnativeManifests;
use App\Services\Secrets\External\ExternalSecretManifests;
use App\Services\Secrets\SecretScope;

/**
 * Who may read what in the region's OpenBao:
 *
 *  - RunR (role runr-control, policy runr-control): the projects' values under
 *    runr/data/projects/*, the projects' policies and roles, the database secrets engine's
 *    connections and roles of bindings (runr-*). Not the auth methods, not the mounts, not its own
 *    policy: those are set once by the install with the root token, which is then revoked.
 *  - a project (role and policy runr-project-<project id>, bound to the service account
 *    runr-bao-<project id> in the region's project namespace): read its own values and the
 *    database credentials of its dynamic bindings, nothing else. External Secrets Operator logs in
 *    as that account (the project's SecretStore runr-bao-<project id>).
 */
final class OpenBaoAccess
{
    public const CONTROL_POLICY = <<<'HCL'
path "runr/data/projects/*" {
  capabilities = ["create", "read", "update", "patch", "delete"]
}
path "runr/metadata/projects/*" {
  capabilities = ["read", "delete", "list"]
}
path "sys/policies/acl/runr-project-*" {
  capabilities = ["create", "read", "update", "delete"]
}
path "auth/kubernetes/role/runr-project-*" {
  capabilities = ["create", "read", "update", "delete"]
}
path "database/config/runr-*" {
  capabilities = ["create", "read", "update", "delete"]
}
path "database/roles/runr-*" {
  capabilities = ["create", "read", "update", "delete"]
}
path "database/rotate-root/runr-*" {
  capabilities = ["update"]
}
path "sys/leases/revoke-prefix/database/creds/runr-*" {
  capabilities = ["update", "sudo"]
}
HCL;

    /** OpenBao path of a project's values (under the KV mount). */
    public static function projectPath(Project $project): string
    {
        return 'projects/'.strtolower((string) $project->id);
    }

    /** Name of the project's policy, Kubernetes auth role, service account and SecretStore. */
    public static function projectName(Project $project): string
    {
        return 'runr-project-'.strtolower((string) $project->id);
    }

    public static function accountName(Project $project): string
    {
        return 'runr-bao-'.strtolower((string) $project->id);
    }

    /**
     * The first configuration, with the root token (OpenBaoInstallService): Kubernetes auth
     * against the cluster it runs in, the KV v2 mount runr/, the database secrets engine and
     * RunR's own policy and role. The audit log to the container's output is declared in the
     * server's configuration (OpenBaoManifests::config(): OpenBao 2.4 enables none through its API).
     */
    public static function configure(OpenBaoClient $root): void
    {
        $mounts = (array) ($root->read('sys/mounts') ?? []);
        $auths = (array) ($root->read('sys/auth') ?? []);
        $has = fn (array $list, string $key) => array_key_exists($key, $list) || array_key_exists($key, (array) ($list['data'] ?? []));

        if (! $has($auths, 'kubernetes/')) {
            $root->write('sys/auth/kubernetes', ['type' => 'kubernetes']);
        }
        // In-cluster: OpenBao reviews tokens with its own service account (TokenReview)
        $root->write('auth/kubernetes/config', ['kubernetes_host' => 'https://kubernetes.default.svc']);

        if (! $has($mounts, OpenBaoClient::KV_MOUNT.'/')) {
            $root->write('sys/mounts/'.OpenBaoClient::KV_MOUNT, ['type' => 'kv', 'options' => ['version' => '2']]);
        }
        if (! $has($mounts, 'database/')) {
            $root->write('sys/mounts/database', ['type' => 'database']);
        }

        $root->putPolicy(OpenBaoClient::CONTROL, self::CONTROL_POLICY);
        $root->write('auth/kubernetes/role/'.OpenBaoClient::CONTROL, [
            'bound_service_account_names' => [OpenBaoClient::CONTROL],
            'bound_service_account_namespaces' => [OpenBaoClient::NAMESPACE],
            'token_policies' => [OpenBaoClient::CONTROL],
            'token_ttl' => (int) config('secrets.openbao.token_ttl', 600),
            'token_max_ttl' => (int) config('secrets.openbao.token_ttl', 600),
        ]);
    }

    /**
     * The project's policy and role, written with RunR's token: read its values and the database
     * credentials of $dynamicRoles (DynamicDbCredentials::roleName()).
     *
     * @param  list<string>  $dynamicRoles
     */
    public static function ensureProject(OpenBaoClient $client, SecretScope $scope, array $dynamicRoles = []): void
    {
        $name = self::projectName($scope->project);
        $policy = 'path "'.OpenBaoClient::KV_MOUNT.'/data/'.self::projectPath($scope->project).'" {'."\n  capabilities = [\"read\"]\n}\n";
        foreach ($dynamicRoles as $role) {
            $policy .= 'path "database/creds/'.$role.'" {'."\n  capabilities = [\"read\"]\n}\n";
        }

        $client->putPolicy($name, $policy);
        $client->write('auth/kubernetes/role/'.$name, [
            'bound_service_account_names' => [self::accountName($scope->project)],
            'bound_service_account_namespaces' => [$scope->namespace],
            'token_policies' => [$name],
            'token_ttl' => (int) config('secrets.openbao.token_ttl', 600),
            'token_max_ttl' => (int) config('secrets.openbao.token_ttl', 600),
        ]);
    }

    /** The project's policy and role go (its values: OpenBaoSecretBackend::purge()). */
    public static function forgetProject(OpenBaoClient $client, Project $project): void
    {
        $client->delete('auth/kubernetes/role/'.self::projectName($project));
        $client->deletePolicy(self::projectName($project));
    }

    /**
     * The project's identity in the region and the SecretStore that logs in with it: the provider
     * block of External Secrets Operator's Vault provider (OpenBao speaks the same API).
     */
    public static function workloadManifests(SecretScope $scope, RegionSecretSetting $settings): array
    {
        $labels = $scope->labels([ExternalSecretManifests::LABEL => 'openbao']);

        return [
            [
                'apiVersion' => 'v1', 'kind' => 'ServiceAccount',
                'metadata' => ['name' => self::accountName($scope->project), 'namespace' => $scope->namespace, 'labels' => $labels],
                'automountServiceAccountToken' => false,
            ],
            [
                'apiVersion' => ExternalSecretManifests::API_VERSION, 'kind' => 'SecretStore',
                'metadata' => ['name' => self::accountName($scope->project), 'namespace' => $scope->namespace, 'labels' => $labels],
                'spec' => ['provider' => ['vault' => self::vaultProvider($scope, $settings)]],
            ],
        ];
    }

    /** ESO's Vault provider block for the project's identity (also the dynamic credentials' generator). */
    public static function vaultProvider(SecretScope $scope, RegionSecretSetting $settings): array
    {
        return array_filter([
            'server' => 'https://'.OpenBaoClient::SERVICE.'.'.OpenBaoClient::NAMESPACE.'.svc:'.OpenBaoClient::PORT,
            'path' => OpenBaoClient::KV_MOUNT,
            'version' => 'v2',
            'caBundle' => $settings->openbao_ca ? base64_encode((string) $settings->openbao_ca) : null,
            'auth' => ['kubernetes' => [
                'mountPath' => 'kubernetes',
                'role' => self::projectName($scope->project),
                'serviceAccountRef' => ['name' => self::accountName($scope->project)],
            ]],
        ], fn ($v) => $v !== null);
    }

    /** Labels of the organization (runr/tenant) for objects that belong to no project. */
    public static function contextLabels(): array
    {
        return KnativeManifests::contextLabels();
    }
}
