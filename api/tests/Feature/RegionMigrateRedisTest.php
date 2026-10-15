<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\KnativeDeployService;
use App\Services\KnativeInstallService;
use App\Services\KnativeRedisService;
use App\Services\RegionMigrateService;
use App\Services\SandboxInstallService;
use App\Services\ZotRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Moving a region to a new cluster reinstalls the RunR-managed Redis there (with the region's
 * password); a Redis that lives outside RunR (in_cluster / external) keeps its address.
 */
class RegionMigrateRedisTest extends TestCase
{
    use RefreshDatabase;

    private function service(KnativeRedisService $redis): RegionMigrateService
    {
        $installer = Mockery::mock(KnativeInstallService::class)->shouldIgnoreMissing();
        $installer->shouldReceive('runningKnative')->andReturn(['operator' => false, 'serving' => false]);

        return new RegionMigrateService(
            $installer,
            Mockery::mock(KnativeDeployService::class),
            Mockery::mock(ZotRegistryService::class),
            $redis,
            Mockery::mock(SandboxInstallService::class),
        );
    }

    private function region(string $redisMode): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => 'fn-'.$redisMode,
            'kubeconfig' => 'apiVersion: v1',
            'is_active' => true,
            'redis_mode' => $redisMode,
            'redis_address' => 'redis://redis.example:6379',
            'redis_password' => 's3cret',
        ]);
    }

    public function test_managed_redis_is_reinstalled_on_the_new_cluster(): void
    {
        $region = $this->region('managed');

        $redis = Mockery::mock(KnativeRedisService::class);
        $redis->shouldReceive('install')
            ->once()
            ->withArgs(fn ($r, $mode, $address, $password) => $r->is($region)
                && $mode === 'managed' && $address === null && $password === 's3cret');

        $this->service($redis)->installInfrastructure($region);
    }

    public function test_redis_outside_runr_is_left_alone(): void
    {
        foreach (['in_cluster', 'external'] as $mode) {
            $redis = Mockery::mock(KnativeRedisService::class);
            $redis->shouldNotReceive('install');

            $this->service($redis)->installInfrastructure($this->region($mode));
        }

        $this->addToAssertionCount(1);
    }
}
