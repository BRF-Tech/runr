<?php

namespace Tests\Unit\Bindings;

use App\Models\Binding;
use App\Models\Project;
use App\Services\Bindings\BindingEnv;
use App\Services\Bindings\BindingManifests;
use Tests\TestCase;

/**
 * Kubernetes resources of bindings (pure builders): the per-project env Secret, the data
 * namespace's NetworkPolicies, the managed KV Redis, Garage and the CloudNativePG clusters.
 */
class BindingManifestsTest extends TestCase
{
    private function project(): Project
    {
        $project = new Project(['name' => 'orders-api']);
        $project->id = '01j9zproject00000000000000';
        $project->slug = 'orders-api';

        return $project;
    }

    private function binding(array $attributes = []): Binding
    {
        $binding = new Binding(array_merge([
            'name' => 'orders',
            'resource_name' => 'orders-abcd1234',
            'type' => 'postgres',
            'plan' => 'dedicated',
            'settings' => ['instances' => 2, 'storage_gb' => 8, 'connection_limit' => 40, 'statement_timeout' => '15s'],
        ], $attributes));
        $binding->id = '01j9zbinding000000abcd1234';

        return $binding;
    }

    public function test_project_secret_holds_the_env_base64_with_a_hash_annotation(): void
    {
        $env = ['ORDERS_DATABASE_URL' => 'postgresql://u:p@h:5432/db?sslmode=require', 'REDIS_URL' => 'redis://u:p@h:6379'];
        $secret = BindingManifests::projectSecret($this->project(), 'fn', $env);

        $this->assertSame('Secret', $secret['kind']);
        $this->assertSame('orders-api-bindings', $secret['metadata']['name']);
        $this->assertSame('fn', $secret['metadata']['namespace']);
        $this->assertSame('runr', $secret['metadata']['labels']['app.kubernetes.io/managed-by']);
        $this->assertSame('01j9zproject00000000000000', $secret['metadata']['labels']['runr/project-id']);
        $this->assertSame('true', $secret['metadata']['labels']['runr.dev/bindings']);
        $this->assertSame(BindingEnv::hash($env), $secret['metadata']['annotations']['runr.dev/bindings-hash']);
        $this->assertSame(['ORDERS_DATABASE_URL', 'REDIS_URL'], array_keys($secret['data']));
        $this->assertSame($env['ORDERS_DATABASE_URL'], base64_decode($secret['data']['ORDERS_DATABASE_URL']));
        $this->assertSame($env['REDIS_URL'], base64_decode($secret['data']['REDIS_URL']));
    }

    public function test_base_policies_deny_everything_but_the_namespace_and_the_cnpg_operator(): void
    {
        $policies = collect(BindingManifests::basePolicies('runr-data', 'cnpg-system'))->keyBy('metadata.name');

        $this->assertSame(['runr-data-default-deny', 'runr-data-same-namespace', 'runr-data-cnpg-operator', 'runr-data-openbao'], $policies->keys()->all());
        // the region's OpenBao reaches the Postgres instances (dynamic credentials), nothing else
        $openbao = $policies['runr-data-openbao'];
        $this->assertSame(['kubernetes.io/metadata.name' => 'runr-openbao'], $openbao['spec']['ingress'][0]['from'][0]['namespaceSelector']['matchLabels']);
        $this->assertSame([['protocol' => 'TCP', 'port' => 5432]], $openbao['spec']['ingress'][0]['ports']);

        $deny = $policies['runr-data-default-deny'];
        $this->assertSame('NetworkPolicy', $deny['kind']);
        $this->assertSame('runr-data', $deny['metadata']['namespace']);
        $this->assertEquals(new \stdClass, $deny['spec']['podSelector']);
        $this->assertSame(['Ingress'], $deny['spec']['policyTypes']);
        $this->assertArrayNotHasKey('ingress', $deny['spec']);

        $same = $policies['runr-data-same-namespace'];
        $this->assertEquals([['from' => [['podSelector' => new \stdClass]]]], $same['spec']['ingress']);

        $operator = $policies['runr-data-cnpg-operator'];
        $this->assertSame([['key' => 'cnpg.io/cluster', 'operator' => 'Exists']], $operator['spec']['podSelector']['matchExpressions']);
        $this->assertSame(
            ['kubernetes.io/metadata.name' => 'cnpg-system'],
            $operator['spec']['ingress'][0]['from'][0]['namespaceSelector']['matchLabels'],
        );
    }

