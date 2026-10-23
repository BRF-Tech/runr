<?php

namespace Tests\Feature\Mcp\Tools;

use App\Mcp\Tools\ToolContext;
use App\Models\Project;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\RegionOverride;
use Modules\Billing\Models\RegionSetting;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * The Billing module's MCP tools (docs/mcp.md#tools, docs/billing.md#api): listed while billing is
 * on, with the permissions of the API routes they call — bills and their lines (a project's
 * preview environments and queue consumer apart), usage, prices and a new version that changes
 * only some prices, regions with queue consumer charging at every level, the platform's choice
 * per tenant (platform administrators), a project's billing tab, quotes, fixed requests and their
 * approval.
 */
class BillingToolsTest extends TestCase
{
    use EnablesBilling, McpToolFixtures, RefreshDatabase;

    private const TOOLS = [
        'billing_bills_list', 'billing_bill_get', 'billing_usage_get', 'billing_prices_get', 'billing_price_set',
        'billing_regions_list', 'billing_queue_consumers_set', 'billing_region_organizations', 'billing_region_organization_set',
        'billing_fixed_requests_list', 'billing_fixed_request_decide', 'project_billing_get', 'project_billing_quote', 'project_fixed_request',
    ];

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->setUpBilling();
        $this->setUpMcpTools();

