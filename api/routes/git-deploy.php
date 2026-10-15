<?php

use App\Http\Controllers\Api\GitCallbackController;
use App\Http\Controllers\Api\GitPreviewController;
use App\Http\Controllers\Api\GitProjectController;
use App\Http\Controllers\Api\GitProviderController;
use App\Http\Controllers\Api\GitSmartHttpController;
use App\Http\Controllers\Api\GitWebhookController;
use App\Http\Middleware\NoStoreResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Git-native deployment (docs/git-deploy.md) — loaded by GitDeployServiceProvider
|--------------------------------------------------------------------------
*/

// ── Console API (API token / session; the api group identifies the tenant) ──
// no-store: live state polled by the console, env override values (ResponseCache never keeps them)
Route::middleware(['api', 'auth:sanctum', NoStoreResponse::class])->prefix('api')->group(function () {
    Route::get('/projects/{project}/git', [GitProjectController::class, 'show']);
    Route::put('/projects/{project}/git', [GitProjectController::class, 'update']);
    Route::get('/projects/{project}/git/deploys', [GitProjectController::class, 'deploys']);
    Route::get('/projects/{project}/git/versions', [GitProjectController::class, 'versions']);
    Route::post('/projects/{project}/git/redeploy', [GitProjectController::class, 'redeploy']);
    Route::post('/projects/{project}/git/repos', [GitProjectController::class, 'repositories']);
    Route::post('/projects/{project}/git/connect', [GitProjectController::class, 'connect']);
    Route::delete('/projects/{project}/git/connect', [GitProjectController::class, 'disconnect']);
    Route::get('/projects/{project}/git/installations', [GitProjectController::class, 'installations']);
    Route::post('/projects/{project}/git/github/install', [GitProjectController::class, 'githubInstall']);
    Route::post('/projects/{project}/git/gitlab/authorize', [GitProjectController::class, 'gitlabAuthorize']);

    Route::get('/projects/{project}/previews', [GitPreviewController::class, 'index']);
    Route::post('/projects/{project}/previews/{preview}/redeploy', [GitPreviewController::class, 'redeploy']);
    Route::delete('/projects/{project}/previews/{preview}', [GitPreviewController::class, 'destroy']);

    // Settings → Git providers (git_providers.manage)
    Route::get('/git-providers', [GitProviderController::class, 'index']);
    Route::post('/git-providers', [GitProviderController::class, 'store']);
    Route::put('/git-providers/{provider}', [GitProviderController::class, 'update']);
    Route::delete('/git-providers/{provider}', [GitProviderController::class, 'destroy']);
});

// ── Provider webhooks + browser callbacks (signature / signed state, no session) ──
Route::prefix('api/git')->middleware('throttle:git-webhooks')->group(function () {
    Route::post('/webhooks/github/{provider}', [GitWebhookController::class, 'github']);
    Route::post('/webhooks/gitlab/{link}', [GitWebhookController::class, 'gitlab']);
    Route::post('/webhooks/forgejo/{link}', [GitWebhookController::class, 'forgejo']);
    Route::get('/github/setup', [GitCallbackController::class, 'githubSetup']);
    Route::get('/oauth/gitlab/callback', [GitCallbackController::class, 'gitlabCallback']);
});

// ── Smart HTTP git remote: /git/<organization>/<project>.git (HTTP Basic, API token) ──
Route::prefix('git')->group(function () {
    $segment = '[A-Za-z0-9][A-Za-z0-9._-]*';

    Route::get('/{owner}/{repo}/info/refs', [GitSmartHttpController::class, 'infoRefs'])
        ->where(['owner' => $segment, 'repo' => $segment]);
    Route::post('/{owner}/{repo}/git-upload-pack', [GitSmartHttpController::class, 'uploadPack'])
        ->where(['owner' => $segment, 'repo' => $segment]);
    Route::post('/{owner}/{repo}/git-receive-pack', [GitSmartHttpController::class, 'receivePack'])
        ->where(['owner' => $segment, 'repo' => $segment]);
});
