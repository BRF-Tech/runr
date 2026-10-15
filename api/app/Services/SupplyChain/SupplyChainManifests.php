<?php

namespace App\Services\SupplyChain;

use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;

/**
 * Kubernetes resources of the supply chain in a region, all in the supply-chain namespace
 * (config supply_chain.namespace, default runr-supply-chain) — never in the project namespace,
 * so the signing key is out of reach of project workloads and the admission policy (which
 * selects project namespaces only) never applies to these Jobs:
 *
 *  - pipelineJob(): one run of the tools image for one image (sbom, attach, scan, sign, attest)
 *  - keygenJob() + keygenRbac(): `cosign generate-key-pair k8s://…` writes the key Secret
 *  - mirrorJob(): copies the Trivy DB into the region registry (offline / air-gapped scanning)
 *  - keySecret() / registryAuthSecret(): restored key pair, registry credentials
 */
class SupplyChainManifests
{
    public const LABEL = 'runr/supply-chain';

    public const WORK_DIR = '/work';

    public const CACHE_DIR = '/cache';

    public const KEYS_DIR = '/keys';

    public const AUTH_DIR = '/docker';

    public const REGISTRY_AUTH_SECRET = 'runr-supply-chain-registry';

    public const KEYGEN_ACCOUNT = 'runr-supply-chain-keygen';

    public const KEYGEN_PASSWORD = 'runr-cosign-keygen';

    public static function namespace(): string
    {
        return (string) config('supply_chain.namespace', 'runr-supply-chain');
    }

    public static function keySecretName(): string
    {
        return (string) config('supply_chain.key_secret', 'runr-cosign-key');
    }

    /** Tools image for a region (a registry mirror keeps Docker Hub short names local; ghcr.io stays as it is). */
    public static function toolsImage(?KnativeRegion $region): string
    {
        $image = (string) config('supply_chain.tools_image');

        return $region ? $region->registryImageRef($image) : $image;
    }

