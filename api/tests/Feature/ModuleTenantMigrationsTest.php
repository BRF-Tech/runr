<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Providers\BillingServiceProvider;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantSchema;
use Modules\Ticket\Providers\TicketServiceProvider;
use SharedSupport\TenantMigrationRegistry;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * A module's tenant migrations are registered whether the module's switch is on or not
 * (docs/upgrade.md): an upgrade that turns billing or tickets on later finds the tables in every
 * tenant schema — tenancy:migrate created them — instead of answering with "relation billing_markups
 * does not exist". Runs on SQLite and on PostgreSQL (DB_CONNECTION=pgsql).
 */
class ModuleTenantMigrationsTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    /** @var list<string> */
    private array $registryBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        $this->registryBefore = TenantMigrationRegistry::paths();
        config(['tenancy.enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach (Tenant::query()->get() as $tenant) {
            $this->wiredTenants[] = $tenant;
        }
        (new \ReflectionProperty(TenantMigrationRegistry::class, 'paths'))->setValue(null, $this->registryBefore);
        BillingServiceProvider::unwire();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_the_tenant_migrations_of_switched_off_modules_run_in_every_tenant_schema(): void
    {
        config([
            'billing' => array_replace(require base_path('Modules/Billing/Config/config.php'), ['enabled' => false, 'currency' => 'USD']),
            'ticket.enabled' => false,
        ]);
        Artisan::call('migrate', ['--path' => 'Modules/Billing/Database/Migrations', '--force' => true]);
        $this->app->register(BillingServiceProvider::class, true);
        $this->app->register(TicketServiceProvider::class, true);

        $paths = array_map('realpath', TenantMigrationRegistry::paths());
        $this->assertContains(realpath(base_path('Modules/Billing/Database/TenantMigrations')), $paths);
        $this->assertContains(realpath(base_path('Modules/Ticket/Database/TenantMigrations')), $paths);

        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        TenantSchema::create($tenant);

        $tables = TenantSchema::inTenant($tenant, function () use ($tenant) {
            $prefix = DB::connection()->getDriverName() === 'pgsql' ? $tenant->database()->getName().'.' : '';

            return array_map(fn (string $t) => Schema::hasTable($prefix.$t), ['billing_markups', 'billing_bills', 'billing_settings', 'support_tickets']);
        });
        $this->assertSame([true, true, true, true], $tables);
    }
}
