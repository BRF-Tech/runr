<?php

namespace Tests\Unit\GlobalProjects;

use App\Models\ContainerConfig;
use App\Models\Project;
use App\Services\GlobalProjects\Data\DataLinkCertificates;
use App\Services\GlobalProjects\Data\DataLinkManifests;
use App\Services\GlobalProjects\Data\RegionalPostgresManifests;
use App\Services\GlobalProjects\GlobalImageCopy;
use App\Services\GlobalProjects\GlobalTriggers;
use Tests\TestCase;

/**
 * What a global project's regions get (docs/global-projects.md): the data link of a binding, a
 * dedicated Postgres binding's replica topology, the trigger workloads on standby, an uploaded
 * image's copy in the manifests — as plain arrays.
 */
class RegionalDataManifestsTest extends TestCase
{
    private const LINK = [
        'sni' => 'cache-1a2b3c4d.data-link.runr.internal',
        'ca_cert' => 'CA',
        'server_cert' => 'SERVER',
        'server_key' => 'SERVER-KEY',
        'client_cert' => 'CLIENT',
        'client_key' => 'CLIENT-KEY',
    ];

    /** @param list<array> $objects */
    private function byKind(array $objects): array
    {
        $out = [];
        foreach ($objects as $object) {
            $out["{$object['kind']}/{$object['metadata']['name']}"] = $object;
        }

        return $out;
    }

    public function test_the_home_answers_the_link_on_websecure_for_the_link_ca_clients_only(): void
    {
        $objects = $this->byKind(DataLinkManifests::server('cache-1a2b3c4d', 'runr-data', self::LINK, [
            'namespace' => 'runr-data', 'service' => 'runr-kv', 'port' => 6379, 'labels' => ['app.kubernetes.io/name' => 'runr-kv'],
        ], 'knative-serving'));

        $this->assertSame([
            'Secret/runr-link-cache-1a2b3c4d-server',
            'Secret/runr-link-cache-1a2b3c4d-ca',
            'TLSOption/runr-link-cache-1a2b3c4d',
            'IngressRouteTCP/runr-link-cache-1a2b3c4d',
            'NetworkPolicy/runr-link-cache-1a2b3c4d-traefik',
        ], array_keys($objects));

        $option = $objects['TLSOption/runr-link-cache-1a2b3c4d']['spec'];
        $this->assertSame(['secretNames' => ['runr-link-cache-1a2b3c4d-ca'], 'clientAuthType' => 'RequireAndVerifyClientCert'], $option['clientAuth']);
        $this->assertTrue($option['sniStrict']);

        $route = $objects['IngressRouteTCP/runr-link-cache-1a2b3c4d']['spec'];
        $this->assertSame(['websecure'], $route['entryPoints']);
        $this->assertSame('HostSNI(`cache-1a2b3c4d.data-link.runr.internal`)', $route['routes'][0]['match']);
        $this->assertSame([['name' => 'runr-kv', 'namespace' => 'runr-data', 'port' => 6379]], $route['routes'][0]['services']);
        $this->assertSame(['secretName' => 'runr-link-cache-1a2b3c4d-server', 'options' => ['name' => 'runr-link-cache-1a2b3c4d', 'namespace' => 'runr-data']], $route['tls']);

        $policy = $objects['NetworkPolicy/runr-link-cache-1a2b3c4d-traefik']['spec'];
        $this->assertSame(['app.kubernetes.io/name' => 'runr-kv'], $policy['podSelector']['matchLabels']);
        $this->assertSame('knative-serving', $policy['ingress'][0]['from'][0]['namespaceSelector']['matchLabels']['kubernetes.io/metadata.name']);
        $this->assertSame(6379, $policy['ingress'][0]['ports'][0]['port']);
        $this->assertSame(base64_encode('SERVER'), $objects['Secret/runr-link-cache-1a2b3c4d-server']['data']['tls.crt']);
    }

