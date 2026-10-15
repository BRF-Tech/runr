<?php

namespace Tests\Unit;

use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Services\Skupper\SkupperLinkParser;
use App\Services\Skupper\SkupperManifests;
use Tests\TestCase;

/**
 * Skupper v2 resources of a private Managed PostgreSQL connection (pure builders)
 * and the link material parser.
 */
class SkupperManifestsTest extends TestCase
{
    private const CA = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUQ==\n-----END CERTIFICATE-----";

    private function project(): Project
    {
        $project = new Project(['name' => 'orders-api']);
        $project->id = '01J9ZPROJECT00000000000000';

        return $project;
    }

    private function binding(array $attrs = []): PrivateDatabaseBinding
    {
        return new PrivateDatabaseBinding(array_merge([
            'project_id' => '01J9ZPROJECT00000000000000',
            'name' => 'orders-db',
            'resource_name' => 'orders-db-abc123',
            'env_prefix' => '',
            'routing_key' => 'pg-orders-rw',
            'port' => 5432,
            'sslmode' => 'require',
            'link_kind' => 'access_token',
        ], $attrs));
    }

    public function test_site_is_a_consumer_site_with_an_object_spec(): void
    {
        $site = SkupperManifests::site('fn');

        $this->assertSame('skupper.io/v2alpha1', $site['apiVersion']);
        $this->assertSame('Site', $site['kind']);
        $this->assertSame(['name' => 'runr', 'namespace' => 'fn', 'labels' => ['app.kubernetes.io/managed-by' => 'runr']], $site['metadata']);
        $this->assertSame('{}', json_encode($site['spec']), 'spec must serialize as an object, not []');
    }

    public function test_listener_exposes_the_routing_key_as_a_local_service(): void
    {
        $listener = SkupperManifests::listener($this->binding(), $this->project(), 'fn');

        $this->assertSame('Listener', $listener['kind']);
        $this->assertSame('orders-db-abc123', $listener['metadata']['name']);
        $this->assertSame(['routingKey' => 'pg-orders-rw', 'host' => 'orders-db-abc123', 'port' => 5432], $listener['spec']);
        $this->assertSame('orders-db-abc123', $listener['metadata']['labels']['runr/private-connection']);
        $this->assertSame('01J9ZPROJECT00000000000000', $listener['metadata']['labels']['runr/project-id']);
    }

    public function test_access_token_and_link_get_runr_names(): void
    {
        $binding = $this->binding();

        [$token] = SkupperManifests::link(['kind' => 'AccessToken', 'spec' => ['url' => 'https://grant.test/x', 'code' => 'c0de', 'ca' => self::CA]], $binding, $this->project(), 'fn');
        $this->assertSame('AccessToken', $token['kind']);
        $this->assertSame('orders-db-abc123', $token['metadata']['name']);
        $this->assertSame('c0de', $token['spec']['code']);

        [$secret, $link] = SkupperManifests::link([
            'kind' => 'Link',
            'spec' => ['endpoints' => [['name' => 'inter-router', 'host' => 'pg.link.test', 'port' => '443']], 'cost' => 1],
            'secret' => ['type' => 'kubernetes.io/tls', 'data' => ['ca.crt' => 'Y2E=', 'tls.crt' => 'Y3J0', 'tls.key' => 'a2V5']],
        ], $binding, $this->project(), 'fn');

        $this->assertSame('Secret', $secret['kind']);
        $this->assertSame('orders-db-abc123-link', $secret['metadata']['name']);
        $this->assertSame('Link', $link['kind']);
        $this->assertSame('orders-db-abc123', $link['metadata']['name']);
        $this->assertSame('orders-db-abc123-link', $link['spec']['tlsCredentials']);
        $this->assertSame(1, $link['spec']['cost']);
    }

    public function test_credentials_live_in_a_secret_with_an_encoded_database_url(): void
    {
        $secret = SkupperManifests::credentialsSecret($this->binding(), $this->project(), 'fn', [
            'database' => 'orders', 'username' => 'app', 'password' => 'p@ss:w/rd',
        ]);

        $this->assertSame('orders-db-abc123-pgconn', $secret['metadata']['name']);
        $data = array_map('base64_decode', $secret['data']);
        $this->assertSame('postgresql://app:p%40ss%3Aw%2Frd@orders-db-abc123:5432/orders?sslmode=require', $data['DATABASE_URL']);
        $this->assertSame('orders-db-abc123', $data['PGHOST']);
        $this->assertSame('5432', $data['PGPORT']);
        $this->assertSame('p@ss:w/rd', $data['PGPASSWORD']);
        $this->assertSame(PrivateDatabaseBinding::SECRET_KEYS, array_keys($data));
    }

