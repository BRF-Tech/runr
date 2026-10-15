<?php

namespace Tests\Feature;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Models\AuditEvent;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\Audit\AuditLogger;
use App\Services\GitService;
use App\Support\TenantAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * What the audit log records per action family (docs/security.md, "Audited events"): actor,
 * result, target, request context, non-secret metadata and model changes.
 */
class AuditTrailTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // First user of the install: Owner
        $this->user = User::factory()->create(['username' => 'jane', 'email' => 'jane@example.test']);
    }

    protected function tearDown(): void
    {
        TenantAccess::setResolvers(null, null);
        parent::tearDown();
    }

    private function event(string $action): ?AuditEvent
    {
        return AuditEvent::where('action', $action)->orderByDesc('occurred_at')->orderByDesc('id')->first();
    }

    private function eventJson(AuditEvent $event): string
    {
        return json_encode($event->toPublicArray());
    }

    public function test_a_successful_login_names_the_user_and_the_request(): void
    {
        $response = $this->withHeader('X-Request-ID', 'req-login-0001')
            ->postJson('/api/auth/login', ['login' => 'jane', 'password' => 'password'])
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req-login-0001');

        $event = $this->event('auth.login');
        $this->assertSame('success', $event->result);
        $this->assertSame('user', $event->actor_type);
        $this->assertSame((string) $this->user->id, $event->actor_id);
        $this->assertSame('jane@example.test', $event->actor_email);
        $this->assertSame('password', $event->metadata['method']);
        $this->assertSame('req-login-0001', $event->request_id);
        $this->assertSame('127.0.0.1', $event->ip_address);
        $this->assertSame('POST', $event->http_method);
        $this->assertSame('api/auth/login', $event->route);
        $this->assertStringNotContainsString($response->json('token'), $this->eventJson($event));
    }

    public function test_a_failed_login_records_the_login_name_never_the_password(): void
    {
        $this->postJson('/api/auth/login', ['login' => 'jane', 'password' => 'Wrong-Pa55word!'])->assertUnauthorized();

        $event = $this->event('auth.login');
        $this->assertSame('failure', $event->result);
        $this->assertSame('anonymous', $event->actor_type);
        $this->assertSame('jane', $event->actor_name);
        $this->assertSame('invalid_credentials', $event->metadata['reason']);
        $this->assertSame(401, $event->status_code);
        $this->assertStringNotContainsString('Wrong-Pa55word!', $this->eventJson($event));
    }

    public function test_logout_and_api_tokens(): void
    {
        $login = $this->user->createToken('api')->plainTextToken;

        $created = $this->withToken($login)->postJson('/api/tokens', ['name' => 'ci-token'])->assertCreated();
        $event = $this->event('api_token.create');
        $this->assertSame('success', $event->result);
        $this->assertSame('api_token', $event->target_type);
        $this->assertSame((string) $created->json('token.id'), $event->target_id);
        $this->assertSame('ci-token', $event->target_name);
        $this->assertSame('session', $event->auth_method);
        $this->assertStringNotContainsString($created->json('plain_token'), $this->eventJson($event));

        // Requests made with the API token are attributed to it
        $this->app['auth']->forgetGuards();
        $this->withToken($created->json('plain_token'))->deleteJson('/api/tokens/'.$created->json('token.id'));
        $revoke = $this->event('api_token.revoke');
        $this->assertNotNull($revoke);
        $this->assertSame('api_token', $revoke->actor_type);
        $this->assertSame('api_token:'.$created->json('token.id'), $revoke->auth_method);

        $this->app['auth']->forgetGuards();
        $this->withToken($login)->postJson('/api/auth/logout')->assertOk();
        $this->assertSame((string) $this->user->id, $this->event('auth.logout')->actor_id);
    }

    public function test_a_project_update_records_changed_fields_and_env_var_names_only(): void
    {
        $this->mock(GitService::class, fn ($git) => $git->shouldReceive('writeFiles')->andReturn('sha'));

        $project = Project::create(['name' => 'orders', 'service_type' => 'faas', 'description' => 'old']);
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'env_vars' => ['KEEP' => 'k', 'DROP' => 'd']]);

        $this->actingAs($this->user, 'sanctum')->putJson("/api/projects/{$project->id}", [
            'description' => 'new',
            'config' => ['env_vars' => ['KEEP' => 'changed-value-xyz', 'ADD' => 'added-value-xyz']],
        ])->assertOk();

        $event = $this->event('project.update');
        $this->assertSame('project', $event->target_type);
        $this->assertSame($project->id, $event->target_id);
        $this->assertSame('orders', $event->target_name);

        $changes = collect($event->metadata['changes']);
        $projectChange = $changes->firstWhere('model', 'project');
        $this->assertContains('description', $projectChange['fields']);
        $this->assertSame('old', $projectChange['before']['description']);
        $this->assertSame('new', $projectChange['after']['description']);

        $configChange = $changes->firstWhere('model', 'project_config');
        $this->assertSame(['ADD'], $configChange['env_vars']['added']);
        $this->assertSame(['DROP'], $configChange['env_vars']['removed']);
        $this->assertSame(['KEEP'], $configChange['env_vars']['changed']);
        $this->assertArrayNotHasKey('env_vars', $configChange['after'] ?? []);

        $json = $this->eventJson($event);
        foreach (['changed-value-xyz', 'added-value-xyz'] as $value) {
            $this->assertStringNotContainsString($value, $json);
        }
    }

    public function test_secret_env_var_changes_record_the_name_only(): void
    {
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/projects/{$project->id}/secrets/DB_PASSWORD", ['value' => 'secret-value-xyz'])
            ->assertCreated();

        $event = $this->event('project.secret.set');
        $this->assertSame('DB_PASSWORD', $event->metadata['name']);
        $this->assertStringNotContainsString('secret-value-xyz', $this->eventJson($event));
    }

    public function test_deploying_an_older_version_is_a_rollback(): void
    {
        Event::fake([DeploymentCreated::class, DeploymentStatusChanged::class]);

        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);
        $v1 = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $v2 = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'ready']);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v2->id])->assertAccepted();
        $this->assertSame($v2->id, $this->event('deployment.create')->metadata['version_id']);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $v1->id])->assertAccepted();
        $rollback = $this->event('deployment.rollback');
        $this->assertNotNull($rollback);
        $this->assertSame($project->id, $rollback->target_id);
        $this->assertNotEmpty($rollback->metadata['deployment_id']);
    }

    public function test_settings_changes_carry_before_and_after(): void
    {
        $this->actingAs($this->user, 'sanctum')->patchJson('/api/settings', ['registration_enabled' => false])->assertOk();

        $event = $this->event('settings.update');
        $setting = collect($event->metadata['changes'] ?? [])->firstWhere('name', 'registration_enabled');
        $this->assertSame(['registration_enabled'], $event->metadata['fields']);
        $this->assertNotNull($setting);
    }

    public function test_downloading_a_kubeconfig_is_recorded(): void
    {
        $region = KnativeRegion::create(['name' => 'op', 'kubeconfig' => "apiVersion: v1\nkind: Config\n"]);

        $this->actingAs($this->user, 'sanctum')->get("/api/knative-regions/{$region->id}/kubeconfig")->assertOk();

        $event = $this->event('region.kubeconfig.download');
        $this->assertSame('region', $event->target_type);
        $this->assertSame($region->id, $event->target_id);
        $this->assertSame('op', $event->target_name);
    }

    public function test_a_403_on_any_other_route_is_an_authorization_denial(): void
    {
        $member = $this->userWithRole('viewer');

        $this->signedIn($member)->getJson('/api/audit-events')->assertForbidden();

        $event = $this->event('authorization.denied');
        $this->assertSame('denied', $event->result);
        $this->assertSame((string) $member->id, $event->actor_id);
        $this->assertSame('api/audit-events', $event->route);
        $this->assertSame(403, $event->status_code);
    }

    public function test_unauthenticated_requests_to_audited_routes_are_not_recorded(): void
    {
        $this->patchJson('/api/settings', ['registration_enabled' => false])->assertUnauthorized();

        $this->assertNull($this->event('settings.update'));
    }

    public function test_a_platform_admin_inside_a_foreign_tenant_is_recorded_as_impersonator(): void
    {
        $admin = $this->platformAdmin();

        // Current tenant t-acme; the admin is a member of t-member only
        TenantAccess::setResolvers(
            fn () => ['id' => 't-acme', 'name' => 'Acme', 'slug' => 'acme'],
            fn ($user, string $tenantId) => $tenantId === 't-member' ? 'owner' : null,
        );

        $event = app(AuditLogger::class)->record('project.update', ['actor' => $admin]);
        $this->assertSame('t-acme', $event->tenant_id);
        $this->assertSame((string) $admin->id, $event->impersonator_id);
        $this->assertSame((string) $admin->id, $event->actor_id);

        // A member of the tenant acts as themselves
        TenantAccess::setResolvers(fn () => ['id' => 't-member'], fn () => 'owner');
        $this->assertNull(app(AuditLogger::class)->record('project.update', ['actor' => $admin])->impersonator_id);
    }

    public function test_system_events_have_no_request_context(): void
    {
        $event = app(AuditLogger::class)->record('audit.retention.pruned', ['actor' => 'system', 'metadata' => ['deleted' => 3]]);

        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->ip_address);
        $this->assertNull($event->route);
        $this->assertSame(3, $event->metadata['deleted']);
    }

    public function test_metadata_is_redacted(): void
    {
        $event = app(AuditLogger::class)->record('test.action', ['actor' => 'system', 'metadata' => [
            'password' => 'p', 'link_token' => 'lt', 'env_vars' => ['A' => 'v'], 'name' => 'visible',
        ]]);

        $this->assertNotSame('p', $event->metadata['password']);
        $this->assertNotSame('lt', $event->metadata['link_token']);
        $this->assertNotSame('v', $event->metadata['env_vars']['A']);
        $this->assertSame('visible', $event->metadata['name']);
    }
}
