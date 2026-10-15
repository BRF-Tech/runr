<?php

use App\Mcp\Http\Controllers\McpEndpointController;
use App\Mcp\Http\Controllers\McpGatewayController;
use App\Mcp\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI agents on the console origin (docs/mcp.md) — loaded by McpServiceProvider without a prefix
|--------------------------------------------------------------------------
| The gateway routes /mcp, /mcp/*, /oauth2/* and /.well-known/oauth-* to the API. Origin check
| and CORS: App\Mcp\Http\Middleware\McpCors (global).
*/

// The RunR MCP server: Streamable HTTP. The `api` group gives tenant identification, the `api`
// rate limiter, account checks and route authorization (config/route_permissions.php)
Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::post('/mcp', [McpEndpointController::class, 'post']);
    Route::get('/mcp', [McpEndpointController::class, 'get']);
    Route::delete('/mcp', [McpEndpointController::class, 'delete']);
});

// OAuth 2.1 authorization server metadata (RFC 8414) and protected resource metadata (RFC 9728)
Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServerMetadata']);
Route::get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResourceMetadata']);
Route::get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResourceMetadata']);
Route::get('/.well-known/oauth-protected-resource/mcp/servers/{handle}', [OAuthController::class, 'hostedResourceMetadata'])
    ->where('handle', '[A-Za-z0-9][A-Za-z0-9-]{0,79}');

// OAuth endpoints (per-IP limiter `mcp-oauth`); the consent is the console's (routes/mcp-api.php)
Route::middleware('throttle:mcp-oauth')->group(function () {
    Route::post('/oauth2/register', [OAuthController::class, 'register']);
    Route::get('/oauth2/authorize', [OAuthController::class, 'authorization']);
    Route::post('/oauth2/token', [OAuthController::class, 'token']);
    Route::post('/oauth2/revoke', [OAuthController::class, 'revoke']);
});

// Hosted MCP servers behind the gateway (authentication per access mode in the controller)
Route::match(['GET', 'POST', 'DELETE'], '/mcp/servers/{handle}', [McpGatewayController::class, 'handle'])
    ->where('handle', '[A-Za-z0-9][A-Za-z0-9-]{0,79}');