    public function test_binding_policy_lets_the_attached_projects_reach_the_service_port(): void
    {
        $policy = BindingManifests::bindingPolicy(
            $this->binding(),
            'runr-data',
            'fn',
            ['p2', 'p1', 'p2'],
            ['labels' => ['cnpg.io/cluster' => 'pg-orders-abcd1234'], 'port' => 5432],
        );

        $this->assertSame('runr-binding-orders-abcd1234', $policy['metadata']['name']);
        $this->assertSame('orders-abcd1234', $policy['metadata']['labels']['runr.dev/binding']);
        $this->assertSame(['cnpg.io/cluster' => 'pg-orders-abcd1234'], $policy['spec']['podSelector']['matchLabels']);

        $rule = $policy['spec']['ingress'][0];
        $this->assertSame(['kubernetes.io/metadata.name' => 'fn'], $rule['from'][0]['namespaceSelector']['matchLabels']);
        $this->assertSame(
            [['key' => 'runr/project-id', 'operator' => 'In', 'values' => ['p1', 'p2']]],
            $rule['from'][0]['podSelector']['matchExpressions'],
        );
        $this->assertSame([['protocol' => 'TCP', 'port' => 5432]], $rule['ports']);
    }

    public function test_ops_pod_mounts_its_secret_and_runs_the_script(): void
    {
        $pod = BindingManifests::opsPod('runr-ops-x', 'runr-data', 'redis:7', 'runr-ops-x', [['name' => 'A', 'value' => 'b']], 90);
        $secret = BindingManifests::opsSecret('runr-ops-x', 'runr-data', ['run.sh' => 'echo hi', 'auth' => 'pw']);

        $this->assertSame('Never', $pod['spec']['restartPolicy']);
        $this->assertSame(90, $pod['spec']['activeDeadlineSeconds']);
        $this->assertSame(['sh', '/ops/run.sh'], $pod['spec']['containers'][0]['command']);
        $this->assertSame([['name' => 'ops', 'secret' => ['secretName' => 'runr-ops-x']]], $pod['spec']['volumes']);
        $this->assertSame('bindings-ops', $pod['metadata']['labels']['runr.dev/purpose']);
        $this->assertSame('pw', base64_decode($secret['data']['auth']));
        $this->assertStringNotContainsString('pw', json_encode($pod), 'credentials stay out of the pod spec');
    }

    public function test_kv_redis_runs_with_an_acl_file_aof_and_noeviction(): void
    {
        $deployment = BindingManifests::kvDeployment('runr-data', 'redis:7-alpine', 512);
        $spec = $deployment['spec']['template']['spec'];
        $args = $spec['containers'][0]['args'];

        $this->assertSame('runr-kv', $deployment['metadata']['name']);
        $this->assertSame('Recreate', $deployment['spec']['strategy']['type']);
        $this->assertSame('kv', $deployment['spec']['template']['metadata']['labels']['runr.dev/data-service']);
        foreach (['--appendonly' => 'yes', '--aclfile' => '/data/users.acl', '--maxmemory' => '512mb', '--maxmemory-policy' => 'noeviction', '--dir' => '/data'] as $flag => $value) {
            $i = array_search($flag, $args, true);
            $this->assertNotFalse($i, "{$flag} is set");
            $this->assertSame($value, $args[$i + 1]);
        }
        $this->assertNotContains('--requirepass', $args, 'the default user comes from the ACL file');

        // First start writes the default (admin) user into the ACL file, password from the Secret
        $init = $spec['initContainers'][0];
        $this->assertStringContainsString('test -s /data/users.acl', $init['command'][2]);
        $this->assertStringContainsString('user default on >${REDIS_PASSWORD}', $init['command'][2]);
        $this->assertSame(['name' => 'runr-kv-auth', 'key' => 'password'], $init['env'][0]['valueFrom']['secretKeyRef']);
        $this->assertSame('runr-kv-data', $spec['volumes'][0]['persistentVolumeClaim']['claimName']);
        $this->assertSame('576Mi', $spec['containers'][0]['resources']['limits']['memory']);

        $pvc = BindingManifests::kvPvc('runr-data', '2Gi', 'fast');
        $this->assertSame('2Gi', $pvc['spec']['resources']['requests']['storage']);
        $this->assertSame('fast', $pvc['spec']['storageClassName']);
        $this->assertArrayNotHasKey('storageClassName', BindingManifests::kvPvc('runr-data', '2Gi')['spec']);

        $this->assertSame('kv-admin', base64_decode(BindingManifests::kvSecret('runr-data', 'kv-admin')['data']['password']));
        $this->assertSame(6379, BindingManifests::kvService('runr-data')['spec']['ports'][0]['port']);
    }

