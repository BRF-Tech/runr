<?php

namespace Tests\Feature\WhiteLabel;

use App\Events\UserRegistered;
use App\Services\WhiteLabel\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Listeners\CreateTenantOnRegistration;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantOnboarding;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Support\TenantRegionShares;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\TestCase;

/**
 * Multi-tenant self-service signup: the tenant is created automatically (form name or
 * "<name>'s workspace"), the owner attached, the onboarding wizard opened. The wizard renames the
 * tenant and assigns the offered region with the default quota (clamped to the free capacity) —
 * accepted at once, the tenant chose it.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;
    use WhiteLabelTenancy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy();
    }

    /** @var list<Tenant> tenants provisioned by the listener (schema dropped in tearDown) */
    private array $provisioned = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        foreach ($this->provisioned as $tenant) {
            try {
                $tenant->database()->manager()->deleteDatabase($tenant);
            } catch (\Throwable) {
                // already gone
            }
        }
        $this->tearDownTenancy();
        parent::tearDown();
    }

    /**
     * The listener with the test schema instead of every tenant migration (the wizard's requests run
     * inside the tenant: it is the RBAC organization of the request).
     */
    private function listener(): CreateTenantOnRegistration
    {
        $provision = function (Tenant $tenant): void {
            $tenant->database()->manager()->createDatabase($tenant);
            $this->provisioned[] = $tenant;
            $tenant->run(function () {
                foreach (self::WL_TENANT_MIGRATIONS as $migration) {
                    (require base_path($migration))->up();
                }
            });
        };

        return new class($provision) extends CreateTenantOnRegistration
        {
            public function __construct(private readonly \Closure $provisionWith) {}

            protected function provision(Tenant $tenant): void
            {
                ($this->provisionWith)($tenant);
            }
        };
    }

    private function register(array $requestData = [], array $user = []): Tenant
    {
        $u = $this->makeUser(array_merge(['name' => 'Jane Doe'], $user));
        $this->listener()->handle(new UserRegistered($u, $requestData));

        $tenantId = TenantUser::query()->where('user_id', $u->id)->value('tenant_id');

        return Tenant::query()->findOrFail($tenantId);
    }

    public function test_a_signup_without_workspace_name_gets_a_default_tenant_with_the_wizard_open(): void
    {
        $tenant = $this->register();

        $this->assertSame("Jane Doe's workspace", $tenant->name);
        $this->assertSame('jane-does-workspace', $tenant->slug);
        $this->assertSame('owner', TenantUser::query()->where('tenant_id', $tenant->id)->value('role'));
        $this->assertSame('pending', TenantOnboarding::query()->find($tenant->id)->status);
    }

    public function test_the_form_name_is_used_and_slugs_stay_unique(): void
    {
        $first = $this->register(['tenant_name' => 'Acme Labs']);
        $second = $this->register(['tenant_name' => 'Acme Labs']);

        $this->assertSame('acme-labs', $first->slug);
        $this->assertSame('acme-labs-1', $second->slug);
    }

    public function test_no_tenant_when_auto_creation_is_off_and_no_name_was_given(): void
    {
        app(PlatformSettings::class)->set('signup_tenant_auto_create', false);
        $user = $this->makeUser();

        $this->listener()->handle(new UserRegistered($user, []));

        $this->assertFalse(TenantUser::query()->where('user_id', $user->id)->exists());
    }

    public function test_the_wizard_offers_regions_and_completes_with_the_default_quota(): void
    {
        $eu = $this->platformRegion('eu-1');
        $this->platformRegion('us-1');
        $this->platformRegion('demo', ['is_demo' => true]);
        $tenant = $this->register();
        $owner = TenantUser::query()->where('tenant_id', $tenant->id)->first()->user;

        app(PlatformSettings::class)->set('signup_default_cpu_quota_m', 1500);
        app(PlatformSettings::class)->set('signup_default_memory_quota_mb', 3072);

        $this->actingAs($owner)->getJson('/api/onboarding', $this->tenantHeaders($tenant))->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('can_complete', true)
            ->assertJsonCount(2, 'regions')
            ->assertJsonPath('default_quota.cpu_quota_m', 1500)
            ->assertJsonMissingPath('plan');

        // two regions offered → the region must be chosen; an unknown one is refused
        $this->actingAs($owner)->postJson('/api/onboarding', ['org_name' => 'Acme', 'region_id' => '01UNKNOWNREGION0000000000'], $this->tenantHeaders($tenant))
            ->assertStatus(422)->assertJsonValidationErrors('region_id');

        $this->actingAs($owner)->postJson('/api/onboarding', ['org_name' => 'Acme Labs', 'region_id' => $eu->id], $this->tenantHeaders($tenant))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('tenant.name', 'Acme Labs')
            ->assertJsonMissingPath('checkout_url');

        $row = DB::connection('central')->table('tenant_regions')->where('tenant_id', $tenant->id)->where('knative_region_id', $eu->id)->first();
        $this->assertSame([1500, 3072], [(int) $row->cpu_quota_m, (int) $row->memory_quota_mb]);
        $this->assertSame('completed', TenantOnboarding::query()->find($tenant->id)->status);
    }

    public function test_a_single_offered_region_is_assigned_without_asking_and_clamped_to_capacity(): void
    {
        $eu = $this->platformRegion('eu-1', ['total_cpu_m' => 3000, 'total_memory_mb' => 6144]);
        $other = $this->plainTenant('other');
        $this->attachRegion($other, $eu, 2000, 4096);
        app(PlatformSettings::class)->set('signup_regions', (string) $eu->id);

        $tenant = $this->register();
        $owner = TenantUser::query()->where('tenant_id', $tenant->id)->first()->user;

        $this->actingAs($owner)->postJson('/api/onboarding', ['org_name' => 'Solo'], $this->tenantHeaders($tenant))->assertOk();

        $row = DB::connection('central')->table('tenant_regions')->where('tenant_id', $tenant->id)->first();
        $this->assertSame((string) $eu->id, (string) $row->knative_region_id);
        $this->assertSame([1000, 2048], [(int) $row->cpu_quota_m, (int) $row->memory_quota_mb]);
    }

    public function test_only_owners_and_admins_complete_the_wizard(): void
    {
        $tenant = $this->register();
        $member = $this->member($tenant, 'member');

        $this->actingAs($member)->getJson('/api/onboarding', $this->tenantHeaders($tenant))->assertJsonPath('can_complete', false);
        $this->actingAs($member)->postJson('/api/onboarding', ['org_name' => 'X'], $this->tenantHeaders($tenant))->assertForbidden();
    }

    public function test_the_wizard_region_is_accepted_at_once_while_shared_regions_wait_for_acceptance(): void
    {
        // Billing on: regions the platform shares wait for the tenant's acceptance — the region the
        // tenant chose in the wizard is accepted with that choice (docs/tenancy.md)
        TenantRegionShares::requireAcceptanceWhen(fn () => true);
        try {
            $eu = $this->platformRegion('eu-1');
            $tenant = $this->register();
            $owner = TenantUser::query()->where('tenant_id', $tenant->id)->first()->user;

            $this->actingAs($owner)->postJson('/api/onboarding', ['org_name' => 'Acme', 'region_id' => $eu->id], $this->tenantHeaders($tenant))
                ->assertOk()->assertJsonPath('status', 'completed');

            $row = DB::connection('central')->table('tenant_regions')->where('tenant_id', $tenant->id)->first();
            $this->assertSame(TenantRegionShares::ACTIVE, $row->status);
            $this->assertNotNull($row->accepted_at);
            $this->assertSame((string) $owner->id, (string) $row->accepted_by);
        } finally {
            TenantRegionShares::requireAcceptanceWhen(null);
        }
    }

    public function test_a_tenant_created_by_an_admin_has_no_wizard(): void
    {
        $byAdmin = $this->createTenantWithSchema('adminmade', self::WL_TENANT_MIGRATIONS);
        $this->actingAs($this->member($byAdmin))->getJson('/api/onboarding', $this->tenantHeaders($byAdmin))
            ->assertJsonPath('status', 'none');
    }
}