    public function test_env_uses_secret_key_refs_with_the_prefix_and_replaces_stale_entries(): void
    {
        $orders = $this->binding(['env_prefix' => 'ORDERS_']);
        $env = [
            ['name' => 'FOO', 'value' => 'bar'],
            ['name' => 'ORDERS_PGHOST', 'value' => 'user-set'],                                                          // same name → replaced
            ['name' => 'PGHOST', 'valueFrom' => ['secretKeyRef' => ['name' => 'old-db-zzz999-pgconn', 'key' => 'PGHOST']]], // removed binding
        ];

        $merged = SkupperManifests::mergeEnv($env, [$orders]);

        $this->assertSame(['name' => 'FOO', 'value' => 'bar'], $merged[0]);
        $this->assertSame(array_merge(['FOO'], array_map(fn ($k) => "ORDERS_{$k}", PrivateDatabaseBinding::SECRET_KEYS)), array_column($merged, 'name'));
        $host = collect($merged)->firstWhere('name', 'ORDERS_PGHOST');
        $this->assertSame(['name' => 'orders-db-abc123-pgconn', 'key' => 'PGHOST', 'optional' => true], $host['valueFrom']['secretKeyRef']);

        // no bindings left: only the user's entries stay
        $this->assertSame([['name' => 'FOO', 'value' => 'bar']], SkupperManifests::mergeEnv($merged, []));
    }

    public function test_parser_reads_an_access_token(): void
    {
        $yaml = <<<'YAML'
            apiVersion: skupper.io/v2alpha1
            kind: AccessToken
            metadata:
              name: pg-grant
            spec:
              url: https://skupper-grant-server.cnpg-link.example.io:9090/0f1e
              code: Xk2pQ
              ca: |
            YAML;
        $yaml .= "\n".implode("\n", array_map(fn ($l) => '    '.$l, explode("\n", self::CA)))."\n";

        $parsed = (new SkupperLinkParser)->parse($yaml);

        $this->assertSame('AccessToken', $parsed['kind']);
        $this->assertSame('https://skupper-grant-server.cnpg-link.example.io:9090/0f1e', $parsed['spec']['url']);
        $this->assertSame('Xk2pQ', $parsed['spec']['code']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $parsed['spec']['ca']);
    }

    public function test_parser_reads_a_generated_link_with_its_secret(): void
    {
        $yaml = <<<'YAML'
            apiVersion: skupper.io/v2alpha1
            kind: Link
            metadata:
              name: link-pg
            spec:
              endpoints:
                - name: inter-router
                  host: skupper-router-pg.cnpg-link.example.io
                  port: "443"
              tlsCredentials: link-pg
              cost: 1
            ---
            apiVersion: v1
            kind: Secret
            metadata:
              name: link-pg
            type: kubernetes.io/tls
            data:
              ca.crt: Y2E=
              tls.crt: Y3J0
              tls.key: a2V5
            YAML;

        $parsed = (new SkupperLinkParser)->parse($yaml);

        $this->assertSame('Link', $parsed['kind']);
        $this->assertArrayNotHasKey('tlsCredentials', $parsed['spec']);
        $this->assertSame('skupper-router-pg.cnpg-link.example.io', $parsed['spec']['endpoints'][0]['host']);
        $this->assertSame(['ca.crt' => 'Y2E=', 'tls.crt' => 'Y3J0', 'tls.key' => 'a2V5'], $parsed['secret']['data']);
    }

    public function test_parser_rejects_v1_tokens_and_garbage(): void
    {
        $parser = new SkupperLinkParser;

        foreach ([
            "apiVersion: v1\nkind: Secret\nmetadata:\n  name: t\n  labels:\n    skupper.io/type: connection-token\ndata: {}\n" => 'v1',
            "apiVersion: skupper.io/v1alpha1\nkind: AccessToken\nspec: {}\n" => 'skupper.io/v2',
            "apiVersion: skupper.io/v2alpha1\nkind: AccessToken\nspec:\n  url: http://plain\n  code: x\n  ca: y\n" => 'https',
            "apiVersion: skupper.io/v2alpha1\nkind: Link\nspec:\n  endpoints: [{host: a}]\n" => 'TLS Secret',
            'just some text' => 'No Skupper v2',
            '' => 'empty',
        ] as $input => $expected) {
            try {
                $parser->parse($input);
                $this->fail("accepted: {$input}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }
}
