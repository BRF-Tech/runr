<?php

namespace App\Mcp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mcp\Models\OAuthRequest;
use App\Mcp\OAuth\AuthorizationServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The consent page's API (console, signed in): an authorization request of an MCP client, and the
 * user's answer. Approving creates an API token, so it needs tokens.manage
 * (config/route_permissions.php); the permissions picked are limited like any API token's
 * (App\Support\Rbac\TokenAbilities) and the token works in the organization the request is
 * approved in (X-Tenant-ID of the console).
 */
class OAuthConsentController extends Controller
{
    public function __construct(private readonly AuthorizationServer $server) {}

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->server->describe($this->find($id), $request, $request->user())])
            ->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'abilities' => 'required|array|min:1',
            'abilities.*' => 'string|max:64',
            'expires_in_days' => 'present|nullable|integer|min:1|max:3650',
        ]);

        $lifetime = $data['expires_in_days'] === null ? null : (int) $data['expires_in_days'];
        $pending = $this->find($id);

        return response()->json([
            'redirect_url' => $this->server->approve($pending, $request, $request->user(), array_values($data['abilities']), $lifetime),
            'client' => ['id' => $pending->client_id, 'name' => $pending->client?->name],
        ])->header('Cache-Control', 'no-store');
    }

    public function deny(Request $request, string $id): JsonResponse
    {
        $pending = $this->find($id);

        return response()->json([
            'redirect_url' => $this->server->deny($pending, $request, $request->user()),
            'client' => ['id' => $pending->client_id, 'name' => $pending->client?->name],
        ])->header('Cache-Control', 'no-store');
    }

    private function find(string $id): OAuthRequest
    {
        $pending = preg_match('/^[0-9a-zA-Z]{26}$/', $id) ? OAuthRequest::query()->with('client')->find(strtolower($id)) : null;
        abort_unless($pending !== null, 404, __('mcp.request_not_found'));

        return $pending;
    }
}