    public function test_a_service_outside_the_data_namespace_gets_no_network_policy(): void
    {
        $objects = $this->byKind(DataLinkManifests::server('cache-1a2b3c4d', 'runr-data', self::LINK, [
            'namespace' => 'redis', 'service' => 'redis-master', 'port' => 6379, 'labels' => null,
        ], 'knative-serving'));

        $this->assertArrayNotHasKey('NetworkPolicy/runr-link-cache-1a2b3c4d-traefik', $objects);
        $this->assertSame('redis', $objects['IngressRouteTCP/runr-link-cache-1a2b3c4d']['spec']['routes'][0]['services'][0]['namespace']);
    }

    public function test_the_other_regions_forward_plain_tcp_into_mutual_tls_to_the_home(): void
    {
        $objects = $this->byKind(DataLinkManifests::client('cache-1a2b3c4d', 'runr-data', self::LINK, '203.0.113.10', 443, 6379, 'ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine', 'fn', ['p2', 'p1', 'p1']));

        $config = $objects['ConfigMap/runr-link-cache-1a2b3c4d']['data']['nginx.conf'];
        foreach ([
            'listen 6379;',
            'proxy_pass 203.0.113.10:443;',
            'proxy_ssl on;',
            'proxy_ssl_server_name on;',
            'proxy_ssl_name cache-1a2b3c4d.data-link.runr.internal;',
            'proxy_ssl_certificate /etc/runr-link/tls/tls.crt;',
            'proxy_ssl_trusted_certificate /etc/runr-link/tls/ca.crt;',
            'proxy_ssl_verify on;',
        ] as $line) {
            $this->assertStringContainsString($line, $config);
        }

        $pod = $objects['Deployment/runr-link-cache-1a2b3c4d']['spec']['template']['spec'];
        $this->assertSame(['nginx', '-c', '/etc/runr-link/nginx.conf', '-g', 'daemon off;'], $pod['containers'][0]['command']);
        $this->assertSame('runr-link-cache-1a2b3c4d-client', $pod['volumes'][1]['secret']['secretName']);
        $this->assertSame(6379, $objects['Service/runr-link-cache-1a2b3c4d']['spec']['ports'][0]['port']);
        $this->assertSame(base64_encode('CLIENT'), $objects['Secret/runr-link-cache-1a2b3c4d-client']['data']['tls.crt']);

        $from = $objects['NetworkPolicy/runr-link-cache-1a2b3c4d-clients']['spec']['ingress'][0]['from'][0];
        $this->assertSame('fn', $from['namespaceSelector']['matchLabels']['kubernetes.io/metadata.name']);
        $this->assertSame(['p1', 'p2'], $from['podSelector']['matchExpressions'][0]['values']);
    }

    public function test_an_ipv6_home_address_is_bracketed(): void
    {
        $this->assertStringContainsString('proxy_pass [2001:db8::10]:443;', DataLinkManifests::nginxConfig('x.data-link.runr.internal', '2001:db8::10', 443, 5432));
    }

    public function test_only_in_cluster_hosts_need_the_link(): void
    {
        $this->assertSame(['service' => 'runr-kv', 'namespace' => 'runr-data'], DataLinkManifests::inClusterService('runr-kv.runr-data.svc.cluster.local'));
        $this->assertSame(['service' => 'pg-orders-1a2b3c4d-rw', 'namespace' => 'runr-data'], DataLinkManifests::inClusterService('pg-orders-1a2b3c4d-rw.runr-data.svc'));
        $this->assertNull(DataLinkManifests::inClusterService('s3.eu-central-1.amazonaws.com'));
        $this->assertNull(DataLinkManifests::inClusterService('203.0.113.7'));
        $this->assertSame('runr-link-cache-1a2b3c4d.runr-data.svc.cluster.local', DataLinkManifests::host('cache-1a2b3c4d', 'runr-data'));
    }