    public static function namespaceManifest(): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => self::namespace(),
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', self::LABEL => 'true'],
            ],
        ];
    }

    /** Whether the region registry speaks plain HTTP (RunR's internal Zot). */
    public static function plainHttp(KnativeRegion $region): bool
    {
        return $region->registry_mode === 'internal';
    }

    /**
     * Job of one supply-chain run.
     *
     * @param  list<string>  $steps  sbom | attach | scan | sign | attest
     */
    public static function pipelineJob(
        string $name,
        KnativeRegion $region,
        RegionSupplyChainSetting $settings,
        string $image,
        array $steps,
        string $reportId,
        bool $registryAuth,
        bool $internalImage,
    ): array {
        $signing = (bool) array_intersect($steps, ['sign', 'attest']);
        $plain = $internalImage && self::plainHttp($region);

        $env = [
            ['name' => 'IMAGE', 'value' => $image],
            ['name' => 'RUNR_STEPS', 'value' => implode(',', $steps)],
            ['name' => 'RUNR_PLAIN_HTTP', 'value' => $plain ? 'true' : 'false'],
            ['name' => 'RUNR_MAX_INLINE_BYTES', 'value' => (string) config('supply_chain.job.max_inline_bytes')],
            ['name' => 'TRIVY_CACHE_DIR', 'value' => self::CACHE_DIR],
            ['name' => 'TRIVY_NO_PROGRESS', 'value' => 'true'],
            ['name' => 'SYFT_CHECK_FOR_APP_UPDATE', 'value' => 'false'],
        ];
        $env = array_merge($env, self::scannerDbEnv($region, $settings));

        if ($plain) {
            $env[] = ['name' => 'TRIVY_INSECURE', 'value' => 'true'];
            $env[] = ['name' => 'SYFT_REGISTRY_INSECURE_USE_HTTP', 'value' => 'true'];
        }

        $mounts = [
            ['name' => 'work', 'mountPath' => self::WORK_DIR],
            ['name' => 'cache', 'mountPath' => self::CACHE_DIR],
            ['name' => 'tmp', 'mountPath' => '/tmp'],
        ];
        $volumes = [
            ['name' => 'work', 'emptyDir' => ['sizeLimit' => config('supply_chain.job.scratch_size_limit')]],
            ['name' => 'cache', 'emptyDir' => new \stdClass],
            ['name' => 'tmp', 'emptyDir' => ['sizeLimit' => config('supply_chain.job.scratch_size_limit')]],
        ];

        if ($signing) {
            $env[] = ['name' => 'RUNR_COSIGN_KEY', 'value' => self::KEYS_DIR.'/cosign.key'];
            $env[] = ['name' => 'RUNR_COSIGN_PUB', 'value' => self::KEYS_DIR.'/cosign.pub'];
            $env[] = ['name' => 'COSIGN_PASSWORD', 'valueFrom' => ['secretKeyRef' => ['name' => self::keySecretName(), 'key' => 'cosign.password']]];
            $mounts[] = ['name' => 'keys', 'mountPath' => self::KEYS_DIR, 'readOnly' => true];
            $volumes[] = ['name' => 'keys', 'secret' => ['secretName' => self::keySecretName(), 'defaultMode' => 0400]];
        }

        if ($registryAuth) {
            $env[] = ['name' => 'DOCKER_CONFIG', 'value' => self::AUTH_DIR];
            $mounts[] = ['name' => 'registry-auth', 'mountPath' => self::AUTH_DIR, 'readOnly' => true];
            $volumes[] = ['name' => 'registry-auth', 'secret' => [
                'secretName' => self::REGISTRY_AUTH_SECRET,
                'items' => [['key' => '.dockerconfigjson', 'path' => 'config.json']],
            ]];
        }

        $labels = [self::LABEL => 'true', 'runr/supply-chain-report' => strtolower($reportId), 'app.kubernetes.io/managed-by' => 'runr'];

        return self::job($name, $region, $labels, ['pipeline'], $env, $mounts, $volumes, (int) config('supply_chain.job.deadline_seconds'));
    }

    /**
     * Key generation: cosign writes the key pair straight into the key Secret (k8s:// key
     * reference) with the password the API generated — the private key never shows up in a log.
     */
    public static function keygenJob(string $name, KnativeRegion $region): array
    {
        $env = [
            ['name' => 'RUNR_KEY_REF', 'value' => 'k8s://'.self::namespace().'/'.self::keySecretName()],
            ['name' => 'COSIGN_PASSWORD', 'valueFrom' => ['secretKeyRef' => ['name' => self::KEYGEN_PASSWORD, 'key' => 'password']]],
        ];

        $job = self::job($name, $region, [self::LABEL => 'keygen', 'app.kubernetes.io/managed-by' => 'runr'], ['keygen'], $env, [], [], 600);
        $job['spec']['template']['spec']['serviceAccountName'] = self::KEYGEN_ACCOUNT;
        $job['spec']['template']['spec']['automountServiceAccountToken'] = true;

        return $job;
    }

    /** @return list<array> ServiceAccount + Role (Secrets in the supply-chain namespace) + RoleBinding of the key generation */
    public static function keygenRbac(): array
    {
        $ns = self::namespace();
        $meta = fn (string $kind) => ['name' => self::KEYGEN_ACCOUNT, 'namespace' => $ns, 'labels' => ['app.kubernetes.io/managed-by' => 'runr', self::LABEL => 'keygen']];

        return [
            ['apiVersion' => 'v1', 'kind' => 'ServiceAccount', 'metadata' => $meta('ServiceAccount')],
            [
                'apiVersion' => 'rbac.authorization.k8s.io/v1',
                'kind' => 'Role',
                'metadata' => $meta('Role'),
                'rules' => [['apiGroups' => [''], 'resources' => ['secrets'], 'verbs' => ['get', 'create', 'update', 'patch']]],
            ],
            [
                'apiVersion' => 'rbac.authorization.k8s.io/v1',
                'kind' => 'RoleBinding',
                'metadata' => $meta('RoleBinding'),
                'roleRef' => ['apiGroup' => 'rbac.authorization.k8s.io', 'kind' => 'Role', 'name' => self::KEYGEN_ACCOUNT],
                'subjects' => [['kind' => 'ServiceAccount', 'name' => self::KEYGEN_ACCOUNT, 'namespace' => $ns]],
            ],
        ];
    }

    public static function keygenPasswordSecret(string $password): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'Opaque',
            'metadata' => ['name' => self::KEYGEN_PASSWORD, 'namespace' => self::namespace(), 'labels' => [self::LABEL => 'keygen']],
            'data' => ['password' => base64_encode($password)],
        ];
    }

    /** Key Secret restored from the API's encrypted copy (e.g. after a region moved to a new cluster). */
    public static function keySecret(RegionSupplyChainSetting $settings): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'Opaque',
            'metadata' => [
                'name' => self::keySecretName(),
                'namespace' => self::namespace(),
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', self::LABEL => 'signing-key'],
                'annotations' => ['runr.sh/key-fingerprint' => (string) $settings->signing_key_fingerprint],
            ],
            'data' => [
                'cosign.key' => base64_encode((string) $settings->signing_private_key),
                'cosign.pub' => base64_encode((string) $settings->signing_public_key),
                'cosign.password' => base64_encode((string) $settings->signing_key_password),
            ],
        ];
    }

    /** Docker config of the region registry (external registry with credentials). */
    public static function registryAuthSecret(KnativeRegion $region): array
    {
        $auth = base64_encode($region->registry_username.':'.$region->registry_password);

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'kubernetes.io/dockerconfigjson',
            'metadata' => ['name' => self::REGISTRY_AUTH_SECRET, 'namespace' => self::namespace(), 'labels' => [self::LABEL => 'registry-auth']],
            'data' => ['.dockerconfigjson' => base64_encode(json_encode(
                ['auths' => [self::registryHost((string) $region->registry_address) => ['auth' => $auth]]],
                JSON_UNESCAPED_SLASHES,
            ))],
        ];
    }

    /** Trivy DB mirror: copies the vulnerability DBs into the region registry and reports the DB build time. */
    public static function mirrorJob(string $name, KnativeRegion $region, RegionSupplyChainSetting $settings, bool $registryAuth): array
    {
        $plain = self::plainHttp($region);
        $env = [
            ['name' => 'RUNR_DB_SOURCE', 'value' => $settings->scanner_db_source ?: (string) config('supply_chain.scan.db_source')],
            ['name' => 'RUNR_JAVA_DB_SOURCE', 'value' => (string) config('supply_chain.scan.java_db_source')],
            ['name' => 'RUNR_DB_TARGET', 'value' => self::registryPath($region, (string) config('supply_chain.scan.db_repository'))],
            ['name' => 'RUNR_JAVA_DB_TARGET', 'value' => self::registryPath($region, (string) config('supply_chain.scan.java_db_repository'))],
            ['name' => 'RUNR_PLAIN_HTTP', 'value' => $plain ? 'true' : 'false'],
            ['name' => 'TRIVY_CACHE_DIR', 'value' => self::CACHE_DIR],
            ['name' => 'TRIVY_NO_PROGRESS', 'value' => 'true'],
        ];
        if ($plain) {
            $env[] = ['name' => 'TRIVY_INSECURE', 'value' => 'true'];
        }

        $mounts = [['name' => 'cache', 'mountPath' => self::CACHE_DIR], ['name' => 'tmp', 'mountPath' => '/tmp']];
        $volumes = [['name' => 'cache', 'emptyDir' => new \stdClass], ['name' => 'tmp', 'emptyDir' => new \stdClass]];
        if ($registryAuth) {
            $env[] = ['name' => 'DOCKER_CONFIG', 'value' => self::AUTH_DIR];
            $mounts[] = ['name' => 'registry-auth', 'mountPath' => self::AUTH_DIR, 'readOnly' => true];
            $volumes[] = ['name' => 'registry-auth', 'secret' => [
                'secretName' => self::REGISTRY_AUTH_SECRET,
                'items' => [['key' => '.dockerconfigjson', 'path' => 'config.json']],
            ]];
        }

        return self::job($name, $region, [self::LABEL => 'scanner-db', 'app.kubernetes.io/managed-by' => 'runr'], ['mirror-db'], $env, $mounts, $volumes, 1200);
    }

    /**
     * Trivy DB settings of a scan: the region registry's copy in "mirror" and "offline" mode,
     * Trivy's default sources in "online" mode.
     *
     * @return list<array{name: string, value: string}>
     */
    public static function scannerDbEnv(KnativeRegion $region, RegionSupplyChainSetting $settings): array
    {
        if ($settings->scanner_db_mode === 'online' || ! $region->registry_address) {
            return [];
        }

        $env = [
            ['name' => 'TRIVY_DB_REPOSITORY', 'value' => self::registryPath($region, (string) config('supply_chain.scan.db_repository'))],
            ['name' => 'TRIVY_JAVA_DB_REPOSITORY', 'value' => self::registryPath($region, (string) config('supply_chain.scan.java_db_repository'))],
        ];
        if (self::plainHttp($region)) {
            $env[] = ['name' => 'TRIVY_INSECURE', 'value' => 'true'];
        }

        return $env;
    }

    /** "<registry>/<path>" in the region registry. */
    public static function registryPath(KnativeRegion $region, string $path): string
    {
        return rtrim((string) $region->registry_address, '/').'/'.ltrim($path, '/');
    }

    private static function registryHost(string $address): string
    {
        return explode('/', preg_replace('#^https?://#', '', $address), 2)[0];
    }

    private static function job(string $name, KnativeRegion $region, array $labels, array $args, array $env, array $mounts, array $volumes, int $deadline): array
    {
        $podSpec = [
            'restartPolicy' => 'Never',
            'automountServiceAccountToken' => false,
            'securityContext' => ['seccompProfile' => ['type' => 'RuntimeDefault']],
            'containers' => [[
                'name' => 'supply-chain',
                'image' => self::toolsImage($region),
                'imagePullPolicy' => 'IfNotPresent',
                'args' => $args,
                'env' => $env,
                'volumeMounts' => $mounts,
                'resources' => config('supply_chain.job.resources'),
                'securityContext' => ['allowPrivilegeEscalation' => false],
            ]],
        ];
        if ($volumes) {
            $podSpec['volumes'] = $volumes;
        }
        // Untrusted image content is unpacked here: same sandbox as the region's builds
        if ($runtimeClass = $region->resolveBuildRuntimeClass()) {
            $podSpec['runtimeClassName'] = $runtimeClass;
        }

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => $name, 'namespace' => self::namespace(), 'labels' => $labels],
            'spec' => [
                'activeDeadlineSeconds' => $deadline,
                'backoffLimit' => 0,
                'ttlSecondsAfterFinished' => (int) config('supply_chain.job.ttl_seconds', 900),
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => $podSpec,
                ],
            ],
        ];
    }
}
