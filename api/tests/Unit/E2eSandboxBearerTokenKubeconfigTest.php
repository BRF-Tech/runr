<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use App\Services\Sandboxes\Exec\ExecKubeconfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * sbx-exec-bearer-token of the sandbox-api e2e runs a command through the native exec client with
 * a ServiceAccount's token: its kubeconfig is the region's cluster with the token as the only
 * credential, which the native client takes itself (no kubectl).
 */
class E2eSandboxBearerTokenKubeconfigTest extends TestCase
{
    protected function tearDown(): void
    {
        ExecKubeconfig::flush();
        parent::tearDown();
    }

    public function test_the_region_cluster_with_the_token_as_its_only_credential(): void
    {
        $ca = base64_encode("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");
        $kind = Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'kind-e2e',
            'clusters' => [
                ['name' => 'other', 'cluster' => ['server' => 'https://other.example:6443']],
                ['name' => 'kind-e2e', 'cluster' => ['server' => 'https://host.docker.internal:39211', 'certificate-authority-data' => $ca, 'tls-server-name' => 'localhost']],
            ],
            'users' => [['name' => 'kind-e2e', 'user' => ['client-certificate-data' => base64_encode('cert'), 'client-key-data' => base64_encode('key')]]],
            'contexts' => [['name' => 'kind-e2e', 'context' => ['cluster' => 'kind-e2e', 'user' => 'kind-e2e']]],
        ], 6, 2);

        $kubeconfig = E2eTestCommand::sbxBearerTokenKubeconfig($kind, 'eyJhbGciOiJSUzI1NiJ9.token');

        $parsed = Yaml::parse($kubeconfig);
        $this->assertSame(['token' => 'eyJhbGciOiJSUzI1NiJ9.token'], $parsed['users'][0]['user'], 'no client certificate');
        $this->assertSame(['server' => 'https://host.docker.internal:39211', 'certificate-authority-data' => $ca, 'tls-server-name' => 'localhost'], $parsed['clusters'][0]['cluster']);

        $config = ExecKubeconfig::parse($kubeconfig);
        $this->assertSame(['host.docker.internal', 39211, 'localhost', 'eyJhbGciOiJSUzI1NiJ9.token'], [$config->host, $config->port, $config->serverName, $config->token]);
    }

    public function test_a_kubeconfig_without_a_cluster_for_its_context_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        E2eTestCommand::sbxBearerTokenKubeconfig("apiVersion: v1\nkind: Config\ncurrent-context: x\n", 't');
    }
}