    public function test_the_link_certificates_chain_to_the_binding_ca(): void
    {
        $link = DataLinkCertificates::issue('cache-1a2b3c4d.data-link.runr.internal');

        $ca = openssl_pkey_get_public($link['ca_cert']);
        $this->assertSame(1, openssl_x509_verify($link['server_cert'], $ca));
        $this->assertSame(1, openssl_x509_verify($link['client_cert'], $ca));
        $server = openssl_x509_parse($link['server_cert']);
        $this->assertSame('DNS:cache-1a2b3c4d.data-link.runr.internal', $server['extensions']['subjectAltName']);
        $this->assertStringContainsString('TLS Web Client Authentication', openssl_x509_parse($link['client_cert'])['extensions']['extendedKeyUsage']);
        $this->assertTrue(openssl_x509_check_private_key($link['server_cert'], $link['server_key']));
        $this->assertTrue(DataLinkCertificates::fresh($link));
        $this->assertFalse(DataLinkCertificates::fresh(['ca_cert' => 'x']));
    }

    public function test_the_home_cluster_keeps_its_bootstrap_and_names_itself_the_primary(): void
    {
        $base = $this->dedicated();
        $members = ['r01home', 'r02replica'];

        $home = RegionalPostgresManifests::cluster($base, 'r01home', 'r01home', 'r02replica', $members, 'runr-link-orders.runr-data.svc.cluster.local', ['initdb' => ['database' => 'b_orders']]);

        $this->assertSame(['initdb' => ['database' => 'b_orders']], $home['spec']['bootstrap']);
        $this->assertSame(['self' => 'r01home', 'primary' => 'r01home', 'source' => 'r02replica'], $home['spec']['replica']);
        $this->assertSame(['name' => 'pg-orders-superuser'], $home['spec']['superuserSecret']);
        // every member, the cluster itself too: the webhook refuses a self or primary it cannot find
        $this->assertSame(['r01home', 'r02replica'], array_column($home['spec']['externalClusters'], 'name'));
        $this->assertCloudNativePgAccepts($home);
    }

    public function test_every_cluster_of_a_topology_names_external_clusters_cloudnativepg_finds(): void
    {
        $members = ['r01home', 'r02replica', 'r03third'];
        $link = 'runr-link-orders.runr-data.svc.cluster.local';
        foreach ([
            // the home, a replica, a demoted home following the new primary, a promoted replica
            RegionalPostgresManifests::cluster($this->dedicated(), 'r01home', 'r01home', 'r02replica', $members, $link, ['initdb' => []]),
            RegionalPostgresManifests::cluster($this->dedicated(), 'r03third', 'r01home', 'r01home', $members, $link),
            RegionalPostgresManifests::cluster($this->dedicated(), 'r01home', 'r02replica', 'r02replica', $members, $link, ['initdb' => []]),
            RegionalPostgresManifests::cluster($this->dedicated(), 'r02replica', 'r02replica', 'r01home', $members, $link, ['pg_basebackup' => ['source' => 'r01home']], 'TOKEN'),
            // a member list that misses the cluster itself (a region being added)
            RegionalPostgresManifests::cluster($this->dedicated(), 'r04new', 'r01home', 'r01home', $members, $link),
        ] as $cluster) {
            $this->assertCloudNativePgAccepts($cluster);
        }
    }