    public function test_garage_is_a_single_node_statefulset(): void
    {
        $toml = BindingManifests::garageToml(str_repeat('ab', 32), 'admin-token', 'garage');

        $this->assertStringContainsString('replication_factor = 1', $toml);
        $this->assertStringContainsString('db_engine = "lmdb"', $toml);
        $this->assertStringContainsString('rpc_secret = "'.str_repeat('ab', 32).'"', $toml);
        $this->assertStringContainsString('admin_token = "admin-token"', $toml);
        $this->assertStringContainsString('api_bind_addr = "0.0.0.0:3900"', $toml);
        $this->assertStringContainsString('api_bind_addr = "0.0.0.0:3903"', $toml);
        $this->assertStringContainsString('s3_region = "garage"', $toml);

        $secret = BindingManifests::garageConfigSecret('runr-data', $toml);
        $this->assertSame('runr-objects-config', $secret['metadata']['name']);
        $this->assertSame($toml, base64_decode($secret['data']['garage.toml']));

        $sts = BindingManifests::garageStatefulSet('runr-data', 'garage:v2.1.0', '20Gi', '1Gi', 'local-path');
        $container = $sts['spec']['template']['spec']['containers'][0];
        $this->assertSame(1, $sts['spec']['replicas']);
        $this->assertSame(['/garage'], $container['command']);
        $this->assertSame(['server'], $container['args']);
        $this->assertSame([3900, 3901, 3903], array_column($container['ports'], 'containerPort'));
        $this->assertSame(['tcpSocket' => ['port' => 3903], 'periodSeconds' => 5], $container['readinessProbe']);
        $this->assertSame('/etc/garage.toml', $container['volumeMounts'][0]['mountPath']);
        $this->assertSame(['meta', 'data'], array_column(array_column($sts['spec']['volumeClaimTemplates'], 'metadata'), 'name'));
        $this->assertSame('20Gi', $sts['spec']['volumeClaimTemplates'][1]['spec']['resources']['requests']['storage']);
        $this->assertSame('local-path', $sts['spec']['volumeClaimTemplates'][1]['spec']['storageClassName']);

        $service = BindingManifests::garageService('runr-data');
        $this->assertSame([3900, 3903], array_column($service['spec']['ports'], 'port'));
    }

    public function test_shared_cluster_with_superuser_access_and_optional_backups(): void
    {
        $cluster = BindingManifests::sharedCluster('runr-data', 2, '10Gi', 'ghcr.io/cloudnative-pg/postgresql:17.5', 200);

        $this->assertSame('postgresql.cnpg.io/v1', $cluster['apiVersion']);
        $this->assertSame('runr-pg-shared', $cluster['metadata']['name']);
        $this->assertSame(2, $cluster['spec']['instances']);
        $this->assertTrue($cluster['spec']['enableSuperuserAccess']);
        $this->assertSame(['size' => '10Gi'], $cluster['spec']['storage']);
        $this->assertSame('200', $cluster['spec']['postgresql']['parameters']['max_connections']);
        $this->assertSame('postgres', $cluster['spec']['inheritedMetadata']['labels']['runr.dev/data-service']);
        $this->assertArrayNotHasKey('backup', $cluster['spec']);

        $backedUp = BindingManifests::sharedCluster('runr-data', 1, '10Gi', 'pg:17', 100, '', [
            'secret' => 'runr-pg-backup-s3', 'bucket' => 'runr-pg-backups', 'endpoint' => 'http://runr-objects.runr-data.svc.cluster.local:3900',
        ]);
        $store = $backedUp['spec']['backup']['barmanObjectStore'];
        $this->assertSame('s3://runr-pg-backups/runr-pg-shared', $store['destinationPath']);
        $this->assertSame('http://runr-objects.runr-data.svc.cluster.local:3900', $store['endpointURL']);
        $this->assertSame(['name' => 'runr-pg-backup-s3', 'key' => 'ACCESS_KEY_ID'], $store['s3Credentials']['accessKeyId']);
        $this->assertSame(['name' => 'runr-pg-backup-s3', 'key' => 'REGION'], $store['s3Credentials']['region']);
        $this->assertSame('7d', $backedUp['spec']['backup']['retentionPolicy']);
    }

