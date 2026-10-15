<?php

use App\Mcp\Http\Controllers\HostedServerController;
use App\Mcp\Http\Controllers\McpSettingsController;
use App\Mcp\Http\Controllers\OAuthConsentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI agents in the console API (docs/mcp.md) — loaded by McpServiceProvider under /api with the
| `api` group and auth:sanctum. Rules: config/route_permissions.php; audit: AuditCatalog.
|--------------------------------------------------------------------------
*/

// Settings → AI agents (MCP)
Route::get('/mcp', [McpSettingsController::class, 'show']);
Route::get('/mcp/tools', [McpSettingsController::class, 'tools']);
Route::get('/mcp/clients', [McpSettingsController::class, 'clients']);
Route::delete('/mcp/clients/{client}', [McpSettingsController::class, 'revoke'])->where('client', '[A-Za-z0-9_]{1,64}');

// The consent page of the OAuth server
Route::get('/oauth2/requests/{id}', [OAuthConsentController::class, 'show']);
Route::post('/oauth2/requests/{id}/approve', [OAuthConsentController::class, 'approve']);
Route::post('/oauth2/requests/{id}/deny', [OAuthConsentController::class, 'deny']);

// Hosted MCP servers (static paths before the project routes)
Route::get('/mcp-servers/templates', [HostedServerController::class, 'templates']);
Route::get('/mcp-servers', [HostedServerController::class, 'index']);
Route::get('/projects/{project}/mcp-server', [HostedServerController::class, 'show']);
Route::put('/projects/{project}/mcp-server', [HostedServerController::class, 'upsert']);
Route::delete('/projects/{project}/mcp-server', [HostedServerController::class, 'destroy']);
Route::post('/projects/{project}/mcp-server/rotate-key', [HostedServerController::class, 'rotateKey']);
Route::get('/projects/{project}/mcp-server/calls', [HostedServerController::class, 'calls']);
Route::post('/projects/{project}/mcp-server/inspector', [HostedServerController::class, 'inspector'])->middleware('throttle:120,1');
