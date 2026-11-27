<?php

namespace Tests\Feature\Kubeconfigs;

use App\Models\KnativeRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Platform regions — an installation without tenancy, or a region a platform administrator
 * creates outside a tenant — keep the kubeconfig they are given: a credential plugin of a cloud
 * CLI or a private API server address is the operator's own choice (docs/security.md, "Tenant
 * region kubeconfigs"); errors keep their details.
 */
class PlatformRegionKubeconfigTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private static function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://10.0.0.5:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'abc', 'exec' => ['apiVersion' => 'client.authentication.k8s.io/v1', 'command' => 'aws', 'args' => ['eks', 'get-token']]]]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            'preferences' => [],
        ], 10, 2);
    }

    public function test_a_platform_region_keeps_its_exec_plugin_and_private_server(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Bus::fake();
        Http::fake(fn (Request $request) => match (true) {
            str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/version') => Http::response(['major' => '1', 'minor' => '33', 'gitVersion' => 'v1.33.0']),
            $request->method() === 'GET' => Http::response(['kind' => 'Status', 'code' => 404], 404),
            default => Http::response([]),
        });

        $this->postJson('/api/knative-regions/validate-kubeconfig', ['kubeconfig' => self::kubeconfig()])
            ->assertOk()->assertJsonPath('version', '1.33');

        $this->postJson('/api/knative-regions', ['name' => 'eks-1', 'kubeconfig' => self::kubeconfig(), 'nodes' => []])->assertCreated();
        $region = KnativeRegion::query()->where('name', 'eks-1')->sole();
        $this->assertSame(trim(self::kubeconfig()), $region->kubeconfig, 'stored and used as it came (the request trims it)');
    }

    public function test_a_platform_users_connection_error_keeps_its_details(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Http::fake(fn () => Http::failedConnection('cURL error 7: Failed to connect to 10.0.0.5 port 6443'));

        $this->postJson('/api/knative-regions/validate-kubeconfig', ['kubeconfig' => self::kubeconfig()])
            ->assertStatus(422)
            ->assertJsonPath('errors.kubeconfig.0', fn (string $message) => str_contains($message, 'cURL error 7: Failed to connect to 10.0.0.5'));
    }
}
