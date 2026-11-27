<?php

namespace Tests\Unit\Kubeconfigs;

use App\Services\Kubeconfigs\KubeconfigPolicy;
use App\Services\Kubeconfigs\KubeconfigRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * What the kubeconfig of an organization's own region may hold (KubeconfigPolicy,
 * docs/security.md "Tenant region kubeconfigs"): the allowed fields only, the current context
 * with its cluster and user only, rebuilt from the checked values — whatever the YAML hides with
 * anchors, aliases, merge keys or several documents, and in JSON as well.
 */
class KubeconfigPolicyTest extends TestCase
{
    private const CA = 'LS0tLS1CRUdJTiBDRVJUSUZJQ0FURS0tLS0tCk1JSUJ5ekNDQVhHZ0F3SUJBZ0lVCi0tLS0tRU5EIENFUlRJRklDQVRFLS0tLS0K';

    /** @param  array<string, mixed>  $cluster  @param  array<string, mixed>  $user  @param  array<string, mixed>  $extra */
    private static function config(array $cluster = [], array $user = ['token' => 'abc'], array $extra = [], array $context = []): array
    {
        return array_replace([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'ctx',
            'clusters' => [['name' => 'c', 'cluster' => $cluster + ['server' => 'https://api.example.com:6443', 'certificate-authority-data' => self::CA]]],
            'users' => [['name' => 'u', 'user' => $user]],
            'contexts' => [['name' => 'ctx', 'context' => $context + ['cluster' => 'c', 'user' => 'u']]],
        ], $extra);
    }

    private static function yaml(array $config): string
    {
        return Yaml::dump($config, 10, 2);
    }

    private function refusedWith(string $kubeconfig, string $reason, ?string $field = null): KubeconfigRefused
    {
        try {
            KubeconfigPolicy::normalize($kubeconfig);
        } catch (KubeconfigRefused $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
            if ($field !== null) {
                $this->assertStringContainsString($field, $e->getMessage());
            }

            return $e;
        }
        $this->fail("refused ({$reason}) expected");
    }