    /**
     * The references CloudNativePG's Cluster webhook checks (validateReplicaClusterExternalClusters,
     * validateBootstrapPgBaseBackupSource, validateExternalClusters, validatePromotionToken):
     * replica.self, replica.primary, replica.source and bootstrap.pg_basebackup.source each name an
     * external cluster, the names are unique, each one has connection parameters, a promotion
     * token only on a cluster that is the primary.
     */
    private function assertCloudNativePgAccepts(array $cluster): void
    {
        $names = array_column($cluster['spec']['externalClusters'] ?? [], 'name');
        $this->assertSame(array_values(array_unique($names)), $names, 'the list of external clusters contains duplicate values');
        foreach ($cluster['spec']['externalClusters'] ?? [] as $external) {
            $this->assertNotEmpty($external['connectionParameters'] ?? null, "{$external['name']}: one of connectionParameters, plugin and barmanObjectStore is required");
        }
        $replica = $cluster['spec']['replica'] ?? [];
        foreach (['self', 'primary', 'source'] as $field) {
            if (($replica[$field] ?? '') !== '') {
                $this->assertContains($replica[$field], $names, "replica.{$field}: External cluster {$replica[$field]} not found");
            }
        }
        $this->assertArrayNotHasKey('enabled', $replica, 'replica mode enabled is not compatible with the primary field');
        if (isset($cluster['spec']['bootstrap']['pg_basebackup'])) {
            $this->assertContains($cluster['spec']['bootstrap']['pg_basebackup']['source'], $names, 'pg_basebackup.source: External cluster not found');
        }
        if (isset($replica['promotionToken'])) {
            $this->assertSame($replica['self'], $replica['primary'], 'promotionToken is only allowed for primary clusters');
        }
    }

    public function test_a_replica_cluster_clones_the_home_through_the_link(): void
    {
        $replica = RegionalPostgresManifests::cluster($this->dedicated(), 'r02replica', 'r01home', 'r01home', ['r01home', 'r02replica'], 'runr-link-orders.runr-data.svc.cluster.local');

        $this->assertSame(['pg_basebackup' => ['source' => 'r01home']], $replica['spec']['bootstrap']);
        $this->assertSame(['self' => 'r02replica', 'primary' => 'r01home', 'source' => 'r01home'], $replica['spec']['replica']);
        $this->assertSame(['r01home', 'r02replica'], array_column($replica['spec']['externalClusters'], 'name'));
        $this->assertCloudNativePgAccepts($replica);
        $external = $replica['spec']['externalClusters'][0];
        $this->assertSame('r01home', $external['name']);
        $this->assertSame([
            'host' => 'runr-link-orders.runr-data.svc.cluster.local',
            'port' => '5432',
            'user' => 'streaming_replica',
            'dbname' => 'postgres',
            'sslmode' => 'verify-ca',
        ], $external['connectionParameters']);
        $this->assertSame(['name' => 'pg-orders-src-replication', 'key' => 'tls.key'], $external['sslKey']);
        $this->assertSame(['name' => 'pg-orders-src-ca', 'key' => 'ca.crt'], $external['sslRootCert']);
        // a promotion with the demotion token of a switchover
        $promoted = RegionalPostgresManifests::cluster($this->dedicated(), 'r02replica', 'r02replica', 'r01home', ['r01home', 'r02replica'], 'h', ['pg_basebackup' => ['source' => 'r01home']], 'TOKEN');
        $this->assertSame('TOKEN', $promoted['spec']['replica']['promotionToken']);
        $this->assertSame(['pg_basebackup' => ['source' => 'r01home']], $promoted['spec']['bootstrap']);
    }

    public function test_the_home_secrets_are_copied_with_the_source_names(): void
    {
        $secrets = RegionalPostgresManifests::secrets('pg-orders', 'runr-data', [
            'superuser' => ['data' => ['username' => 'cG9zdGdyZXM=', 'password' => 'c3U=']],
            'owner' => ['data' => ['username' => 'Yl9vcmRlcnM=', 'password' => 'b3du']],
            'replication' => ['data' => ['tls.crt' => 'Y3J0', 'tls.key' => 'a2V5']],
            'ca' => ['data' => ['ca.crt' => 'Y2E=', 'ca.key' => 'c2VjcmV0']],
        ], ['app.kubernetes.io/managed-by' => 'runr']);

        $this->assertSame(['pg-orders-superuser', 'pg-orders-owner', 'pg-orders-src-replication', 'pg-orders-src-ca'], array_column(array_column($secrets, 'metadata'), 'name'));
        $this->assertSame('kubernetes.io/tls', $secrets[2]['type']);
        // the CA's key never leaves the home
        $this->assertSame(['ca.crt' => 'Y2E='], $secrets[3]['data']);
    }

