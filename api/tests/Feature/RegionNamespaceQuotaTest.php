<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\Helpers\OrganizationCeiling;
use App\Services\RegionNamespaceQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * RegionNamespaceQuotaService::applyNamespaceQuota() puts the organization
 * ceiling on the Region's project namespace through the Region's kubeconfig, and removes
 * RunR's own objects again when no ceiling is configured.
 */
class RegionNamespaceQuotaTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{method: string, path: string, body: string}> */
    private array $calls = [];

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create($attributes + [
            'name' => 'fn',
            'project_namespace' => 'fn-projects',
            'kubeconfig' => $this->kubeconfig(),
            'is_active' => true,
        ]);
    }

    /** @param array<string, ?array> $existing path suffix → object returned by GET */
    private function fakeCluster(array $existing = []): void
    {
        Http::fake(function (Request $request) use ($existing) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = ['method' => $request->method(), 'path' => $path, 'body' => $request->body()];

            if ($request->method() === 'GET') {
                foreach ($existing as $suffix => $object) {
                    if (str_ends_with($path, $suffix)) {
                        return Http::response($object);
                    }
                }

                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return Http::response(['kind' => 'Status'], 200);
        });
    }

    private function calls(string $method): array
    {
        return array_values(array_map(
            fn ($c) => $c['path'],
            array_filter($this->calls, fn ($c) => $c['method'] === $method),
        ));
    }

    public function test_ceiling_is_read_from_config(): void
    {
        config(['quota.cpu' => '8', 'quota.memory_gib' => '0.5']);
        $this->assertSame(8000, OrganizationCeiling::cpuMillicores());
        $this->assertSame(512, OrganizationCeiling::memoryMb());
        $this->assertTrue(OrganizationCeiling::isConfigured());

        foreach ([null, '', '0', 0, '-2', 'abc'] as $none) {
            config(['quota.cpu' => $none, 'quota.memory_gib' => $none]);
            $this->assertNull(OrganizationCeiling::cpuMillicores(), var_export($none, true));
            $this->assertNull(OrganizationCeiling::memoryMb(), var_export($none, true));
        }
        $this->assertFalse(OrganizationCeiling::isConfigured());
    }

    public function test_a_ceiling_is_applied_to_the_project_namespace(): void
    {
        config(['quota.cpu' => '4', 'quota.memory_gib' => '8']);
        $this->fakeCluster();

        $result = app(RegionNamespaceQuotaService::class)->applyNamespaceQuota($this->region());

        $this->assertSame(RegionNamespaceQuotaService::RESULT_APPLIED, $result);
        $this->assertSame([
            '/api/v1/namespaces/fn-projects/resourcequotas/runr-organization-ceiling',
            '/api/v1/namespaces/fn-projects/limitranges/runr-container-defaults',
        ], $this->calls('PATCH'));

        $quota = json_decode($this->calls[0]['body'], true);
        // CPU: reserved CPU only (Kata's queue-proxy bursts above its request — NamespaceQuotaTrait)
        $this->assertSame([
            'requests.cpu' => '4000m',
            'requests.memory' => '8192Mi',
            'limits.memory' => '8192Mi',
        ], $quota['spec']['hard']);
    }

    public function test_without_a_ceiling_runr_managed_objects_are_removed(): void
    {
        config(['quota.cpu' => null, 'quota.memory_gib' => null]);
        $managed = ['metadata' => ['labels' => ['app.kubernetes.io/managed-by' => 'runr']]];
        $this->fakeCluster([
            '/resourcequotas/runr-organization-ceiling' => $managed,
            '/limitranges/runr-container-defaults' => ['metadata' => ['labels' => ['owner' => 'someone-else']]],
        ]);

        $result = app(RegionNamespaceQuotaService::class)->applyNamespaceQuota($this->region());

        $this->assertSame(RegionNamespaceQuotaService::RESULT_REMOVED, $result);
        $this->assertSame([], $this->calls('PATCH'));
        // A LimitRange of that name not labelled as RunR's is left alone
        $this->assertSame(['/api/v1/namespaces/fn-projects/resourcequotas/runr-organization-ceiling'], $this->calls('DELETE'));
    }

    public function test_without_a_ceiling_and_nothing_to_remove_nothing_changes(): void
    {
        config(['quota.cpu' => '0', 'quota.memory_gib' => '']);
        $this->fakeCluster();

        $this->assertSame(RegionNamespaceQuotaService::RESULT_SKIPPED, app(RegionNamespaceQuotaService::class)->applyNamespaceQuota($this->region()));
        $this->assertSame([], $this->calls('PATCH'));
        $this->assertSame([], $this->calls('DELETE'));
    }

    public function test_demo_and_tenant_owned_regions_are_skipped(): void
    {
        config(['quota.cpu' => '4', 'quota.memory_gib' => '8']);
        $this->fakeCluster();

        $service = app(RegionNamespaceQuotaService::class);
        $this->assertSame(RegionNamespaceQuotaService::RESULT_SKIPPED, $service->applyNamespaceQuota($this->region(['name' => 'demo', 'kubeconfig' => null, 'is_demo' => true])));

        $tenantOwned = $this->region(['name' => 'own']);
        $tenantOwned->forceFill(['owner_tenant_id' => 'acme']);
        $this->assertSame(RegionNamespaceQuotaService::RESULT_SKIPPED, $service->applyNamespaceQuota($tenantOwned));

        $this->assertSame([], $this->calls);
    }

    public function test_a_refused_apply_fails_loudly(): void
    {
        config(['quota.cpu' => '4']);
        Http::fake(fn () => Http::response(['kind' => 'Status', 'code' => 403], 403));

        $this->expectException(\RuntimeException::class);
        app(RegionNamespaceQuotaService::class)->applyNamespaceQuota($this->region());
    }
}
