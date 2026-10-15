<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Console\CreateTenantCommand;
use Modules\Tenancy\Http\Controllers\TenantController;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Services\TenancyLoginService;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * RunR has no plans or packages. The tenant `plan` label (free/starter/pro/
 * enterprise in the API, basic/premium/enterprise in the CLI, `basic` as the column default)
 * is gone from the schema, the API, the CLI and the login payload.
 */
class TenantPlanRemovedTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const MIGRATION = 'Modules/Tenancy/Database/Migrations/2026_10_01_300001_drop_plan_from_tenants_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        // Central connection + the Tenancy module's central migrations (suite runs with TENANCY_ENABLED off)
        $this->setUpTenancy();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_tenants_table_has_no_plan_column(): void
    {
        $this->assertTrue(Schema::connection('central')->hasTable('tenants'));
        $this->assertFalse(Schema::connection('central')->hasColumn('tenants', 'plan'));
        $this->assertNotContains('plan', Tenant::getCustomColumns());
        $this->assertNotContains('plan', (new Tenant)->getFillable());
    }

    public function test_drop_migration_is_reversible(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $this->assertTrue(Schema::connection('central')->hasColumn('tenants', 'plan'));

        $migration->up();
        $this->assertFalse(Schema::connection('central')->hasColumn('tenants', 'plan'));
    }

    public function test_update_ignores_a_plan_field(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        $request = Request::create("/api/tenants/{$tenant->id}", 'PATCH', ['name' => 'Acme 2', 'plan' => 'enterprise']);
        $response = app(TenantController::class)->update($request, $tenant->id);

        $body = $response->getData(true);
        $this->assertSame('Acme 2', $body['name']);
        $this->assertArrayNotHasKey('plan', $body);

        $fresh = Tenant::find($tenant->id);
        $this->assertSame('Acme 2', $fresh->name);
        $this->assertNull($fresh->getAttribute('plan'), 'plan must not land in the data JSON either');
    }

    public function test_create_tenant_command_has_no_plan_option(): void
    {
        $this->assertFalse((new CreateTenantCommand)->getDefinition()->hasOption('plan'));
    }

    public function test_login_payload_lists_tenants_without_plan(): void
    {
        $user = User::create([
            'name' => 'Jane',
            'username' => 'jane',
            'email' => 'jane@example.test',
            'password' => 'secret-password',
        ]);
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

        $data = app(TenancyLoginService::class)->loginData($user);

        $this->assertCount(1, $data['tenants']);
        $this->assertSame(['id', 'name', 'slug', 'role'], array_keys($data['tenants'][0]));
        $this->assertSame('acme', $data['active_tenant']['slug']);
    }
}