    public function test_dedicated_cluster_bootstraps_the_binding_database_and_manages_its_role(): void
    {
        $binding = $this->binding();
        $cluster = BindingManifests::dedicatedCluster($binding, 'runr-data', 'pg:17', '');
        $spec = $cluster['spec'];

        $this->assertSame('pg-orders-abcd1234', $cluster['metadata']['name']);
        $this->assertSame(2, $spec['instances']);
        $this->assertSame(['size' => '8Gi'], $spec['storage']);
        $this->assertTrue($spec['enableSuperuserAccess']);

        $initdb = $spec['bootstrap']['initdb'];
        $this->assertSame('b_orders_abcd1234', $initdb['database']);
        $this->assertSame('b_orders_abcd1234', $initdb['owner']);
        $this->assertSame(['name' => 'pg-orders-abcd1234-owner'], $initdb['secret']);
        $this->assertContains("ALTER ROLE \"b_orders_abcd1234\" SET statement_timeout = '15s'", $initdb['postInitApplicationSQL']);
        $this->assertContains('REVOKE ALL ON DATABASE "b_orders_abcd1234" FROM PUBLIC', $initdb['postInitApplicationSQL']);

        $role = $spec['managed']['roles'][0];
        $this->assertSame('b_orders_abcd1234', $role['name']);
        $this->assertSame('present', $role['ensure']);
        $this->assertTrue($role['login']);
        $this->assertSame(40, $role['connectionLimit']);
        $this->assertSame(['name' => 'pg-orders-abcd1234-owner'], $role['passwordSecret']);
        $this->assertSame('100', $spec['postgresql']['parameters']['max_connections']);
        $this->assertSame('orders-abcd1234', $spec['inheritedMetadata']['labels']['runr.dev/binding']);
        $this->assertArrayNotHasKey('backup', $spec);

        $owner = BindingManifests::ownerSecret($binding, 'runr-data', 's3cret');
        $this->assertSame('kubernetes.io/basic-auth', $owner['type']);
        $this->assertSame('b_orders_abcd1234', base64_decode($owner['data']['username']));
        $this->assertSame('s3cret', base64_decode($owner['data']['password']));

        $withBackup = BindingManifests::dedicatedCluster($binding, 'runr-data', 'pg:17', '', ['secret' => 's', 'bucket' => 'b', 'endpoint' => 'http://e']);
        $this->assertSame('s3://b/pg-orders-abcd1234', $withBackup['spec']['backup']['barmanObjectStore']['destinationPath']);
    }

    public function test_scheduled_backup_and_backup_secret(): void
    {
        $backup = BindingManifests::scheduledBackup('runr-pg-shared', 'runr-data', '0 0 3 * * *');

        $this->assertSame('ScheduledBackup', $backup['kind']);
        $this->assertSame('runr-pg-shared-daily', $backup['metadata']['name']);
        $this->assertSame('0 0 3 * * *', $backup['spec']['schedule']);
        $this->assertSame(['name' => 'runr-pg-shared'], $backup['spec']['cluster']);
        $this->assertSame('barmanObjectStore', $backup['spec']['method']);

        $secret = BindingManifests::backupSecret('runr-data', 'runr-pg-backup-s3', ['access_key_id' => 'GK1', 'secret_access_key' => 'sk', 'region' => 'garage']);
        $this->assertSame('GK1', base64_decode($secret['data']['ACCESS_KEY_ID']));
        $this->assertSame('sk', base64_decode($secret['data']['ACCESS_SECRET_KEY']));
        $this->assertSame('garage', base64_decode($secret['data']['REGION']));
    }
}
