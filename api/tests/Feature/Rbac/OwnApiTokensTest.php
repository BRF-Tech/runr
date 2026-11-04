<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Support\Auth\LoginTokens;
use App\Support\Rbac\RoutePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as Router;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * One's own API tokens (docs/permissions.md): listing and revoking them — the API Tokens page, the
 * connected MCP clients — needs no role permission (route rule '@own:tokens.manage'); creating one
 * needs tokens.manage. An API token limited to some permissions must carry tokens.manage to list
 * or revoke tokens (it still ends itself with a sign-out), and nobody touches another user's.
 */
class OwnApiTokensTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private User $member;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userWithRole('owner');
        // A custom role without tokens.manage
        $this->customRole('reader', ['projects.view']);
        $this->member = $this->userWithRole('reader', ['username' => 'reader']);
        $this->other = $this->userWithRole('developer', ['username' => 'other']);
    }

    private function as(string $plainTextToken): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($plainTextToken);
    }

    private function mcpToken(User $user, string $client): int
    {
        $token = $user->createToken('Assistant (MCP)', ['projects.view'], now()->addDays(30))->accessToken;
        $token->forceFill(['oauth_client_id' => $client, 'oauth_resource' => url('/mcp')])->save();

        return (int) $token->getKey();
    }

    public function test_the_route_rules(): void
    {
        $rules = config('route_permissions');
        $this->assertSame('@own:tokens.manage', $rules['GET tokens']);
        $this->assertSame('@own:tokens.manage', $rules['DELETE tokens/{token}']);
        $this->assertSame('@own:tokens.manage', $rules['GET mcp/clients']);
        $this->assertSame('@own:tokens.manage', $rules['DELETE mcp/clients/{client}']);
        $this->assertSame('tokens.manage', $rules['POST tokens']);
        $this->assertSame([RoutePermissions::OWN_SCOPED, ['tokens.manage']], RoutePermissions::parse('@own:tokens.manage'));

        $route = Router::getRoutes()->match(Request::create('/api/tokens', 'GET'));
        $this->assertSame('@own:tokens.manage', RoutePermissions::requirement($route));
    }

    public function test_a_role_without_tokens_manage_lists_and_revokes_its_own_tokens_and_creates_none(): void
    {
        $session = LoginTokens::issue($this->member)->plainTextToken;
        $ci = (int) $this->member->createToken('ci', ['projects.view'])->accessToken->getKey();
        $mcp = $this->mcpToken($this->member, 'assistant');
        $theirs = (int) $this->other->createToken('theirs')->accessToken->getKey();
        $theirMcp = $this->mcpToken($this->other, 'assistant');

        $listed = $this->as($session)->getJson('/api/tokens')->assertOk()->json('tokens');
        $this->assertEqualsCanonicalizing([$ci, $mcp], array_column($listed, 'id'), 'own tokens only');
        // The MCP client's token is listed, marked as one
        $mcpClient = array_column($listed, 'mcp_client', 'id');
        $this->assertTrue($mcpClient[$mcp]);
        $this->assertFalse($mcpClient[$ci]);

        $this->postJson('/api/tokens', ['name' => 'new'])
            ->assertForbidden()->assertJsonPath('permissions', ['tokens.manage']);

        // Another user's token: not the member's to touch
        $this->deleteJson("/api/tokens/{$theirs}")->assertNotFound();
        $this->assertNotNull(PersonalAccessToken::find($theirs));

        $this->deleteJson("/api/tokens/{$ci}")->assertOk()->assertJsonPath('message', __('api.token.deleted'));
        $this->assertNull(PersonalAccessToken::find($ci));

        // The connected MCP clients: listed and disconnected, the member's own only
        $clients = $this->getJson('/api/mcp/clients')->assertOk()->json('data');
        $this->assertSame([$mcp], array_merge(...array_map(fn ($c) => array_column($c['tokens'], 'id'), $clients)));
        $this->deleteJson('/api/mcp/clients/assistant')->assertOk()->assertJsonPath('revoked', 1);
        $this->assertNull(PersonalAccessToken::find($mcp));
        $this->assertNotNull(PersonalAccessToken::find($theirMcp));
    }

    public function test_a_limited_token_needs_tokens_manage_to_list_or_revoke_and_still_signs_itself_out(): void
    {
        $limited = $this->member->createToken('ci', ['projects.view']);
        $other = (int) $this->member->createToken('deploy')->accessToken->getKey();

        $this->as($limited->plainTextToken)->getJson('/api/tokens')
            ->assertForbidden()->assertJsonPath('permissions', ['tokens.manage']);
        $this->deleteJson("/api/tokens/{$other}")->assertForbidden();
        $this->getJson('/api/mcp/clients')->assertForbidden();
        $this->assertNotNull(PersonalAccessToken::find($other));

        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::find($limited->accessToken->getKey()));

        // A token that carries tokens.manage lists them (the role still need not hold it)
        $carrier = $this->member->createToken('bootstrap', ['tokens.manage', 'projects.view'])->plainTextToken;
        $this->as($carrier)->getJson('/api/tokens')->assertOk();
    }
}
