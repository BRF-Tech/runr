<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Audit log API (docs/security.md): filters, pagination, detail, CSV / JSON export (audit.export),
 * tenant scoping, retention setting.
 */
class AuditLogApiTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        // Owner: audit.view, audit.export, settings.manage. Developer: none of them.
        $this->admin = $this->userWithRole('owner', ['name' => 'Ada Admin']);
        $this->member = $this->userWithRole('developer');
    }

    protected function tearDown(): void
    {
        TenantAccess::setResolvers(null, null);
        parent::tearDown();
    }

    private function seedEvent(array $attributes): AuditEvent
    {
        return AuditEvent::create(array_merge([
            'occurred_at' => now(),
            'actor_type' => 'user',
            'actor_id' => '7',
            'actor_name' => 'Grace',
            'action' => 'project.update',
            'result' => 'success',
        ], $attributes));
    }

    /** Only the seeded events (the API calls of the test add events of their own). */
    private function listed(string $query = ''): array
    {
        $response = $this->signedIn($this->admin)->getJson('/api/audit-events?'.$query)->assertOk();

        return collect($response->json('data'))
            ->reject(fn ($e) => str_starts_with($e['action'], 'audit.'))
            ->pluck('action')->all();
    }

    public function test_filters_and_pagination(): void
    {
        $this->seedEvent(['action' => 'project.update', 'target_type' => 'project', 'target_name' => 'orders', 'occurred_at' => now()->subDays(2)]);
        $this->seedEvent(['action' => 'project.delete', 'target_type' => 'project', 'target_name' => 'billing']);
        $this->seedEvent(['action' => 'auth.login', 'result' => 'failure', 'actor_type' => 'anonymous', 'actor_id' => null, 'actor_name' => 'mallory']);
        $this->seedEvent(['action' => 'region.update', 'target_type' => 'region', 'actor_id' => '9', 'actor_name' => 'Linus']);

        $this->assertEqualsCanonicalizing(['project.update', 'project.delete'], $this->listed('action=project.*'));
        $this->assertSame(['auth.login'], $this->listed('action=auth.login'));
        $this->assertEqualsCanonicalizing(['auth.login', 'region.update'], $this->listed('action=auth.login,region.update'));
        $this->assertSame(['auth.login'], $this->listed('result=failure'));
        $this->assertSame(['region.update'], $this->listed('actor=9'));
        $this->assertSame(['region.update'], $this->listed('actor=linus'));
        $this->assertSame(['project.delete'], $this->listed('target=bill'));
        $this->assertSame(['region.update'], $this->listed('target_type=region'));
        $this->assertSame(['project.update'], $this->listed('to='.urlencode(now()->subDay()->toIso8601String())));
        $this->assertSame(['auth.login'], $this->listed('q=mallory'));

        $page = $this->signedIn($this->admin)->getJson('/api/audit-events?per_page=2&action=project.*,auth.*,region.*')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.can_export', true)
            ->assertJsonPath('meta.scope', 'platform');
        $this->assertCount(2, $page->json('data'));
        $this->assertContains('shell.session.open', $page->json('meta.actions'));

        $this->signedIn($this->admin)->getJson('/api/audit-events?result=maybe')->assertUnprocessable();
    }

    public function test_newest_first_and_the_detail(): void
    {
        $old = $this->seedEvent(['action' => 'project.create', 'occurred_at' => now()->subHour()]);
        $new = $this->seedEvent(['action' => 'project.delete', 'metadata' => ['changes' => [['model' => 'project', 'fields' => ['name']]]]]);

        $data = collect($this->signedIn($this->admin)->getJson('/api/audit-events?action=project.*')->json('data'));
        $this->assertSame([$new->id, $old->id], $data->pluck('id')->all());

        $this->signedIn($this->admin)->getJson("/api/audit-events/{$new->id}")
            ->assertOk()
            ->assertJsonPath('data.action', 'project.delete')
            ->assertJsonPath('data.actor.name', 'Grace')
            ->assertJsonPath('data.metadata.changes.0.fields.0', 'name');
    }

    public function test_only_audit_view_reads_the_log_and_a_tenant_sees_its_own_events(): void
    {
        $this->signedIn($this->member)->getJson('/api/audit-events')->assertForbidden();
        $this->signedIn($this->member)->getJson('/api/audit-events/export')->assertForbidden();

        // Inside a tenant: that tenant's events only
        TenantAccess::setResolvers(fn () => ['id' => 't-1'], fn ($user, $tenantId) => 'owner');
        $this->seedEvent(['action' => 'project.update', 'tenant_id' => 't-1']);
        $this->seedEvent(['action' => 'project.delete', 'tenant_id' => 't-2']);
        $this->seedEvent(['action' => 'region.update', 'tenant_id' => null]);

        $response = $this->signedIn($this->admin)->getJson('/api/audit-events?action=project.*,region.*')
            ->assertOk()
            ->assertJsonPath('meta.scope', 'tenant');
        $this->assertSame(['project.update'], collect($response->json('data'))->pluck('action')->all());
        $this->assertSame(['t-1'], collect($response->json('data'))->pluck('tenant_id')->unique()->values()->all());
    }

    public function test_outside_a_tenant_the_log_narrows_to_a_tenant_or_to_platform_events(): void
    {
        $this->seedEvent(['action' => 'project.update', 'tenant_id' => 't-1']);
        $this->seedEvent(['action' => 'project.delete', 'tenant_id' => null]);

        $this->assertSame(['project.update'], $this->listed('action=project.*&tenant_id=t-1'));
        $this->assertSame(['project.delete'], $this->listed('action=project.*&tenant_id=platform'));
    }

    public function test_csv_export_neutralizes_formulas(): void
    {
        $this->seedEvent(['action' => 'project.update', 'target_type' => 'project', 'target_name' => '=HYPERLINK("http://evil")', 'metadata' => ['fields' => ['name']]]);

        $response = $this->signedIn($this->admin)->get('/api/audit-events/export?action=project.update')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=runr-audit-', (string) $response->headers->get('Content-Disposition'));

        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $header = str_getcsv($lines[0]);
        $this->assertSame('occurred_at', $header[0]);
        $this->assertContains('metadata', $header);

        $row = array_combine($header, str_getcsv($lines[1]));
        $this->assertSame('project.update', $row['action']);
        $this->assertSame("'=HYPERLINK(\"http://evil\")", $row['target_name']);
        $this->assertSame(['fields' => ['name']], json_decode($row['metadata'], true));

        $this->assertSame('audit.export', AuditEvent::where('action', 'audit.export')->value('action'));
    }

    public function test_json_export(): void
    {
        $this->seedEvent(['action' => 'project.update']);
        $this->seedEvent(['action' => 'project.delete']);

        $response = $this->signedIn($this->admin)->get('/api/audit-events/export?format=json&action=project.*')->assertOk();

        $events = json_decode($response->streamedContent(), true);
        $this->assertIsArray($events);
        $this->assertEqualsCanonicalizing(['project.update', 'project.delete'], array_column($events, 'action'));
    }

    public function test_export_takes_the_audit_export_ability(): void
    {
        Gate::define('audit.export', fn () => false);

        $this->signedIn($this->admin)->get('/api/audit-events/export')->assertForbidden();
        $this->signedIn($this->admin)->getJson('/api/audit-events')->assertOk()->assertJsonPath('meta.can_export', false);
    }

    public function test_retention_settings(): void
    {
        $this->signedIn($this->admin)->getJson('/api/audit-settings')
            ->assertOk()
            ->assertJsonPath('retention_days', 365)
            ->assertJsonPath('can_update', true)
            ->assertJsonPath('forward_enabled', false);

        $this->signedIn($this->admin)->putJson('/api/audit-settings', ['retention_days' => 90])
            ->assertOk()
            ->assertJsonPath('retention_days', 90);
        $this->assertSame(90, AuditEvent::where('action', 'audit.settings.update')->where('result', 'success')->first()->metadata['retention_days']);

        $this->signedIn($this->admin)->putJson('/api/audit-settings', ['retention_days' => 99999])->assertUnprocessable();

        $this->signedIn($this->member)->putJson('/api/audit-settings', ['retention_days' => 1])->assertForbidden();
    }
}
