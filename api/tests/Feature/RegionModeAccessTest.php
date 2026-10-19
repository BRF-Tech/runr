<?php

namespace Tests\Feature;

use App\Http\Requests\RegistrySetup\RegistrySetupRequest;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Modules\Tenancy\Http\Requests\TenancyStoreKnativeRegionRequest;
use Modules\Tenancy\Http\Requests\TenancyUpdateKnativeRegionRequest;
use Tests\TestCase;

/**
 * region_mode (managed / infinite tenant quotas) is the platform's choice: in a multi-tenant
 * install the region create, update and setup requests take it from platform administrators
 * only — anyone else's value (the console's region wizard sends the default with every new
 * region) is not validated and never reaches the region. A single-tenant install has no
 * organizations: whoever manages regions sets it.
 */
class RegionModeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The Tenancy module's column (central and tenant schemas)
        if (! Schema::hasColumn('knative_regions', 'region_mode')) {
            Schema::table('knative_regions', fn (Blueprint $table) => $table->string('region_mode', 20)->default('managed'));
        }
    }

    /** @return list<class-string<FormRequest>> */
    private static function requests(): array
    {
        return [TenancyStoreKnativeRegionRequest::class, TenancyUpdateKnativeRegionRequest::class, RegistrySetupRequest::class];
    }

    /**
     * @param  class-string<FormRequest>  $class
     * @return array<string, mixed> what the request validates of region_mode
     */
    private function validatedRegionMode(string $class, User $user): array
    {
        $request = $class::create('/api/knative-regions', 'POST', ['region_mode' => 'infinite']);
        $request->setUserResolver(fn () => $user);
        $rules = array_intersect_key($request->rules(), ['region_mode' => true]);

        return Validator::make(['region_mode' => 'infinite'], $rules)->validated();
    }

    /** is_admin: the Tenancy module's column of the users table, read by Rbac::isPlatformAdmin() */
    private function user(bool $admin): User
    {
        return User::factory()->make()->forceFill(['is_admin' => $admin]);
    }

    public function test_a_multi_tenant_install_takes_it_from_platform_administrators_only(): void
    {
        config(['tenancy.enabled' => true]);
        $admin = $this->user(true);
        $member = $this->user(false);

        foreach (self::requests() as $class) {
            $this->assertSame(['region_mode' => 'infinite'], $this->validatedRegionMode($class, $admin), "{$class}: platform administrator");
            $this->assertSame([], $this->validatedRegionMode($class, $member), "{$class}: organization member");
        }
    }

    public function test_a_single_tenant_install_takes_it_from_whoever_manages_regions(): void
    {
        config(['tenancy.enabled' => false]);
        $member = $this->user(false);

        foreach (self::requests() as $class) {
            $this->assertSame(['region_mode' => 'infinite'], $this->validatedRegionMode($class, $member), $class);
        }
    }

    public function test_an_unknown_mode_is_still_refused(): void
    {
        config(['tenancy.enabled' => true]);
        $request = TenancyUpdateKnativeRegionRequest::create('/api/knative-regions/x', 'PATCH');
        $request->setUserResolver(fn () => $this->user(true));

        $this->assertTrue(Validator::make(['region_mode' => 'unlimited'], $request->rules())->fails());
    }
}