    public function test_the_replica_state_line(): void
    {
        $this->assertSame(['primary' => false, 'streaming' => true, 'lag_seconds' => 3], RegionalPostgresManifests::parseState('t streaming 3'));
        $this->assertSame(['primary' => true, 'streaming' => false, 'lag_seconds' => null], RegionalPostgresManifests::parseState('f none -'));
        $this->assertNull(RegionalPostgresManifests::parseState(null));
        $this->assertNull(RegionalPostgresManifests::parseState('garbage'));
        $this->assertSame('r01jabc', RegionalPostgresManifests::topologyName('01JABC'));
    }

    public function test_the_trigger_workloads_wait_on_standby_outside_the_primary(): void
    {
        $project = new Project(['name' => 'shop']);
        $project->slug = 'shop';
        $manifests = [
            ['kind' => 'Service', 'apiVersion' => 'serving.knative.dev/v1', 'metadata' => ['name' => 'shop'], 'spec' => []],
            ['kind' => 'CronJob', 'metadata' => ['name' => 'shop-trigger'], 'spec' => ['schedule' => '* * * * *']],
            ['kind' => 'Deployment', 'metadata' => ['name' => 'shop-qc'], 'spec' => ['replicas' => 1]],
            ['kind' => 'Deployment', 'metadata' => ['name' => 'other'], 'spec' => ['replicas' => 1]],
        ];

        $standby = GlobalTriggers::standbyManifests($manifests, $project);

        $this->assertTrue($standby[1]['spec']['suspend']);
        $this->assertSame(0, $standby[2]['spec']['replicas']);
        $this->assertSame(1, $standby[3]['spec']['replicas']);
        $this->assertSame($manifests[0], $standby[0]);
        $this->assertTrue(GlobalTriggers::hasTriggerWorkloads($manifests, $project));
        $this->assertFalse(GlobalTriggers::hasTriggerWorkloads([$manifests[0]], $project));
    }

    public function test_an_uploaded_image_runs_from_the_region_copy(): void
    {
        $project = new Project(['name' => 'shop']);
        $project->setRelation('containerConfig', new ContainerConfig(['image' => 'registry.eu:5000/runr-uploads/org/shop:1.0']));
        $manifests = [
            ['kind' => 'Service', 'spec' => ['template' => ['spec' => ['containers' => [['name' => 'app', 'image' => 'registry.eu:5000/runr-uploads/org/shop:1.0'], ['name' => 'sidecar', 'image' => 'busybox']]]]]],
            ['kind' => 'CronJob', 'spec' => ['jobTemplate' => ['spec' => ['template' => ['spec' => ['containers' => [['name' => 'app', 'image' => 'registry.eu:5000/runr-uploads/org/shop:1.0']]]]]]]],
        ];

        $out = GlobalImageCopy::withImage($manifests, $project, 'registry.us:5000/runr-uploads/org/shop@sha256:'.str_repeat('a', 64));

        $this->assertStringStartsWith('registry.us:5000/', $out[0]['spec']['template']['spec']['containers'][0]['image']);
        $this->assertSame('busybox', $out[0]['spec']['template']['spec']['containers'][1]['image']);
        $this->assertStringStartsWith('registry.us:5000/', $out[1]['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['image']);
    }

    private function dedicated(): array
    {
        return [
            'apiVersion' => 'postgresql.cnpg.io/v1',
            'kind' => 'Cluster',
            'metadata' => ['name' => 'pg-orders', 'namespace' => 'runr-data'],
            'spec' => [
                'instances' => 1,
                'bootstrap' => ['initdb' => ['database' => 'b_orders', 'owner' => 'b_orders']],
                'managed' => ['roles' => [['name' => 'b_orders']]],
            ],
        ];
    }
}
