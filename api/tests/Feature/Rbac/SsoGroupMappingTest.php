<?php

namespace Tests\Feature\Rbac;

use App\Models\Group;
use App\Models\User;
use App\Support\Rbac\SingleTenantOrganization;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\User as SocialiteUser;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SsoGroupMapping;
use Modules\AuthProvider\Providers\AuthProviderServiceProvider;
use Modules\AuthProvider\Services\OAuthService;
use Modules\AuthProvider\Services\Saml\SamlService;
use Modules\AuthProvider\Services\Saml\SamlUser;
use Modules\AuthProvider\Services\SsoGroupSync;
use Tests\Concerns\ActsWithRoles;
use Tests\Support\SamlTestIdp;
use Tests\TestCase;

/**
 * SSO group mapping: IdP groups (OIDC claim / SAML attribute) → groups and the member's role on
 * every sign-in, just-in-time users with the provider's role, the mapping API, and the SAML ACS
 * end to end.
 */
class SsoGroupMappingTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private const FRONTEND = 'https://console.example.test';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://runr.example.test',
            'app.frontend_url' => self::FRONTEND,
            'authprovider.enabled' => true,
        ]);
        // The suite runs with AUTH_PROVIDER_ENABLED off: boot the module like SamlSsoTest does
        $this->app->register(AuthProviderServiceProvider::class, true);
        Artisan::call('migrate', ['--path' => 'Modules/AuthProvider/Database/Migrations', '--force' => true]);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
        Log::spy();

        $this->owner = $this->userWithRole('owner', ['username' => 'admin']);
    }

    private function provider(array $attributes = []): AuthProvider
    {
        return AuthProvider::query()->create(array_merge([
            'name' => 'Okta', 'driver' => 'oidc', 'client_id' => 'id', 'client_secret' => 'secret',
            'oidc_discovery_url' => 'https://idp.example.test/.well-known/openid-configuration',
            'is_enabled' => true, 'auto_register' => true,
        ], $attributes));
    }

    private function oidcUser(array $raw): SocialiteUser
    {
        return (new SocialiteUser)->setRaw($raw)->map(['id' => 'sub-1', 'email' => 'jane@example.test', 'name' => 'Jane']);
    }

    private function map(string $external, ?Group $group = null, ?string $role = null, ?AuthProvider $provider = null): SsoGroupMapping
    {
        return SsoGroupMapping::query()->create([
            'external_group' => $external,
            'group_id' => $group?->id,
            'role' => $role,
            'auth_provider_id' => $provider?->id,
        ]);
    }

    private function sync(AuthProvider $provider, User $user, array $groups, bool $isNew = false, string $claim = 'groups'): void
    {
        app(SsoGroupSync::class)->sync($provider, $user, $this->oidcUser([$claim => $groups]), $isNew);
        $this->flushRbac();
    }

    private function role(User $user): ?string
    {
        return (new SingleTenantOrganization)->memberRole($user->id);
    }

    // ── Reading groups ──────────────────────────────────────────────────────

    public function test_groups_come_from_the_configured_claim_or_the_usual_names(): void
    {
        $sync = app(SsoGroupSync::class);

        $this->assertSame(['eng', 'ops'], $sync->externalGroups($this->provider(), $this->oidcUser(['groups' => ['eng', 'ops']])));
        $this->assertSame(['eng'], $sync->externalGroups($this->provider(), $this->oidcUser(['memberOf' => 'eng'])));
        $this->assertSame(
            ['team-a'],
            $sync->externalGroups($this->provider(['groups_attribute' => 'teams']), $this->oidcUser(['teams' => ['team-a'], 'groups' => ['ignored']])),
        );

        $saml = $this->provider(['driver' => 'saml', 'name' => 'ADFS']);
        $user = (new SamlUser)->setRaw([
            'attributes' => ['http://schemas.microsoft.com/ws/2008/06/identity/claims/groups' => ['g1', 'g2']],
            'friendly_attributes' => [],
        ]);
        $this->assertSame(['g1', 'g2'], $sync->externalGroups($saml, $user));
    }

    // ── Groups ──────────────────────────────────────────────────────────────

    public function test_mapped_groups_follow_the_idp_and_manual_memberships_stay(): void
    {
        $provider = $this->provider();
        $user = $this->userWithRole('viewer');
        $eng = $this->groupWithRole('engineering', 'developer');
        $ops = $this->groupWithRole('ops', null, [$user]); // manual
        $this->map('Engineering', $eng);
        $this->map('ops-team', $ops);

        $this->sync($provider, $user, ['engineering']); // case-insensitive
        $this->assertSame('sso', DB::table('rbac_group_user')->where('group_id', $eng->id)->where('user_id', $user->id)->value('source'));
        $this->assertTrue($this->can($user, 'projects.deploy'), 'through the group role');

        $this->sync($provider, $user, []);
        $this->assertFalse(DB::table('rbac_group_user')->where('group_id', $eng->id)->where('user_id', $user->id)->exists());
        $this->assertTrue(DB::table('rbac_group_user')->where('group_id', $ops->id)->where('user_id', $user->id)->exists(), 'manual membership');
    }

    public function test_mappings_of_another_provider_do_not_apply(): void
    {
        $okta = $this->provider();
        $other = $this->provider(['name' => 'Other']);
        $user = $this->userWithRole('viewer');
        $eng = $this->groupWithRole('engineering', null);
        $this->map('engineering', $eng, null, $other);

        $this->sync($okta, $user, ['engineering']);

        $this->assertFalse(DB::table('rbac_group_user')->where('group_id', $eng->id)->exists());
    }

    // ── Roles ───────────────────────────────────────────────────────────────

    public function test_a_role_mapping_lets_the_idp_manage_the_role(): void
    {
        $provider = $this->provider();
        $user = $this->userWithRole('viewer');
        $this->map('runr-admins', null, 'admin');

        $this->sync($provider, $user, ['runr-admins']);
        $this->assertSame('admin', $this->role($user));

        // Left the IdP group: back to the default role
        $this->sync($provider, $user, ['someone-else']);
        $this->assertSame('developer', $this->role($user));
    }

    public function test_the_matching_role_with_the_most_permissions_wins(): void
    {
        $provider = $this->provider();
        $user = $this->userWithRole('viewer');
        $this->map('readers', null, 'viewer');
        $this->map('builders', null, 'developer');

        $this->sync($provider, $user, ['readers', 'builders']);

        $this->assertSame('developer', $this->role($user));
    }

    public function test_owners_keep_their_role(): void
    {
        $provider = $this->provider();
        $this->map('readers', null, 'viewer');

        $this->sync($provider, $this->owner, ['readers']);

        $this->assertSame('owner', $this->role($this->owner));
    }

    public function test_without_role_mappings_the_role_is_left_alone(): void
    {
        $provider = $this->provider(['default_role' => 'viewer']);
        $user = $this->userWithRole('admin');

        $this->sync($provider, $user, ['anything']);

        $this->assertSame('admin', $this->role($user));
    }

    // ── Just-in-time provisioning ───────────────────────────────────────────

    public function test_new_users_get_the_providers_role(): void
    {
        $provider = $this->provider(['default_role' => 'viewer']);

        $result = app(OAuthService::class)
            ->resolveExternalUser($provider, 'oidc', $this->oidcUser(['groups' => []]));

        $user = User::where('email', 'jane@example.test')->firstOrFail();
        $this->assertSame((string) $user->id, (string) $result['user']->id);
        $this->assertSame('viewer', $this->role($user));
    }

    public function test_new_users_get_the_organizations_default_role_otherwise(): void
    {
        $provider = $this->provider();

        app(OAuthService::class)
            ->resolveExternalUser($provider, 'oidc', $this->oidcUser(['groups' => []]));

        $this->assertSame('developer', $this->role(User::where('email', 'jane@example.test')->firstOrFail()));
    }

    // ── Mapping API ─────────────────────────────────────────────────────────

    public function test_sso_managers_manage_mappings(): void
    {
        $provider = $this->provider();
        $group = $this->groupWithRole('engineering', null);

        $id = $this->signedIn($this->owner)->postJson('/api/sso/group-mappings', [
            'external_group' => 'eng', 'group_id' => $group->id, 'role' => 'developer', 'auth_provider_id' => $provider->id,
        ])->assertCreated()->assertJsonPath('mapping.group.name', 'engineering')->json('mapping.id');

        $this->signedIn($this->owner)->getJson('/api/sso/group-mappings')->assertOk()->assertJsonPath('mappings.0.role', 'developer');
        $this->signedIn($this->owner)->putJson("/api/sso/group-mappings/{$id}", ['external_group' => 'engineering', 'role' => 'viewer'])
            ->assertOk()->assertJsonPath('mapping.role', 'viewer');
        $this->signedIn($this->owner)->deleteJson("/api/sso/group-mappings/{$id}")->assertOk();
        $this->assertSame(0, SsoGroupMapping::count());
    }

    public function test_mappings_are_validated_and_guarded(): void
    {
        $this->customRole('sso-admin', ['sso.manage']);
        $ssoAdmin = $this->userWithRole('sso-admin');
        $dev = $this->userWithRole('developer');

        $this->signedIn($dev)->getJson('/api/sso/group-mappings')->assertForbidden();
        $this->signedIn($this->owner)->postJson('/api/sso/group-mappings', ['external_group' => 'x'])->assertUnprocessable();
        $this->signedIn($this->owner)->postJson('/api/sso/group-mappings', ['external_group' => 'x', 'role' => 'owner'])->assertUnprocessable();
        // Signing in with the IdP group would hand the role out: only roles the editor could grant
        $this->signedIn($ssoAdmin)->postJson('/api/sso/group-mappings', ['external_group' => 'x', 'role' => 'admin'])->assertForbidden();
    }

    public function test_a_providers_role_for_new_users_is_validated(): void
    {
        $provider = $this->provider();

        $this->signedIn($this->owner)->putJson("/api/auth-providers/{$provider->id}", ['default_role' => 'owner'])->assertUnprocessable();
        $this->signedIn($this->owner)->putJson("/api/auth-providers/{$provider->id}", ['default_role' => 'viewer', 'groups_attribute' => 'roles'])
            ->assertOk()->assertJsonPath('default_role', 'viewer')->assertJsonPath('groups_attribute', 'roles');
    }

    // ── SAML end to end ─────────────────────────────────────────────────────

    public function test_a_saml_sign_in_applies_the_group_mapping(): void
    {
        $idp = new SamlTestIdp;
        $provider = AuthProvider::findOrFail($this->signedIn($this->owner)->postJson('/api/auth-providers', [
            'name' => 'Corporate SSO', 'driver' => 'saml', 'auto_register' => true,
            'saml_idp_entity_id' => SamlTestIdp::ENTITY_ID, 'saml_idp_sso_url' => SamlTestIdp::SSO_URL,
            'saml_idp_x509_cert' => $idp->certificate, 'groups_attribute' => 'memberOf',
        ])->assertCreated()->json('id'));
        $this->app['auth']->forgetGuards();
        $admins = $this->groupWithRole('platform-team', null);
        $this->map('cn=runr-admins', $admins, 'admin');

        $url = $this->getJson("/api/saml/{$provider->id}/login")->assertOk()->json('redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $doc = new DOMDocument;
        $doc->loadXML(gzinflate(base64_decode($query['SAMLRequest'])));
        $sp = app(SamlService::class)->spUrls($provider);

        $this->post("/api/saml/{$provider->id}/acs", ['SAMLResponse' => $idp->response([
            'in_response_to' => $doc->documentElement->getAttribute('ID'),
            'destination' => $sp['acs_url'],
            'audience' => $sp['entity_id'],
            'name_id' => 'u-42',
            'attributes' => ['mail' => 'ada@example.test', 'displayName' => 'Ada', 'memberOf' => ['cn=users', 'cn=runr-admins']],
        ])])->assertRedirect();

        $ada = User::where('email', 'ada@example.test')->firstOrFail();
        $this->assertSame('admin', $this->role($ada));
        $this->assertSame('sso', DB::table('rbac_group_user')->where('group_id', $admins->id)->where('user_id', $ada->id)->value('source'));
    }
}