    public function test_a_token_kubeconfig_is_kept_as_its_checked_fields(): void
    {
        $result = KubeconfigPolicy::normalize(self::yaml(self::config(['tls-server-name' => 'kube.internal.example'], ['token' => 'abc'], ['preferences' => []], ['namespace' => 'apps'])));

        $this->assertSame('api.example.com', $result->host);
        $this->assertSame(6443, $result->port);
        $this->assertSame([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://api.example.com:6443', 'certificate-authority-data' => self::CA, 'tls-server-name' => 'kube.internal.example']]],
            'users' => [['name' => 'u', 'user' => ['token' => 'abc']]],
            'contexts' => [['name' => 'ctx', 'context' => ['namespace' => 'apps', 'cluster' => 'c', 'user' => 'u']]],
            'current-context' => 'ctx',
        ], $result->config);

        // what is stored reads back as the same kubeconfig
        $this->assertSame($result->config, KubeconfigPolicy::normalize($result->yaml())->config);
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function acceptedCredentials(): array
    {
        return [
            'client certificate' => [[], ['client-certificate-data' => self::CA, 'client-key-data' => self::CA]],
            'username and password' => [[], ['username' => 'admin', 'password' => 's3cret']],
            'no credentials' => [[], []],
            'insecure TLS without a CA' => [['insecure-skip-tls-verify' => true, 'certificate-authority-data' => null], ['token' => 'abc']],
            'a literal IPv4 server' => [['server' => 'https://203.0.113.10'], ['token' => 'abc']],
            'a literal IPv6 server with a path' => [['server' => 'https://[2001:db8::10]:6443/k8s/clusters/c-1'], ['token' => 'abc']],
        ];
    }

    /** @param  array<string, mixed>  $cluster  @param  array<string, mixed>  $user */
    #[DataProvider('acceptedCredentials')]
    public function test_accepted_fields(array $cluster, array $user): void
    {
        $config = self::config(array_filter($cluster, fn ($v) => $v !== null), $user);
        if (array_key_exists('certificate-authority-data', $cluster) && $cluster['certificate-authority-data'] === null) {
            unset($config['clusters'][0]['cluster']['certificate-authority-data']);
        }

        $result = KubeconfigPolicy::normalize(self::yaml($config));

        $this->assertSame($user, $result->config['users'][0]['user']);
        $this->assertEquals($config['clusters'][0]['cluster'], $result->config['clusters'][0]['cluster']);
        // an empty user is written as a map, as kubectl reads it
        $this->assertStringNotContainsString('user: []', $result->yaml());
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function refusedFields(): array
    {
        $exec = ['apiVersion' => 'client.authentication.k8s.io/v1', 'command' => '/bin/sh', 'args' => ['-c', 'id']];

        return [
            'exec' => ['user', 'exec', ['exec' => $exec]],
            'auth-provider' => ['user', 'exec', ['auth-provider' => ['name' => 'oidc', 'config' => ['idp-issuer-url' => 'https://idp.example.com']]]],
            'tokenFile' => ['user', 'file', ['tokenFile' => '/var/run/secrets/kubernetes.io/serviceaccount/token']],
            'client-certificate' => ['user', 'file', ['client-certificate' => '/etc/ssl/private/client.crt']],
            'client-key' => ['user', 'file', ['client-key' => '/etc/ssl/private/client.key']],
            'as' => ['user', 'impersonation', ['as' => 'system:admin']],
            'as-uid' => ['user', 'impersonation', ['as-uid' => '1']],
            'as-groups' => ['user', 'impersonation', ['as-groups' => ['system:masters']]],
            'as-user-extra' => ['user', 'impersonation', ['as-user-extra' => ['x' => ['y']]]],
            'user extensions' => ['user', 'field', ['extensions' => [['name' => 'x', 'extension' => []]]]],
            'certificate-authority' => ['cluster', 'file', ['certificate-authority' => '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt']],
            'proxy-url' => ['cluster', 'proxy', ['proxy-url' => 'http://10.0.0.1:3128']],
            'cluster extensions' => ['cluster', 'field', ['extensions' => [['name' => 'x', 'extension' => []]]]],
            'disable-compression' => ['cluster', 'field', ['disable-compression' => true]],
            'context extensions' => ['context', 'field', ['extensions' => [['name' => 'x', 'extension' => []]]]],
        ];
    }

    /** @param  array<string, mixed>  $field */
    #[DataProvider('refusedFields')]
    public function test_refused_fields(string $where, string $reason, array $field): void
    {
        $config = match ($where) {
            'user' => self::config([], ['token' => 'abc'] + $field),
            'cluster' => self::config($field),
            default => self::config([], ['token' => 'abc'], [], $field),
        };

        $e = $this->refusedWith(self::yaml($config), $reason, (string) array_key_first($field));
        $this->assertStringNotContainsString('/bin/sh', $e->getMessage(), 'the message names the field, not its value');
    }

    public function test_top_level_fields_beyond_the_allowed_ones_are_refused(): void
    {
        $this->refusedWith(self::yaml(self::config([], ['token' => 'abc'], ['extensions' => [['name' => 'x', 'extension' => []]]])), 'field', 'extensions');
        $this->refusedWith(self::yaml(self::config([], ['token' => 'abc'], ['preferences' => ['extensions' => []]])), 'field', 'preferences.extensions');
        $this->refusedWith(self::yaml(self::config([], ['token' => 'abc'], ['kind' => 'Secret'])), 'not_a_config');
        $this->refusedWith(self::yaml(self::config([], ['token' => 'abc'], ['apiVersion' => 'v2'])), 'not_a_config');
    }

    public function test_an_exec_plugin_in_a_second_user_is_refused_by_name(): void
    {
        $config = self::config();
        $config['users'][] = ['name' => 'other', 'user' => ['exec' => ['command' => 'curl', 'apiVersion' => 'client.authentication.k8s.io/v1']]];
        $config['contexts'][] = ['name' => 'other', 'context' => ['cluster' => 'c', 'user' => 'other']];

        $this->refusedWith(self::yaml($config), 'exec', 'users[1].user.exec');
    }

    public function test_current_context_pointing_at_a_refused_user_is_refused(): void
    {
        $config = self::config([], ['exec' => ['command' => 'sh', 'apiVersion' => 'client.authentication.k8s.io/v1']]);

        $this->refusedWith(self::yaml($config), 'exec', 'users[0].user.exec');
    }

    public function test_entries_the_current_context_does_not_use_are_refused(): void
    {
        $config = self::config();
        $config['clusters'][] = ['name' => 'internal', 'cluster' => ['server' => 'https://10.0.0.1']];
        $this->refusedWith(self::yaml($config), 'unused', 'clusters[1]');

        $config = self::config();
        $config['contexts'][] = ['name' => 'spare', 'context' => ['cluster' => 'c', 'user' => 'u']];
        $this->refusedWith(self::yaml($config), 'unused', 'contexts[1]');

        $config = self::config();
        $config['users'][] = ['name' => 'u', 'user' => ['token' => 'two']];
        $this->refusedWith(self::yaml($config), 'duplicate');
    }

    public function test_a_current_context_that_names_nothing_is_refused(): void
    {
        $this->refusedWith(self::yaml(self::config([], ['token' => 'abc'], ['current-context' => 'nope'])), 'no_context');
        $config = self::config();
        $config['contexts'][0]['context']['user'] = 'nobody';
        $this->refusedWith(self::yaml($config), 'missing');
    }

    public function test_anchors_aliases_and_merge_keys_are_judged_by_what_they_resolve_to(): void
    {
        // the used user takes exec from a merge key
        $merged = <<<'YAML'
apiVersion: v1
kind: Config
current-context: ctx
clusters:
- name: c
  cluster:
    server: https://api.example.com
contexts:
- name: ctx
  context: {cluster: c, user: u}
users:
- name: u
  user:
    <<: {exec: {command: sh, apiVersion: client.authentication.k8s.io/v1}}
    token: abc
YAML;
        $this->refusedWith($merged, 'exec', 'users[0].user.exec');

        // an alias repeats a harmless map, a second user adds exec to it
        $aliased = <<<'YAML'
apiVersion: v1
kind: Config
current-context: ctx
clusters:
- name: c
  cluster: &cluster
    server: https://api.example.com
contexts:
- name: ctx
  context: {cluster: c, user: u}
users:
- name: u
  user: &creds
    token: abc
- name: v
  user:
    <<: *creds
    exec: {command: /bin/sh, apiVersion: client.authentication.k8s.io/v1}
YAML;
        $this->refusedWith($aliased, 'exec', 'users[1].user.exec');

        // aliases of allowed values are fine: what is stored is the resolved value
        $fine = <<<'YAML'
apiVersion: v1
kind: Config
current-context: ctx
clusters:
- name: c
  cluster:
    server: https://api.example.com
contexts:
- name: ctx
  context: {cluster: c, user: u}
users:
- name: u
  user:
    token: &t abc
    username: *t
YAML;
        $result = KubeconfigPolicy::normalize($fine);
        $this->assertSame(['token' => 'abc', 'username' => 'abc'], $result->config['users'][0]['user']);
        $this->assertStringNotContainsString('&', $result->yaml());
    }

    public function test_several_documents_and_custom_tags_are_refused(): void
    {
        $two = self::yaml(self::config())."---\n".self::yaml(self::config([], ['exec' => ['command' => 'sh']]));
        $this->refusedWith($two, 'invalid');
        $this->refusedWith(str_replace('token: abc', 'token: !php/object O:8:"stdClass":0:{}', self::yaml(self::config())), 'value');
        $this->refusedWith(str_replace('token: abc', 'token: !custom abc', self::yaml(self::config())), 'invalid');
        $this->refusedWith('- just a list', 'invalid');
        $this->refusedWith('', 'invalid');
    }

    public function test_a_json_kubeconfig_is_read_and_stored_as_yaml(): void
    {
        $json = json_encode(self::config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = KubeconfigPolicy::normalize((string) $json);
        $this->assertSame('https://api.example.com:6443', $result->config['clusters'][0]['cluster']['server']);
        $this->assertStringStartsWith('apiVersion: v1', $result->yaml());

        $this->refusedWith((string) json_encode(self::config([], ['exec' => ['command' => 'sh']])), 'exec');
    }

    /** @return array<string, array{string}> */
    public static function refusedServers(): array
    {
        return [
            'http' => ['http://api.example.com:6443'],
            'user info' => ['https://user:pass@api.example.com'],
            'a query' => ['https://api.example.com/?x=1'],
            'a fragment' => ['https://api.example.com/#x'],
            'a backslash' => ['https://api.example.com\\@10.0.0.1/'],
            'a space' => ['https://api.example.com /'],
            'an octal address' => ['https://0177.0.0.1/'],
            'a decimal address' => ['https://2130706433/'],
            'a bad port' => ['https://api.example.com:99999'],
            'a bracketed name' => ['https://[api.example.com]/'],
            'no host' => ['https:///api'],
        ];
    }

    #[DataProvider('refusedServers')]
    public function test_refused_servers(string $server): void
    {
        $this->refusedWith(self::yaml(self::config(['server' => $server])), 'server');
    }

    public function test_values_are_checked(): void
    {
        $this->refusedWith(self::yaml(self::config([], ['token' => "abc\r\nX-Injected: 1"])), 'value', 'users[0].user.token');
        $this->refusedWith(self::yaml(self::config(['certificate-authority-data' => 'not base64!'])), 'value', 'certificate-authority-data');
        $this->refusedWith(self::yaml(self::config(['insecure-skip-tls-verify' => 'true'])), 'value', 'insecure-skip-tls-verify');
        $this->refusedWith(self::yaml(self::config(['tls-server-name' => 'a/b'])), 'value', 'tls-server-name');
        $this->refusedWith(self::yaml(self::config([], ['client-certificate-data' => self::CA])), 'key_pair');
        $this->refusedWith(self::yaml(self::config([], ['token' => 'abc'], [], ['namespace' => 'Not_A_Namespace'])), 'value', 'namespace');
        $this->refusedWith(str_repeat('#', KubeconfigPolicy::MAX_BYTES + 1), 'too_large');
    }

    public function test_runrs_own_address_pin_is_dropped_and_checked_again(): void
    {
        $result = KubeconfigPolicy::normalize(self::yaml(self::config(['proxy-url' => 'runr-pinned:203.0.113.10'])));

        $this->assertArrayNotHasKey('proxy-url', $result->config['clusters'][0]['cluster']);
        $pinned = Yaml::parse($result->pinnedYaml(['198.51.100.7', '2001:db8::7']));
        $this->assertSame('runr-pinned:198.51.100.7,2001:db8::7', $pinned['clusters'][0]['cluster']['proxy-url']);
        $this->assertSame($result->config, KubeconfigPolicy::normalize($result->pinnedYaml(['198.51.100.7']))->config);
    }
}
