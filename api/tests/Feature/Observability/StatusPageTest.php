<?php

namespace Tests\Feature\Observability;

use App\Models\Observability\StatusPageRoute;
use App\Models\Observability\UptimeCheck;
use App\Models\Observability\UptimeIncident;
use App\Models\Observability\UptimeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * Public status page: settings (status_page.manage), slug / custom domain uniqueness through the
 * central lookup, components chosen from the uptime checks, the public payload (overall status,
 * daily uptime, incident history) by slug and by domain — only when public, never behind sign-in.
 */
class StatusPageTest extends TestCase
{
    use ActsWithRoles, FakesObservabilityCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();
    }

    public function test_settings_and_public_page(): void
    {
        $owner = $this->userWithRole('owner');
        $dev = $this->userWithRole('developer');
        $project = $this->obsProject($this->obsRegion());
        $api = UptimeCheck::query()->create(['project_id' => $project->id, 'name' => 'API', 'status' => 'up']);
        $web = UptimeCheck::query()->create(['project_id' => $project->id, 'name' => 'Web', 'status' => 'down']);
        UptimeCheck::query()->create(['project_id' => $project->id, 'name' => 'Internal', 'status' => 'up']);
        UptimeResult::query()->create(['uptime_check_id' => $api->id, 'location' => 'control-plane', 'ok' => true, 'checked_at' => now()->subHour()]);
        UptimeResult::query()->create(['uptime_check_id' => $api->id, 'location' => 'control-plane', 'ok' => false, 'checked_at' => now()->subMinutes(30)]);
        UptimeIncident::query()->create(['uptime_check_id' => $web->id, 'project_id' => $project->id, 'status' => 'open', 'title' => 'Web is down', 'message' => 'Investigating', 'cause' => 'Status 502 from 10.0.0.4', 'started_at' => now()->subMinutes(10)]);

        $this->signedIn($dev)->getJson('/api/observability/status-page')->assertForbidden()->assertJsonPath('permissions', ['status_page.manage']);

        $this->signedIn($owner)->getJson('/api/observability/status-page')
            ->assertOk()
            ->assertJsonPath('data.page.exists', false)
            ->assertJsonPath('data.page.slug', 'status')
            ->assertJsonCount(3, 'data.components');

        // not public yet
        $this->signedIn($owner)->putJson('/api/observability/status-page', [
            'slug' => 'acme', 'title' => 'Acme status', 'is_public' => false,
            'components' => [
                ['id' => $api->id, 'show_on_status_page' => true, 'status_page_name' => 'Public API', 'status_page_order' => 1],
                ['id' => $web->id, 'show_on_status_page' => true, 'status_page_order' => 2],
            ],
        ])->assertOk()->assertJsonPath('data.page.exists', true)->assertJsonPath('data.preview.status', 'partial_outage');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public/status-pages/acme')->assertNotFound();

        $this->signedIn($owner)->putJson('/api/observability/status-page', [
            'slug' => 'acme', 'title' => 'Acme status', 'is_public' => true, 'custom_domain' => 'Status.Acme.example.',
        ])->assertOk()->assertJsonPath('data.page.custom_domain', 'status.acme.example');

        $this->app['auth']->forgetGuards();
        $page = $this->getJson('/api/public/status-pages/acme')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=30, public')
            ->assertJsonPath('data.title', 'Acme status')
            ->assertJsonPath('data.status', 'partial_outage')
            ->assertJsonPath('data.components.0.name', 'Public API')
            ->assertJsonPath('data.components.0.status', 'operational')
            ->assertJsonPath('data.components.1.status', 'outage')
            ->assertJsonCount(90, 'data.components.0.days')
            ->assertJsonPath('data.incidents.0.title', 'Web is down')
            ->assertJsonPath('data.incidents.0.component', 'Web')
            ->assertJsonPath('data.branding.product_name', 'RunR')
            ->json('data');

        $this->assertEqualsWithDelta(50.0, $page['components'][0]['uptime_24h'], 0.01);
        $this->assertArrayNotHasKey('cause', $page['incidents'][0], 'internal details stay internal');
        $this->assertStringNotContainsString('10.0.0.4', json_encode($page));
        $this->assertCount(2, $page['components'], 'only the chosen checks');

        $this->getJson('/api/public/status-pages?domain=STATUS.acme.example')->assertOk()->assertJsonPath('data.title', 'Acme status');
        $this->getJson('/api/public/status-pages?domain=other.example')->assertNotFound();
        $this->getJson('/api/public/status-pages/NOT_A_SLUG')->assertNotFound();
    }

    public function test_slug_and_domain_must_be_free(): void
    {
        $owner = $this->userWithRole('owner');
        StatusPageRoute::query()->create(['slug' => 'taken', 'custom_domain' => 'status.taken.example', 'tenant_id' => 'another-tenant', 'status_page_id' => '01JOTHERPAGE00000000000000', 'is_public' => true]);

        $this->signedIn($owner)->putJson('/api/observability/status-page', ['slug' => 'taken', 'title' => 'x', 'is_public' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $this->signedIn($owner)->putJson('/api/observability/status-page', ['slug' => 'mine', 'title' => 'x', 'is_public' => true, 'custom_domain' => 'status.taken.example'])
            ->assertUnprocessable()->assertJsonValidationErrors(['custom_domain']);
        $this->signedIn($owner)->putJson('/api/observability/status-page', ['slug' => 'Bad Slug', 'title' => 'x', 'is_public' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['slug']);

        // saving its own page again with the same slug is fine
        $this->signedIn($owner)->putJson('/api/observability/status-page', ['slug' => 'mine', 'title' => 'x', 'is_public' => true])->assertOk();
        $this->signedIn($owner)->putJson('/api/observability/status-page', ['slug' => 'mine', 'title' => 'y', 'is_public' => true])->assertOk();
        $this->assertSame(1, StatusPageRoute::query()->where('slug', 'mine')->count());
    }
}
