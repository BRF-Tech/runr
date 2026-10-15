<?php

namespace App\Mcp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mcp\Models\OAuthClient;
use App\Mcp\Protocol\ProtocolVersion;
use App\Mcp\Server\McpServer;
use App\Mcp\Support\McpUrls;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolRegistry;
use App\Support\TenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Settings → AI agents (MCP): how to connect, the tool catalog with the permissions each tool
 * needs, and the OAuth clients connected with the user's account (revocable).
 */
class McpSettingsController extends Controller
{
    public function __construct(private readonly ToolRegistry $tools) {}

    /** GET /api/mcp */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'endpoint' => McpUrls::endpoint($request),
            'server' => ['name' => 'runr', 'title' => McpServer::title(), 'version' => (string) config('app.version', 'dev')],
            'protocol_versions' => ProtocolVersion::supported(),
            'oauth' => [
                'enabled' => (bool) config('mcp.oauth.enabled', true),
                'issuer' => McpUrls::issuer($request),
                'resource_metadata' => McpUrls::resourceMetadata($request),
                'authorization_endpoint' => McpUrls::authorizationEndpoint($request),
                'token_endpoint' => McpUrls::tokenEndpoint($request),
                'registration_endpoint' => config('mcp.oauth.open_registration', true) ? McpUrls::registrationEndpoint($request) : null,
            ],
            'token_lifetimes' => array_values((array) config('mcp.oauth.token_lifetimes')),
            'default_token_lifetime' => config('mcp.oauth.default_token_lifetime', 30),
        ]);
    }

    /** GET /api/mcp/tools — every tool, and whether the caller (with the request's token) may use it. */
    public function tools(Request $request): JsonResponse
    {
        $user = $request->user();

        $tools = array_values(array_map(fn (Tool $tool) => [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => $tool->description(),
            'group' => $tool->group(),
            'permissions' => $tool->permissions(),
            'allowed' => $this->tools->permits($tool, $user),
            'annotations' => $tool->annotations(),
            'streams' => $tool->streams(),
            'input_schema' => $tool->inputSchema(),
        ], $this->tools->all()));

        return response()->json(['data' => $tools, 'groups' => ToolRegistry::GROUPS]);
    }

    /** GET /api/mcp/clients — OAuth clients holding tokens of the caller in this organization. */
    public function clients(Request $request): JsonResponse
    {
        $tokens = $this->oauthTokens($request)->orderByDesc('created_at')->get();
        $clients = OAuthClient::query()->whereIn('id', $tokens->pluck('oauth_client_id')->unique()->all())->get()->keyBy('id');

        $data = [];
        foreach ($tokens->groupBy('oauth_client_id') as $clientId => $group) {
            $client = $clients->get($clientId);
            $data[] = [
                'client_id' => (string) $clientId,
                'name' => $client?->name ?? 'MCP client',
                'client_uri' => $client?->client_uri,
                'redirect_hosts' => $client?->redirectHosts() ?? [],
                'tokens' => $group->map(fn (PersonalAccessToken $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'abilities' => $t->abilities,
                    'resource' => $t->getAttribute('oauth_resource'),
                    'created_at' => $t->created_at?->toIso8601String(),
                    'last_used_at' => $t->last_used_at?->toIso8601String(),
                    'expires_at' => $t->expires_at?->toIso8601String(),
                ])->values()->all(),
                'connected_at' => $group->min('created_at')?->toIso8601String(),
                'last_used_at' => $group->max('last_used_at')?->toIso8601String(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** DELETE /api/mcp/clients/{client} — revokes every token of the caller the client holds here. */
    public function revoke(Request $request, string $client): JsonResponse
    {
        $revoked = $this->oauthTokens($request)->where('oauth_client_id', $client)->delete();
        abort_if($revoked === 0, 404, __('mcp.client_not_connected'));

        return response()->json(['revoked' => $revoked, 'client' => ['id' => $client, 'name' => OAuthClient::query()->find($client)?->name]]);
    }

    private function oauthTokens(Request $request)
    {
        $user = $request->user();
        $query = PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->whereNotNull('oauth_client_id');

        if (config('tenancy.enabled')) {
            $query->where('tenant_id', TenantAccess::currentId());
        }

        return $query;
    }
}