        $this->project = $this->billedProject('orders', $this->region, $this->owner);
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        ToolContext::sleepUsing(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A platform administrator (users.is_admin, the Tenancy module's column). */
    private function platformAdmin(): User
    {
        if (! Schema::hasColumn('users', 'is_admin')) {
            Artisan::call('migrate', ['--path' => 'Modules/Tenancy/Database/Migrations/2026_03_10_100001_add_is_admin_to_users.php', '--force' => true]);
        }
        $admin = User::factory()->create(['username' => 'root-'.Str::lower(Str::random(4))]);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    public function test_the_tools_follow_the_permissions_of_their_routes(): void
    {
        [$token, $session] = $this->connect();
        $names = $this->mcpToolNames($token, $session);
        foreach (array_diff(self::TOOLS, ['billing_region_organizations', 'billing_region_organization_set']) as $tool) {
            $this->assertContains($tool, $names);
        }

        // The platform's choice per tenant: platform administrators only
        $this->assertNotContains('billing_region_organizations', $names, 'an organization owner is no platform administrator');
        $admin = $this->platformAdmin();
        [$adminToken, $adminSession] = $this->connect($admin);
        $this->assertContains('billing_region_organization_set', $this->mcpToolNames($adminToken, $adminSession));
        [$scoped, $scopedSession] = $this->connect($admin, ['billing.view']);
        $scopedNames = $this->mcpToolNames($scoped, $scopedSession);
        $this->assertContains('billing_region_organizations', $scopedNames);
        $this->assertNotContains('billing_region_organization_set', $scopedNames, 'needs billing.manage on the token');

        // Reading needs billing.view, changing billing.manage, deciding billing.approve
        $this->assertToolHidden('billing_bills_list', ['projects.view']);
        $this->assertToolHidden('billing_price_set', ['billing.view'], ['effective_from' => '2026-11-01', 'prices' => ['cpu_vcpu_hour' => 1]]);
        $this->assertToolHidden('billing_queue_consumers_set', ['billing.view'], ['region' => $this->region->id, 'level' => 'region', 'charge' => true]);
        $this->assertToolHidden('billing_fixed_request_decide', ['billing.manage'], ['request' => 'x', 'decision' => 'approve']);
    }

    public function test_bills_and_their_lines_show_preview_environments_apart(): void
    {
        // 25 vCPU-hours of the project, 50 of its previews (0.04 a vCPU-hour)
        $this->usageDay($this->project, '2026-10-05', 25);
        $this->usageDay($this->project, '2026-10-06', 50, attributes: ['source' => UsageRecord::SOURCE_PREVIEW]);

        $list = $this->structured($this->callTool('billing_bills_list', ['period' => '2026-10']));
        $this->assertSame(1, $list['total']);
        $bill = $list['bills'][0];
        $this->assertSame('open', $bill['status']);
        $this->assertEqualsWithDelta(3.0, $bill['total'], 0.001);

        $result = $this->callTool('billing_bill_get', ['bill' => $bill['id']]);
        $detail = $this->structured($result);
        $this->assertEqualsWithDelta(2.0, $detail['by_component']['preview']['total'], 0.001);
        $this->assertEqualsWithDelta(1.0, $detail['by_component']['project']['total'], 0.001);
        $this->assertStringContainsString('preview environments 2.00 USD', $result['content'][0]['text']);

        // The project's own view of the bill, as its owner
        $this->structured($this->callTool('billing_bill_get', ['bill' => $bill['id'], 'project' => $this->project->slug]));
        $this->mcpToolError($this->callTool('billing_bill_get', ['bill' => $bill['id'], 'project' => $this->project->slug, 'scope' => 'to_tenants']));

        $tab = $this->callTool('project_billing_get', ['project' => $this->project->id]);
        $this->assertEqualsWithDelta(2.0, $this->structured($tab)['current_by_component']['preview']['total'], 0.001);
        $this->assertStringContainsString('of it preview environments 2.00 USD', $tab['content'][0]['text']);

        $usage = $this->structured($this->callTool('billing_usage_get', ['period' => '2026-10', 'project' => $this->project->id]));
        $this->assertEqualsWithDelta(75.0, $usage['data'][0]['quantities']['vcpu_hours'], 0.001);

        $this->assertSchemaRejects('billing_bills_list', ['period' => '2026-13'], '/period');
        $this->mcpToolError($this->callTool('billing_bills_list', ['scope' => 'from_platform', 'project' => $this->project->id]));
    }

    public function test_a_price_version_changes_only_the_prices_given(): void
    {
        $prices = $this->structured($this->callTool('billing_prices_get'));
        $this->assertEqualsWithDelta(0.04, $prices['effective_defaults']['cpu_vcpu_hour'], 0.0001);

        $saved = $this->callTool('billing_price_set', ['effective_from' => '2026-11-01', 'prices' => ['cpu_vcpu_hour' => 0.05], 'note' => 'November']);
        $this->structured($saved);
        $version = Price::query()->whereNull('region_id')->whereDate('effective_from', '2026-11-01')->sole();
        $this->assertEqualsWithDelta(0.05, (float) $version->cpu_vcpu_hour, 0.0001);
        $this->assertEqualsWithDelta(0.005, (float) $version->memory_gb_hour, 0.0001, 'kept from the version before');

        // A later version builds on the scheduled one, a region's on what applies there
        $this->structured($this->callTool('billing_price_set', ['effective_from' => '2026-12-01', 'prices' => ['memory_gb_hour' => 0.01]]));
        $december = Price::query()->whereNull('region_id')->whereDate('effective_from', '2026-12-01')->sole();
        $this->assertEqualsWithDelta(0.05, (float) $december->cpu_vcpu_hour, 0.0001);
        $this->structured($this->callTool('billing_price_set', ['region' => $this->region->name, 'effective_from' => '2026-11-15', 'prices' => ['gpu_hour' => 2]]));
        $this->assertEqualsWithDelta(2.0, (float) Price::query()->where('region_id', $this->region->id)->sole()->gpu_hour, 0.0001);

        $region = $this->structured($this->callTool('billing_prices_get', ['region' => $this->region->id]));
        $this->assertSame($this->region->id, $region['region']['id']);

        // The API's rules stand: a date in the past, nothing to change, an unknown region
        $this->mcpToolError($this->callTool('billing_price_set', ['effective_from' => '2026-01-01', 'prices' => ['gpu_hour' => 2]]));
        $this->mcpToolError($this->callTool('billing_price_set', ['effective_from' => '2026-11-01', 'prices' => []]));
        $this->mcpToolError($this->callTool('billing_price_set', ['region' => 'nowhere', 'effective_from' => '2026-11-01', 'prices' => ['gpu_hour' => 2]]));
        $this->assertSchemaRejects('billing_price_set', ['effective_from' => '2026-11-01', 'prices' => ['cpu_vcpu_hour' => -1]], '/prices/cpu_vcpu_hour');
    }

    public function test_queue_consumer_charging_at_the_region_and_for_the_organization(): void
    {
        $this->structured($this->callTool('billing_queue_consumers_set', ['region' => $this->region->name, 'level' => 'region', 'charge' => true]));
        $this->assertTrue((bool) RegionSetting::query()->find($this->region->id)?->charge_queue_consumers);

        $off = $this->callTool('billing_queue_consumers_set', ['region' => $this->region->id, 'level' => 'organization', 'charge' => false]);
        $this->assertFalse($this->structured($off)['region']['queue_consumers_charged']);
        $this->assertStringContainsString('not charged', $off['content'][0]['text']);
        $this->assertFalse(RegionOverride::query()->find($this->region->id)?->charge_queue_consumers);

        $regions = $this->structured($this->callTool('billing_regions_list'))['regions'];
        $this->assertSame([true, false, false], [$regions[0]['charge_queue_consumers'], $regions[0]['queue_consumers_override'], $regions[0]['queue_consumers_charged']]);

        $this->structured($this->callTool('billing_queue_consumers_set', ['region' => $this->region->id, 'level' => 'organization', 'charge' => null]));
        $this->assertNull(RegionOverride::query()->find($this->region->id));

        $this->mcpToolError($this->callTool('billing_queue_consumers_set', ['region' => $this->region->id, 'level' => 'region', 'charge' => null]));
        $this->assertSchemaRejects('billing_queue_consumers_set', ['region' => $this->region->id, 'level' => 'tenant', 'charge' => false], '/level');

        // Without tenancy no organization shares a platform region: the platform's choice per tenant answers 404
        $admin = $this->platformAdmin();
        $this->assertSame(404, $this->mcpToolError($this->callTool('billing_region_organizations', ['region' => $this->region->id], $admin))['status']);
    }

    public function test_fixed_resources_are_quoted_requested_and_decided(): void
    {
        $fixedRegion = $this->billingRegion('fx', 'fixed');
        $shop = $this->billedProject('shop', $fixedRegion, $this->owner);

        $quote = $this->callTool('project_billing_quote', ['project' => $shop->id, 'cpu_m' => 1000, 'memory_mb' => 1024]);
        $this->assertEqualsWithDelta(28.0, $this->structured($quote)['monthly_price'], 0.001, '25 for the vCPU, 3 for the GB');
        $this->assertStringContainsString('28.00 USD a month', $quote['content'][0]['text']);

        $request = $this->structured($this->callTool('project_fixed_request', ['project' => $shop->id, 'cpu_m' => 1000, 'memory_mb' => 1024, 'note' => 'launch']))['request'];
        $this->assertSame(FixedRequest::PENDING, $request['status']);

        $listed = $this->structured($this->callTool('billing_fixed_requests_list', ['status' => 'pending']));
        $this->assertSame([$request['id']], array_column($listed['requests'], 'id'));

        $this->mcpToolError($this->callTool('billing_fixed_request_decide', ['request' => $request['id'], 'decision' => 'reject']));
        $approved = $this->structured($this->callTool('billing_fixed_request_decide', ['request' => $request['id'], 'decision' => 'approve', 'note' => 'ok']));
        $this->assertSame(FixedRequest::APPROVED, $approved['request']['status']);
        $this->assertSame(FixedRequest::APPROVED, FixedRequest::query()->own()->whereKey($request['id'])->sole()->status);

        // A second request, withdrawn
        $second = $this->structured($this->callTool('project_fixed_request', ['project' => $shop->id, 'cpu_m' => 2000, 'memory_mb' => 2048]))['request'];
        $cancelled = $this->structured($this->callTool('project_fixed_request', ['project' => $shop->id, 'cancel' => $second['id']]));
        $this->assertSame(FixedRequest::CANCELLED, $cancelled['request']['status']);
        $this->mcpToolError($this->callTool('project_fixed_request', ['project' => $shop->id, 'cancel' => $second['id'], 'cpu_m' => 1000]));
        $this->mcpToolError($this->callTool('project_fixed_request', ['project' => $shop->id]));

        // A pay-as-you-go region takes no request
        $this->assertSame(422, $this->mcpToolError($this->callTool('project_fixed_request', ['project' => $this->project->id, 'cpu_m' => 1000, 'memory_mb' => 1024]))['status']);
    }
}
