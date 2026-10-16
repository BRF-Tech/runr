<?php

namespace App\Services\Bindings;

use App\Models\Binding;
use App\Models\Project;
use App\Services\Helpers\DedicatedWorkers;
use App\Services\Manifests\KnativeManifests;

/**
 * Kubernetes resources of bindings, built as plain arrays — no cluster access here:
 * the per-project env Secret, NetworkPolicies of the data namespace, one-shot operation pods,
 * the managed KV Redis, the Garage object store and the CloudNativePG clusters.
 */
class BindingManifests
{
    public const KV_NAME = 'runr-kv';

    public const KV_PORT = 6379;

    public const OBJECT_NAME = 'runr-objects';

    public const OBJECT_S3_PORT = 3900;

    public const OBJECT_RPC_PORT = 3901;

    public const OBJECT_ADMIN_PORT = 3903;

    public const PG_SHARED = 'runr-pg-shared';

    public const PG_PORT = 5432;

    public const CNPG_API = 'postgresql.cnpg.io/v1';

    public static function labels(array $extra = []): array
    {
        return array_merge(['app.kubernetes.io/managed-by' => 'runr'], $extra);
    }

    // ── Project env ─────────────────────────────────────────────────────────────────────

    /**
     * <project>-bindings: every env var of the project's attached bindings (prefixed).
     *
     * @param  array<string, string>  $env
     */
    public static function projectSecret(Project $project, string $namespace, array $env): array
    {
        ksort($env);

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => BindingEnv::secretName($project),
                'namespace' => $namespace,
                'labels' => array_merge(self::labels([
                    'runr/project-id' => $project->id,
                    'runr.dev/bindings' => 'true',
                ]), KnativeManifests::extraLabels($project)),
                'annotations' => ['runr.dev/bindings-hash' => BindingEnv::hash($env)],
            ],
            'type' => 'Opaque',
            'data' => array_map(fn ($v) => base64_encode((string) $v), $env),
        ];
    }

    // ── Data namespace + NetworkPolicies ────────────────────────────────────────────────

    public static function dataNamespace(string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $namespace,
                'labels' => self::labels(['runr.dev/data' => 'true']),
            ],
        ];
    }

    /**
     * Policies every data namespace has: nothing gets in unless allowed — pods of the namespace
     * (ops pods, replication between instances) and the CNPG operator to its instances.
     * Per-binding policies add the attached projects.
     *
     * @return list<array>
     */
    public static function basePolicies(string $namespace, string $operatorNamespace): array
    {
        $policy = fn (string $name, array $spec) => [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => self::labels(['runr.dev/data' => 'true'])],
            'spec' => $spec,
        ];

        return [
            $policy('runr-data-default-deny', [
                'podSelector' => new \stdClass,
                'policyTypes' => ['Ingress'],
            ]),
            $policy('runr-data-same-namespace', [
                'podSelector' => new \stdClass,
                'policyTypes' => ['Ingress'],
                'ingress' => [['from' => [['podSelector' => new \stdClass]]]],
            ]),
            $policy('runr-data-cnpg-operator', [
                'podSelector' => ['matchExpressions' => [['key' => 'cnpg.io/cluster', 'operator' => 'Exists']]],
                'policyTypes' => ['Ingress'],
                'ingress' => [['from' => [[
                    'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => $operatorNamespace]],
                ]]]],
            ]),
        ];
    }

    public static function bindingPolicyName(Binding $binding): string
    {
        return 'runr-binding-'.$binding->resource_name;
    }

    /**
     * Pods of the attached projects (project namespace, runr/project-id) → the binding's service
     * port. $target: {labels: pod labels of the service, port}.
     *
     * @param  list<string>  $projectIds
     */
    public static function bindingPolicy(Binding $binding, string $namespace, string $projectNamespace, array $projectIds, array $target): array
    {
        sort($projectIds);

        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => [
                'name' => self::bindingPolicyName($binding),
                'namespace' => $namespace,
                'labels' => self::labels(['runr.dev/binding' => $binding->resource_name]),
            ],
            'spec' => [
                'podSelector' => ['matchLabels' => $target['labels']],
                'policyTypes' => ['Ingress'],
                'ingress' => [[
                    'from' => [[
                        'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => $projectNamespace]],
                        'podSelector' => ['matchExpressions' => [[
                            'key' => 'runr/project-id',
                            'operator' => 'In',
                            'values' => array_values(array_unique($projectIds)),
                        ]]],
                    ]],
                    'ports' => [['protocol' => 'TCP', 'port' => (int) $target['port']]],
                ]],
            ],
        ];
    }

    // ── One-shot operation pods ─────────────────────────────────────────────────────────

    /** @param array<string, string> $files */
    public static function opsSecret(string $name, string $namespace, array $files): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => self::labels(['runr.dev/purpose' => 'bindings-ops'])],
            'type' => 'Opaque',
            'data' => array_map(fn ($c) => base64_encode((string) $c), $files),
        ];
    }

    public static function opsPod(string $name, string $namespace, string $image, string $secretName, array $env, int $timeout): array
    {
        $container = [
            'name' => 'ops',
            'image' => $image,
            'command' => ['sh', '/ops/run.sh'],
            'volumeMounts' => [['name' => 'ops', 'mountPath' => '/ops', 'readOnly' => true]],
            'resources' => [
                'requests' => ['cpu' => '50m', 'memory' => '64Mi'],
                'limits' => ['cpu' => '500m', 'memory' => '256Mi'],
            ],
        ];
        if ($env) {
            $container['env'] = $env;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => self::labels(['runr.dev/purpose' => 'bindings-ops'])],
            'spec' => [
                'restartPolicy' => 'Never',
                'activeDeadlineSeconds' => $timeout,
                'containers' => [$container],
                'volumes' => [['name' => 'ops', 'secret' => ['secretName' => $secretName]]],
            ],
        ];
    }

    // ── KV: managed Redis (ACL file + AOF on a volume) ──────────────────────────────────

    public static function kvSecret(string $namespace, string $password): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => self::KV_NAME.'-auth', 'namespace' => $namespace, 'labels' => self::labels(['runr.dev/data-service' => 'kv'])],
            'type' => 'Opaque',
            'data' => ['password' => base64_encode($password)],
        ];
    }

    public static function kvPvc(string $namespace, string $size, string $storageClass = ''): array
    {
        return self::pvc(self::KV_NAME.'-data', $namespace, $size, $storageClass, 'kv');
    }

    /**
     * Redis 7 with an ACL file on its volume: the default user (admin, password from the
     * Secret) is written on the first start, binding users are persisted with ACL SAVE.
     * noeviction: a full instance refuses writes instead of dropping another binding's keys.
     */
    public static function kvDeployment(string $namespace, string $image, int $maxMemoryMb): array
    {
        $labels = ['app.kubernetes.io/name' => self::KV_NAME, 'runr.dev/data-service' => 'kv'];
        $password = ['name' => 'REDIS_PASSWORD', 'valueFrom' => ['secretKeyRef' => ['name' => self::KV_NAME.'-auth', 'key' => 'password']]];
        $memory = ($maxMemoryMb + 64).'Mi';

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => self::KV_NAME, 'namespace' => $namespace, 'labels' => self::labels($labels)],
            'spec' => [
                'replicas' => 1,
                'strategy' => ['type' => 'Recreate'],
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => self::KV_NAME]],
                'template' => [
                    'metadata' => ['labels' => self::labels($labels)],
                    'spec' => [
                        'initContainers' => [[
                            'name' => 'acl-init',
                            'image' => $image,
                            'command' => ['sh', '-c', 'test -s /data/users.acl || echo "user default on >${REDIS_PASSWORD} ~* &* +@all" > /data/users.acl'],
                            'env' => [$password],
                            'volumeMounts' => [['name' => 'data', 'mountPath' => '/data']],
                        ]],
                        'containers' => [[
                            'name' => 'redis',
                            'image' => $image,
                            'args' => [
                                '--port', (string) self::KV_PORT,
                                '--appendonly', 'yes',
                                '--dir', '/data',
                                '--aclfile', '/data/users.acl',
                                '--maxmemory', $maxMemoryMb.'mb',
                                '--maxmemory-policy', 'noeviction',
                            ],
                            'ports' => [['containerPort' => self::KV_PORT]],
                            'readinessProbe' => ['tcpSocket' => ['port' => self::KV_PORT], 'periodSeconds' => 5],
                            'volumeMounts' => [['name' => 'data', 'mountPath' => '/data']],
                            'resources' => [
                                'requests' => ['cpu' => '100m', 'memory' => $memory],
                                'limits' => ['cpu' => '500m', 'memory' => $memory],
                            ],
                        ]],
                        'volumes' => [['name' => 'data', 'persistentVolumeClaim' => ['claimName' => self::KV_NAME.'-data']]],
                        // its volume may be bound to a node selected as a dedicated worker later
                        ...DedicatedWorkers::systemPlacement(),
                    ],
                ],
            ],
        ];
    }

    public static function kvService(string $namespace): array
    {
        return self::service(self::KV_NAME, $namespace, ['app.kubernetes.io/name' => self::KV_NAME], 'kv', [
            ['name' => 'redis', 'port' => self::KV_PORT, 'targetPort' => self::KV_PORT],
        ]);
    }

    // ── Object store: Garage (single node, replication factor 1) ────────────────────────

    public static function garageToml(string $rpcSecret, string $adminToken, string $s3Region): string
    {
        return implode("\n", [
            'metadata_dir = "/var/lib/garage/meta"',
            'data_dir = "/var/lib/garage/data"',
            'db_engine = "lmdb"',
            'replication_factor = 1',
            'consistency_mode = "consistent"',
            'rpc_bind_addr = "0.0.0.0:'.self::OBJECT_RPC_PORT.'"',
            'rpc_public_addr = "127.0.0.1:'.self::OBJECT_RPC_PORT.'"',
            'rpc_secret = "'.$rpcSecret.'"',
            '',
            '[s3_api]',
            's3_region = "'.$s3Region.'"',
            'api_bind_addr = "0.0.0.0:'.self::OBJECT_S3_PORT.'"',
            '',
            '[admin]',
            'api_bind_addr = "0.0.0.0:'.self::OBJECT_ADMIN_PORT.'"',
            'admin_token = "'.$adminToken.'"',
            '',
        ]);
    }

    public static function garageConfigSecret(string $namespace, string $toml): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => self::OBJECT_NAME.'-config', 'namespace' => $namespace, 'labels' => self::labels(['runr.dev/data-service' => 'object'])],
            'type' => 'Opaque',
            'data' => ['garage.toml' => base64_encode($toml)],
        ];
    }

    public static function garageStatefulSet(string $namespace, string $image, string $dataSize, string $metaSize, string $storageClass = ''): array
    {
        $labels = ['app.kubernetes.io/name' => self::OBJECT_NAME, 'runr.dev/data-service' => 'object'];
        $claim = function (string $name, string $size) use ($storageClass) {
            $spec = ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => $size]]];
            if ($storageClass !== '') {
                $spec['storageClassName'] = $storageClass;
            }

            return ['metadata' => ['name' => $name], 'spec' => $spec];
        };

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'StatefulSet',
            'metadata' => ['name' => self::OBJECT_NAME, 'namespace' => $namespace, 'labels' => self::labels($labels)],
            'spec' => [
                'serviceName' => self::OBJECT_NAME,
                'replicas' => 1,
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => self::OBJECT_NAME]],
                'template' => [
                    'metadata' => ['labels' => self::labels($labels)],
                    'spec' => [
                        'containers' => [[
                            'name' => 'garage',
                            'image' => $image,
                            'command' => ['/garage'],
                            'args' => ['server'],
                            'ports' => [
                                ['name' => 's3', 'containerPort' => self::OBJECT_S3_PORT],
                                ['name' => 'rpc', 'containerPort' => self::OBJECT_RPC_PORT],
                                ['name' => 'admin', 'containerPort' => self::OBJECT_ADMIN_PORT],
                            ],
                            // tcp: /health answers 503 until the layout is applied (after the pod is up)
                            'readinessProbe' => ['tcpSocket' => ['port' => self::OBJECT_ADMIN_PORT], 'periodSeconds' => 5],
                            'volumeMounts' => [
                                ['name' => 'config', 'mountPath' => '/etc/garage.toml', 'subPath' => 'garage.toml', 'readOnly' => true],
                                ['name' => 'meta', 'mountPath' => '/var/lib/garage/meta'],
                                ['name' => 'data', 'mountPath' => '/var/lib/garage/data'],
                            ],
                            'resources' => [
                                'requests' => ['cpu' => '50m', 'memory' => '128Mi'],
                                'limits' => ['cpu' => '1', 'memory' => '512Mi'],
                            ],
                        ]],
                        'volumes' => [['name' => 'config', 'secret' => ['secretName' => self::OBJECT_NAME.'-config']]],
                        // its volumes may be bound to a node selected as a dedicated worker later
                        ...DedicatedWorkers::systemPlacement(),
                    ],
                ],
                'volumeClaimTemplates' => [$claim('meta', $metaSize), $claim('data', $dataSize)],
            ],
        ];
    }

    public static function garageService(string $namespace): array
    {
        return self::service(self::OBJECT_NAME, $namespace, ['app.kubernetes.io/name' => self::OBJECT_NAME], 'object', [
            ['name' => 's3', 'port' => self::OBJECT_S3_PORT, 'targetPort' => self::OBJECT_S3_PORT],
            ['name' => 'admin', 'port' => self::OBJECT_ADMIN_PORT, 'targetPort' => self::OBJECT_ADMIN_PORT],
        ]);
    }

    // ── Postgres: CloudNativePG ─────────────────────────────────────────────────────────

    /**
     * @param  array{secret: string, bucket: string, endpoint: string}|null  $backup  barman-cloud target
     */
    public static function sharedCluster(string $namespace, int $instances, string $storage, string $image, int $maxConnections, string $storageClass = '', ?array $backup = null): array
    {
        $spec = [
            'instances' => $instances,
            'imageName' => $image,
            'enableSuperuserAccess' => true,
            'storage' => self::cnpgStorage($storage, $storageClass),
            'affinity' => self::cnpgAffinity(),
            'postgresql' => ['parameters' => ['max_connections' => (string) $maxConnections]],
            'inheritedMetadata' => ['labels' => self::labels(['runr.dev/data-service' => 'postgres'])],
        ];

        if ($backup) {
            $spec['backup'] = self::barman(self::PG_SHARED, $backup);
        }

        return [
            'apiVersion' => self::CNPG_API,
            'kind' => 'Cluster',
            'metadata' => ['name' => self::PG_SHARED, 'namespace' => $namespace, 'labels' => self::labels(['runr.dev/data-service' => 'postgres'])],
            'spec' => $spec,
        ];
    }

    /** basic-auth Secret of a dedicated cluster's owner role (initdb + managed role password) */
    public static function ownerSecret(Binding $binding, string $namespace, string $password): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $binding->dedicatedClusterName().'-owner',
                'namespace' => $namespace,
                'labels' => self::labels(['runr.dev/data-service' => 'postgres', 'runr.dev/binding' => $binding->resource_name, 'cnpg.io/reload' => 'true']),
            ],
            'type' => 'kubernetes.io/basic-auth',
            'data' => ['username' => base64_encode($binding->pgName()), 'password' => base64_encode($password)],
        ];
    }

    /**
     * A dedicated cluster of one binding: its database + owner role (initdb), the role managed by
     * the operator (password from the owner Secret — a changed Secret changes the password,
     * connection limit), role settings applied once at bootstrap.
     */
    public static function dedicatedCluster(Binding $binding, string $namespace, string $image, string $storageClass = '', ?array $backup = null): array
    {
        $role = $binding->pgName();
        $limit = (int) $binding->setting('connection_limit', config('bindings.postgres.default_connection_limit'));
        $timeout = (string) $binding->setting('statement_timeout', config('bindings.postgres.default_statement_timeout'));
        $idle = (string) config('bindings.postgres.idle_in_transaction_timeout');
        $secret = $binding->dedicatedClusterName().'-owner';
        $instances = max(1, (int) $binding->setting('instances', config('bindings.postgres.dedicated.default_instances')));
        $storage = (int) $binding->setting('storage_gb', config('bindings.postgres.dedicated.default_storage_gb')).'Gi';

        $spec = [
            'instances' => $instances,
            'imageName' => $image,
            'enableSuperuserAccess' => true,
            'storage' => self::cnpgStorage($storage, $storageClass),
            'affinity' => self::cnpgAffinity(),
            'bootstrap' => ['initdb' => [
                'database' => $role,
                'owner' => $role,
                'secret' => ['name' => $secret],
                'postInitApplicationSQL' => [
                    sprintf("ALTER ROLE \"%s\" SET statement_timeout = '%s'", $role, $timeout),
                    sprintf("ALTER ROLE \"%s\" SET idle_in_transaction_session_timeout = '%s'", $role, $idle),
                    sprintf('REVOKE ALL ON DATABASE "%s" FROM PUBLIC', $role),
                ],
            ]],
            'managed' => ['roles' => [[
                'name' => $role,
                'ensure' => 'present',
                'login' => true,
                'inherit' => true,
                'connectionLimit' => $limit,
                'passwordSecret' => ['name' => $secret],
            ]]],
            'postgresql' => ['parameters' => ['max_connections' => (string) max(100, $limit + 20)]],
            'inheritedMetadata' => ['labels' => self::labels(['runr.dev/data-service' => 'postgres', 'runr.dev/binding' => $binding->resource_name])],
        ];

        if ($backup) {
            $spec['backup'] = self::barman($binding->dedicatedClusterName(), $backup);
        }

        return [
            'apiVersion' => self::CNPG_API,
            'kind' => 'Cluster',
            'metadata' => [
                'name' => $binding->dedicatedClusterName(),
                'namespace' => $namespace,
                'labels' => self::labels(['runr.dev/data-service' => 'postgres', 'runr.dev/binding' => $binding->resource_name]),
            ],
            'spec' => $spec,
        ];
    }

    public static function scheduledBackup(string $cluster, string $namespace, string $schedule): array
    {
        return [
            'apiVersion' => self::CNPG_API,
            'kind' => 'ScheduledBackup',
            'metadata' => ['name' => $cluster.'-daily', 'namespace' => $namespace, 'labels' => self::labels(['runr.dev/data-service' => 'postgres'])],
            'spec' => [
                'schedule' => $schedule,
                'backupOwnerReference' => 'self',
                'cluster' => ['name' => $cluster],
                'method' => 'barmanObjectStore',
            ],
        ];
    }

    /** S3 credentials of the Postgres backups (bucket in the region object store) */
    public static function backupSecret(string $namespace, string $name, array $target): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => self::labels(['runr.dev/data-service' => 'postgres'])],
            'type' => 'Opaque',
            'data' => array_map('base64_encode', [
                'ACCESS_KEY_ID' => (string) $target['access_key_id'],
                'ACCESS_SECRET_KEY' => (string) $target['secret_access_key'],
                'REGION' => (string) ($target['region'] ?? 'garage'),
            ]),
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────

    private static function barman(string $cluster, array $backup): array
    {
        return [
            'barmanObjectStore' => [
                'destinationPath' => 's3://'.$backup['bucket'].'/'.$cluster,
                'endpointURL' => $backup['endpoint'],
                's3Credentials' => [
                    'accessKeyId' => ['name' => $backup['secret'], 'key' => 'ACCESS_KEY_ID'],
                    'secretAccessKey' => ['name' => $backup['secret'], 'key' => 'ACCESS_SECRET_KEY'],
                    'region' => ['name' => $backup['secret'], 'key' => 'REGION'],
                ],
                'wal' => ['compression' => 'gzip'],
                'data' => ['compression' => 'gzip'],
            ],
            'retentionPolicy' => (string) config('bindings.postgres.backup.retention', '7d'),
        ];
    }

    /**
     * The instances tolerate the dedicated-worker taint and prefer the other nodes, as RunR's
     * other components with a node-bound volume (DedicatedWorkers::systemPlacement()); the
     * operator's own pod anti-affinity (one instance per node, preferred) stays on.
     */
    private static function cnpgAffinity(): array
    {
        return [
            'tolerations' => [DedicatedWorkers::toleration()],
            'nodeAffinity' => DedicatedWorkers::preferOtherNodes()['nodeAffinity'],
        ];
    }

    private static function cnpgStorage(string $size, string $storageClass): array
    {
        $storage = ['size' => $size];
        if ($storageClass !== '') {
            $storage['storageClass'] = $storageClass;
        }

        return $storage;
    }

    private static function pvc(string $name, string $namespace, string $size, string $storageClass, string $service): array
    {
        $spec = ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => $size]]];
        if ($storageClass !== '') {
            $spec['storageClassName'] = $storageClass;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => self::labels(['runr.dev/data-service' => $service])],
            'spec' => $spec,
        ];
    }

    private static function service(string $name, string $namespace, array $selector, string $dataService, array $ports): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => self::labels(['runr.dev/data-service' => $dataService])],
            'spec' => [
                'type' => 'ClusterIP',
                'selector' => $selector,
                'ports' => $ports,
            ],
        ];
    }
}
