<?php

namespace Tests\Feature\Billing\Concerns;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\RegionSetting;
use Modules\Billing\Providers\BillingServiceProvider;
use Modules\Billing\Services\BillingSettings;
use Modules\Billing\Support\DefaultPrices;

/**
 * The Billing module on the test database (the suite runs with every module off): the module's
 * config with billing.enabled, its provider registered (services, routes under /api, hooks and
 * listeners — BillingServiceProvider::wire()), its central migrations. tearDownBilling() removes
 * the static hooks again.
 */
trait EnablesBilling
{
    /** @param array<string, mixed> $config */
    protected function setUpBilling(array $config = []): void
    {
        config(['billing' => array_replace_recursive(require base_path('Modules/Billing/Config/config.php'), [
            'enabled' => true,
            'currency' => 'USD',
            'webhook' => ['url' => '', 'secret' => '', 'timeout' => 5, 'max_attempts' => 3],
        ], $config)]);

        Artisan::call('migrate', ['--path' => 'Modules/Billing/Database/Migrations', '--force' => true]);

        $this->app->register(BillingServiceProvider::class);
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();

        // Billing "started" long ago: earlier months of the tests are billed
        app(BillingSettings::class)->set(BillingSettings::STARTED_ON, '2020-01-01');
    }

    protected function tearDownBilling(): void
    {
        BillingServiceProvider::unwire();
    }

    protected function billingRegion(string $name = 'eu-1', string $type = 'payg', array $attributes = []): KnativeRegion
    {
        $region = KnativeRegion::query()->create($attributes + [
            'name' => $name.'-'.Str::lower(Str::random(4)),
            'project_namespace' => $name,
            'is_active' => true,
            'is_demo' => true,
            'total_cpu_m' => 64000,
            'total_memory_mb' => 262144,
        ]);
        if ($type !== 'payg') {
            RegionSetting::query()->create(['region_id' => $region->id, 'billing_type' => $type]);
        }

        return $region;
    }

    protected function billedProject(string $name, ?KnativeRegion $region, ?User $owner = null, array $attributes = []): Project
    {
        return Project::query()->create($attributes + [
            'name' => $name.'-'.Str::lower(Str::random(4)),
            'service_type' => 'caas',
            'knative_region_id' => $region?->id,
            'created_by' => $owner?->id,
            'owner_id' => $owner?->id,
        ]);
    }

    /** @param array<string, float> $overrides */
    protected function priceVersion(?string $regionId, string $effectiveFrom, array $overrides = []): Price
    {
        return Price::query()->create(array_merge(DefaultPrices::VALUES, $overrides, [
            'region_id' => $regionId,
            'effective_from' => $effectiveFrom,
        ]));
    }

    /**
     * A day of metered usage of a project: vCPU-hours, GB-hours, GPU-hours.
     */
    protected function usageDay(Project $project, string $date, float $vcpuHours, float $gbHours = 0, float $gpuHours = 0, string $entryPoint = 'http', array $attributes = []): void
    {
        DB::table('usage_records')->insert($attributes + [
            'id' => (string) Str::ulid(),
            'project_id' => $project->id,
            'deployment_id' => (string) Str::ulid(),
            'region_id' => $project->knative_region_id,
            'record_date' => $date,
            'scale_mode' => 'scale_to_zero',
            'entry_point' => $entryPoint,
            'session_count' => 10,
            'total_cpu_mc_ms' => (int) round($vcpuHours * 3_600_000_000),
            'total_memory_mib_ms' => (int) round($gbHours * 1024 * 3_600_000),
            'total_gpu_unit_ms' => (int) round($gpuHours * 3_600_000),
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function allocation(Project $project, int $cpuM, int $memoryMb, string $from, ?string $until = null, string $status = FixedRequest::APPROVED, int $gpus = 0): FixedRequest
    {
        return FixedRequest::query()->create([
            'project_id' => $project->id,
            'project_name' => $project->name,
            'region_id' => $project->knative_region_id,
            'cpu_m' => $cpuM,
            'memory_mb' => $memoryMb,
            'gpu_count' => $gpus,
            'status' => $status,
            'monthly_price' => 0,
            'currency' => 'USD',
            'active_from' => $from,
            'active_until' => $until,
        ]);
    }

    protected function bearer(User $user, array $abilities): static
    {
        $plain = $user->createToken('billing-system', $abilities)->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$plain)->withHeader('Accept', 'application/json');
    }
}
