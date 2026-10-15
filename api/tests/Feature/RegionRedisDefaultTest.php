<?php

namespace Tests\Feature;

use App\Jobs\InstallRedisJob;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\KnativeRedisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A new region comes with managed Redis unless the create request opts out with
 * redis_mode "none" — the same for POST /knative-regions and the setup wizard's
 * registry setup (both call KnativeRedisService::queueInitialInstall).
 */
class RegionRedisDefaultTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://region.test:6443';

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    /** Cluster API: version 1.31, the project namespace does not exist yet, every write succeeds. */
    private function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/version') {
                return Http::response(['major' => '1', 'minor' => '31']);
            }
            if ($request->method() === 'GET' && str_starts_with($path, '/api/v1/namespaces/')) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return Http::response(['kind' => 'Namespace', 'metadata' => ['name' => 'fn']]);
        });
    }

    public function test_store_installs_managed_redis_by_default(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $this->fakeCluster();
        $user = User::factory()->create(['username' => 'admin']);

        $this->actingAs($user, 'sanctum')->postJson('/api/knative-regions', [
            'name' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'nodes' => [],
        ])->assertCreated()
            ->assertJsonPath('redis_mode', 'managed')
            ->assertJsonPath('knative_redis_status', 'installing');

        Bus::assertDispatched(InstallRedisJob::class, fn (InstallRedisJob $job) => $job->mode === 'managed' && $job->region->name === 'fn' && $job->address === null);
    }

    public function test_store_skips_redis_when_opted_out(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $this->fakeCluster();
        $user = User::factory()->create(['username' => 'admin']);

        $this->actingAs($user, 'sanctum')->postJson('/api/knative-regions', [
            'name' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'nodes' => [],
            'redis_mode' => 'none',
        ])->assertCreated();

        Bus::assertNotDispatched(InstallRedisJob::class);
        $this->assertNull(KnativeRegion::where('name', 'fn')->value('redis_mode'));
    }

    public function test_store_external_redis_needs_an_address(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $this->fakeCluster();
        $user = User::factory()->create(['username' => 'admin']);

        $this->actingAs($user, 'sanctum')->postJson('/api/knative-regions', [
            'name' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'nodes' => [],
            'redis_mode' => 'external',
        ])->assertUnprocessable()->assertJsonValidationErrors('redis_address');

        Bus::assertNotDispatched(InstallRedisJob::class);
    }

    public function test_initial_install_passes_external_settings(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn']);

        app(KnativeRedisService::class)->queueInitialInstall($region, 'external', 'redis.example:6379', 's3cret', 'u1');

        Bus::assertDispatched(InstallRedisJob::class, fn (InstallRedisJob $job) => $job->mode === 'external' && $job->address === 'redis.example:6379' && $job->password === 's3cret');
        $this->assertSame('external', $region->fresh()->redis_mode);
        $this->assertSame('installing', $region->fresh()->knative_redis_status);
    }
}
