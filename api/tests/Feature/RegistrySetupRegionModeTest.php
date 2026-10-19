<?php

namespace Tests\Feature;

use App\Http\Requests\RegistrySetup\RegistrySetupRequest;
use App\Jobs\InstallRegistryAndCreateRegionJob;
use App\Models\KnativeRegion;
use App\Services\KnativeNodeService;
use App\Services\KnativeRedisService;
use App\Services\KnativeStatusService;
use App\Services\ZotRegistryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * The region wizard's Region Mode (managed / infinite) reaches the region it creates through
 * POST /registry-setup/start — where the regions table has the column (the Tenancy module adds
 * it); a single-tenant install has none and the field is not taken.
 */
class RegistrySetupRegionModeTest extends TestCase
{
    use RefreshDatabase;

    private function runSetup(array $config): KnativeRegion
    {
        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldReceive('testExternalRegistry')->andReturn(['success' => true, 'message' => 'ok']);
        $status = Mockery::mock(KnativeStatusService::class);
        $status->shouldReceive('createProjectNamespace')->andReturnNull();
        $redis = Mockery::mock(KnativeRedisService::class);
        $redis->shouldReceive('queueInitialInstall')->andReturnNull();
        $this->app->instance(KnativeRedisService::class, $redis);

        (new InstallRegistryAndCreateRegionJob('token-1', array_merge([
            'name' => 'eu-1', 'kubeconfig' => 'apiVersion: v1', 'nodes' => [],
            'registry_mode' => 'external', 'external_address' => 'registry.example.test',
        ], $config)))->handle($zot, $status, Mockery::mock(KnativeNodeService::class));

        return KnativeRegion::where('name', 'eu-1')->firstOrFail();
    }

    public function test_the_region_mode_is_taken_where_the_column_exists(): void
    {
        if (! Schema::hasColumn('knative_regions', 'region_mode')) {
            Schema::table('knative_regions', fn (Blueprint $table) => $table->string('region_mode', 20)->default('managed'));
        }

        $this->assertArrayHasKey('region_mode', (new RegistrySetupRequest)->rules());
        $this->assertSame('infinite', $this->runSetup(['region_mode' => 'infinite'])->fresh()->region_mode);
    }

    public function test_without_the_column_the_field_is_not_taken(): void
    {
        $this->assertFalse(Schema::hasColumn('knative_regions', 'region_mode'), 'the suite runs without the Tenancy module');

        $this->assertArrayNotHasKey('region_mode', (new RegistrySetupRequest)->rules());
        $this->assertSame('eu-1', $this->runSetup(['region_mode' => 'infinite'])->name, 'created all the same');
    }
}
