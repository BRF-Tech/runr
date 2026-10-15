<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * API tokens carry '*' or a subset of their owner's permissions and never exceed what the owner
 * may do when the token is used.
 */
class TokenScopeTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userWithRole('owner');
    }

    private function bearer(string $plain): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }

    public function test_a_scoped_token_only_does_what_it_carries(): void
    {
        $dev = $this->userWithRole('developer');
        $project = $this->caasProject();
        $plain = $dev->createToken('ci', ['projects.view'])->plainTextToken;

        $this->bearer($plain)->getJson('/api/projects')->assertOk();
        $this->bearer($plain)->postJson("/api/projects/{$project->id}/deploy", [])->assertForbidden();
        $this->bearer($plain)->getJson('/api/tokens')->assertForbidden(); // tokens.manage not carried
    }

    public function test_a_token_never_exceeds_its_owners_current_permissions(): void
    {
        $dev = $this->userWithRole('developer');
        $project = $this->caasProject();
        $plain = $dev->createToken('deployer', ['*'])->plainTextToken;

        $this->assertAllowed($this->bearer($plain)->postJson("/api/projects/{$project->id}/deploy", []));

        (new SingleTenantOrganization)->setMemberRole($dev->id, 'viewer');
        $this->bearer($plain)->postJson("/api/projects/{$project->id}/deploy", [])->assertForbidden();
    }

    public function test_tokens_are_created_with_the_creators_permissions_only(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->signedIn($viewer)->postJson('/api/tokens', ['name' => 'too-much', 'abilities' => ['projects.deploy']])
            ->assertUnprocessable()->assertJsonValidationErrors('abilities');
        $this->signedIn($viewer)->postJson('/api/tokens', ['name' => 'unknown', 'abilities' => ['projects.fly']])
            ->assertUnprocessable();

        $created = $this->signedIn($viewer)->postJson('/api/tokens', ['name' => 'read', 'abilities' => ['logs.view']])
            ->assertCreated();
        // Implied permissions come along
        $this->assertSame(['projects.view', 'logs.view'], $created->json('token.abilities'));
    }

    public function test_project_access_permissions_can_be_put_on_a_token(): void
    {
        $viewer = $this->userWithRole('viewer');
        $project = $this->caasProject();
        $this->grantProject($viewer, $project, 'developer');

        $abilities = collect($this->signedIn($viewer)->getJson('/api/tokens')->assertOk()->json('available_abilities'))->pluck('key');
        $this->assertContains('projects.deploy', $abilities);
        $this->assertNotContains('regions.manage', $abilities);

        $plain = $viewer->createToken('deploy-checkout', ['projects.deploy'])->plainTextToken;
        $this->assertAllowed($this->bearer($plain)->postJson("/api/projects/{$project->id}/deploy", []));
    }

    public function test_legacy_scope_names_are_translated_on_creation(): void
    {
        $dev = $this->userWithRole('developer');

        $abilities = $this->signedIn($dev)->postJson('/api/tokens', ['name' => 'old-cli', 'abilities' => ['deployments:write']])
            ->assertCreated()->json('token.abilities');

        $this->assertSame(['projects.view', 'projects.deploy'], $abilities);
    }

    public function test_a_wildcard_token_made_with_a_scoped_token_keeps_that_scope(): void
    {
        $dev = $this->userWithRole('developer');
        $plain = $dev->createToken('bootstrap', ['tokens.manage', 'projects.view'])->plainTextToken;

        $abilities = $this->bearer($plain)->postJson('/api/tokens', ['name' => 'child'])->assertCreated()->json('token.abilities');

        $this->assertSame(['projects.view', 'tokens.manage'], $abilities);
    }

    public function test_a_platform_admin_with_a_scoped_token_is_limited_by_it(): void
    {
        $admin = $this->platformAdmin();
        $plain = $admin->createToken('readonly', ['regions.view'])->plainTextToken;

        $this->bearer($plain)->getJson('/api/knative-regions')->assertOk();
        $this->bearer($plain)->postJson('/api/knative-regions', [])->assertForbidden();

        $full = $admin->createToken('full', ['*'])->plainTextToken;
        $this->assertAllowed($this->bearer($full)->postJson('/api/knative-regions', []));
    }

    public function test_expired_tokens_are_refused(): void
    {
        $dev = $this->userWithRole('developer');
        $token = $dev->createToken('old', ['*'], now()->subMinute());

        $this->bearer($token->plainTextToken)->getJson('/api/projects')->assertUnauthorized();
    }

    public function test_the_token_list_offers_the_users_permissions(): void
    {
        $viewer = $this->userWithRole('viewer');
        $abilities = collect($this->signedIn($viewer)->getJson('/api/tokens')->assertOk()->json('available_abilities'));

        $this->assertSame(
            // the Viewer role in catalog order (config/permissions.php)
            ['projects.view', 'git.pull', 'logs.view', 'metrics.view', 'traces.view', 'workflows.view', 'sandboxes.view', 'security.view', 'regions.view', 'images.view', 'models.view', 'bindings.view', 'mcp.servers.call', 'members.view', 'tokens.manage', 'tickets.view', 'tickets.create'],
            $abilities->pluck('key')->all(),
        );
        $this->assertSame('projects', $abilities->firstWhere('key', 'projects.view')['group']);
    }

    public function test_permissions_endpoint_reports_the_token_limit(): void
    {
        $dev = $this->userWithRole('developer');
        $plain = $dev->createToken('ci', ['logs.view'])->plainTextToken;

        $data = $this->bearer($plain)->getJson('/api/auth/permissions')->assertOk()->json();

        $this->assertTrue($data['token_scoped']);
        $this->assertSame(['projects.view', 'logs.view'], $data['permissions']);
        $this->assertInstanceOf(User::class, $dev);
    }
}
