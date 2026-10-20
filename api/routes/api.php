<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\AuditEventController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CodeController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\DeploymentController;
use App\Http\Controllers\Api\ExecController;
use App\Http\Controllers\Api\GatewayController;
use App\Http\Controllers\Api\HistoricalMetricsController;
use App\Http\Controllers\Api\HorizonLinkController;
use App\Http\Controllers\Api\KnativeFunctionTlsController;
use App\Http\Controllers\Api\KnativeInstallController;
use App\Http\Controllers\Api\KnativeNodeController;
use App\Http\Controllers\Api\KnativeRedisController;
use App\Http\Controllers\Api\KnativeRegionController;
use App\Http\Controllers\Api\KnativeUninstallController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\PrivateDatabaseBindingController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectDomainController;
use App\Http\Controllers\Api\ProjectOwnershipController;
use App\Http\Controllers\Api\ProjectSecretController;
use App\Http\Controllers\Api\ProjectStopController;
use App\Http\Controllers\Api\RegionCapacityController;
use App\Http\Controllers\Api\RegionDemoController;
use App\Http\Controllers\Api\RegionMigrateController;
use App\Http\Controllers\Api\RegistryController;
use App\Http\Controllers\Api\RegistrySetupController;
use App\Http\Controllers\Api\RuntimeController;
use App\Http\Controllers\Api\SandboxController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WasmController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/version', function () {
    $v = config('app.version');
    // Tag versions already start with 'v', commit SHAs get 'main-' prefix
    if ($v !== 'dev' && ! str_starts_with($v, 'v')) {
        $v = 'main-'.$v;
    }

    return response()->json(['version' => $v]);
});

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::get('/auth/registration-status', [AuthController::class, 'registrationStatus']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Roles, groups, project access, effective permissions (docs/permissions.md)
    require __DIR__.'/rbac.php';

    Route::apiResource('projects', ProjectController::class);

    // The payer of the project's bills, stop / resume with a reason (docs/billing.md)
    Route::put('/projects/{project}/owner', [ProjectOwnershipController::class, 'update']);
    Route::post('/projects/{project}/stop', [ProjectStopController::class, 'stop']);
    Route::post('/projects/{project}/resume', [ProjectStopController::class, 'resume']);

    Route::get('/projects/{project}/code', [CodeController::class, 'getXaaSCode']);
    Route::post('/projects/{project}/code', [CodeController::class, 'saveXaaSCode']);

    Route::get('/projects/{project}/files', [CodeController::class, 'getFaaSFiles']);
    Route::post('/projects/{project}/files', [CodeController::class, 'saveFaaSFiles']);

    Route::get('/projects/{project}/versions', [CodeController::class, 'versions']);
    Route::get('/projects/{project}/versions/{version}/files', [CodeController::class, 'versionFiles']);

    Route::get('/projects/{project}/deployments', [DeploymentController::class, 'index']);
    Route::post('/projects/{project}/deploy', [DeploymentController::class, 'deploy']);
    Route::get('/projects/{project}/deployments/{deployment}', [DeploymentController::class, 'show']);
    // A deploy that waits for its version's build (a version built again for the region's registry)
    Route::get('/projects/{project}/queued-deploys/{queuedDeploy}', [DeploymentController::class, 'queued']);
    Route::get('/projects/{project}/deployments/{deployment}/logs', [DeploymentController::class, 'logs']);
    Route::get('/projects/{project}/deployments/{deployment}/metrics', [DeploymentController::class, 'metrics']);
    Route::delete('/projects/{project}/deployments/{deployment}', [DeploymentController::class, 'destroy']);
    Route::patch('/projects/{project}/deployments/{deployment}/status', [DeploymentController::class, 'updateStatus']);

    // Private connections to Managed PostgreSQL through Skupper
    Route::get('/projects/{project}/private-connections', [PrivateDatabaseBindingController::class, 'index']);
    Route::post('/projects/{project}/private-connections', [PrivateDatabaseBindingController::class, 'store']);
    Route::get('/projects/{project}/private-connections/{privateConnection}/status', [PrivateDatabaseBindingController::class, 'status']);
    Route::delete('/projects/{project}/private-connections/{privateConnection}', [PrivateDatabaseBindingController::class, 'destroy']);

    // Bindings: managed KV / object storage / Postgres attached to projects (routes/bindings.php)
    require __DIR__.'/bindings.php';

    // Shell exec — returns a one-time token; WebSocket handled by Reverb
    Route::post('/projects/{project}/deployments/{deployment}/exec', [ExecController::class, 'start']);

    // Custom domains per project — {domain} is scoped to {project}
    Route::scopeBindings()->group(function () {
        Route::get('/projects/{project}/domains', [ProjectDomainController::class, 'index']);
        Route::post('/projects/{project}/domains', [ProjectDomainController::class, 'store']);
        Route::post('/projects/{project}/domains/{domain}/verify', [ProjectDomainController::class, 'verify']);
        Route::put('/projects/{project}/domains/{domain}/tls', [ProjectDomainController::class, 'updateTls']);
        Route::delete('/projects/{project}/domains/{domain}', [ProjectDomainController::class, 'destroy']);
    });

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::patch('/settings', [SettingsController::class, 'update']);

    // Runtime plugins: the catalog for everyone; registration needs runtimes.manage (platform)
    // or runtimes.use_custom (organization), checked in the controller
    Route::get('/runtimes', [RuntimeController::class, 'index']);
    Route::post('/runtimes/validate', [RuntimeController::class, 'validateManifest']);
    Route::post('/runtimes', [RuntimeController::class, 'store']);
    Route::get('/runtimes/{runtime}', [RuntimeController::class, 'show']);
    Route::put('/runtimes/{runtime}', [RuntimeController::class, 'update']);
    Route::delete('/runtimes/{runtime}', [RuntimeController::class, 'destroy']);
    Route::get('/runtimes/{runtime}/template', [RuntimeController::class, 'template']);
    Route::get('/runtimes/{runtime}/builds', [RuntimeController::class, 'builds']);
    Route::post('/runtimes/{runtime}/builds', [RuntimeController::class, 'build']);
    Route::post('/runtimes/{runtime}/test-deploy', [RuntimeController::class, 'testDeploy']);

    // Region-less preview endpoints (must be before apiResource to avoid ID collision)
    Route::post('/knative-regions/validate-kubeconfig', [KnativeRegionController::class, 'validateKubeconfig']);
    Route::post('/knative-regions/preview-nodes', [KnativeRegionController::class, 'previewNodes']);
    Route::post('/knative-regions/check-namespace-preview', [KnativeRegionController::class, 'checkNamespacePreview']);
    Route::post('/knative-regions/preview-storage-classes', [KnativeRegionController::class, 'previewStorageClasses']);

    // Every route that changes a region (or hands out its kubeconfig) carries `region.mutable`
    // (App\Http\Middleware\EnsureRegionMutable; with tenancy: platform regions read-only for
    // tenant users, tenant-owned regions for the tenant's owner/admin only).

    // CRUD + settings + kubeconfig
    Route::apiResource('knative-regions', KnativeRegionController::class)->only(['index', 'show']);
    Route::apiResource('knative-regions', KnativeRegionController::class)->only(['store', 'update', 'destroy'])
        ->middleware('region.mutable');
    Route::get('/knative-regions/{knativeRegion}/kubeconfig', [KnativeRegionController::class, 'downloadKubeconfig'])->middleware('region.mutable');
    Route::put('/knative-regions/{knativeRegion}/settings', [KnativeRegionController::class, 'updateSettings'])->middleware('region.mutable');
    Route::put('/knative-regions/{knativeRegion}/monitoring', [KnativeRegionController::class, 'updateMonitoring'])->middleware('region.mutable');

    // Capacity budgets (always-on, FaaS overcommit) and the overcommit factors → RegionCapacityController
    Route::get('/knative-regions/{knativeRegion}/capacity', [RegionCapacityController::class, 'show']);
    Route::put('/knative-regions/{knativeRegion}/capacity', [RegionCapacityController::class, 'update'])->middleware('region.mutable');

    // Function-domain TLS (region's own Traefik, separate from the console's Ingress) — 100% opt-in
    Route::put('/knative-regions/{knativeRegion}/function-tls', [KnativeFunctionTlsController::class, 'update'])->middleware('region.mutable');
    Route::delete('/knative-regions/{knativeRegion}/function-tls', [KnativeFunctionTlsController::class, 'destroy'])->middleware('region.mutable');

    // Skupper v2 on the region (detected, never installed by RunR) + the project namespace's Site
    Route::get('/knative-regions/{knativeRegion}/skupper', [PrivateDatabaseBindingController::class, 'regionStatus']);
    Route::post('/knative-regions/{knativeRegion}/skupper/site', [PrivateDatabaseBindingController::class, 'ensureSite'])->middleware('region.mutable');

    // Install/Uninstall/Status → KnativeInstallController
    Route::get('/knative-regions/{knativeRegion}/knative-status', [KnativeInstallController::class, 'knativeStatus']);
    Route::post('/knative-regions/{knativeRegion}/install-knative', [KnativeInstallController::class, 'installKnative'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/uninstall-knative', [KnativeInstallController::class, 'uninstallKnative'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/install-operator', [KnativeInstallController::class, 'installOperator'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/install-serving', [KnativeInstallController::class, 'installServing'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/upgrade-serving', [KnativeInstallController::class, 'upgradeServing'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/uninstall-operator', [KnativeUninstallController::class, 'uninstallOperator'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/uninstall-serving', [KnativeUninstallController::class, 'uninstallServing'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/detect-components', [KnativeInstallController::class, 'detectComponents'])->middleware('region.mutable');
    Route::get('/knative-regions/{knativeRegion}/check-namespace', [KnativeInstallController::class, 'checkNamespace']);
    Route::get('/knative-regions/{knativeRegion}/compatible-versions', [KnativeInstallController::class, 'compatibleVersions']);
    Route::get('/knative-regions/{knativeRegion}/component-status', [KnativeInstallController::class, 'componentStatus']);

    // Nodes → KnativeNodeController
    Route::get('/knative-regions/{knativeRegion}/nodes', [KnativeNodeController::class, 'listNodes']);
    Route::post('/knative-regions/{knativeRegion}/workers', [KnativeNodeController::class, 'applyWorkers'])->middleware('region.mutable');

    // Redis → KnativeRedisController
    Route::post('/knative-regions/{knativeRegion}/test-redis', [KnativeRedisController::class, 'testRedis'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/redis', [KnativeRedisController::class, 'configureRedis'])->middleware('region.mutable');
    Route::delete('/knative-regions/{knativeRegion}/redis', [KnativeRedisController::class, 'removeRedis'])->middleware('region.mutable');
    Route::get('/knative-regions/{knativeRegion}/redis-status', [KnativeRedisController::class, 'redisStatus']);

    // Sandbox (Runtime Isolation)
    Route::get('/knative-regions/{knativeRegion}/kata-shims', [SandboxController::class, 'kataShims']);
    Route::post('/knative-regions/{knativeRegion}/install-sandbox', [SandboxController::class, 'installSandbox'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/uninstall-sandbox', [SandboxController::class, 'uninstallSandbox'])->middleware('region.mutable');
    Route::get('/knative-regions/{knativeRegion}/sandbox-status', [SandboxController::class, 'sandboxStatus']);
    Route::put('/knative-regions/{knativeRegion}/sandbox-mapping', [SandboxController::class, 'updateMapping'])->middleware('region.mutable');

    // WebAssembly support (runwasi shims on selected nodes) → WasmController; POST / DELETE: sandbox.manage
    Route::get('/knative-regions/{knativeRegion}/wasm', [WasmController::class, 'show']);
    Route::post('/knative-regions/{knativeRegion}/wasm', [WasmController::class, 'install'])->middleware('region.mutable');
    Route::delete('/knative-regions/{knativeRegion}/wasm', [WasmController::class, 'uninstall'])->middleware('region.mutable');

    // Region migration
    Route::post('/knative-regions/{knativeRegion}/migrate', [RegionMigrateController::class, 'startMigration'])->middleware('region.mutable');
    Route::get('/knative-regions/{knativeRegion}/migration-status', [RegionMigrateController::class, 'migrationStatus']);
    Route::post('/knative-regions/{knativeRegion}/complete-migration', [RegionMigrateController::class, 'completeMigration'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/rollback-migration', [RegionMigrateController::class, 'rollbackMigration'])->middleware('region.mutable');
    Route::post('/knative-regions/{knativeRegion}/complete-domain-migration', [RegionMigrateController::class, 'completeDomainMigration'])->middleware('region.mutable');

    // Registry setup (async flow with cache token) — creates a region, like POST /knative-regions
    Route::post('/registry-setup/start', [RegistrySetupController::class, 'start'])->middleware('region.mutable');
    Route::get('/registry-setup/{token}/status', [RegistrySetupController::class, 'status']);
    Route::post('/registry-setup/{token}/retry', [RegistrySetupController::class, 'retry'])->middleware('region.mutable');
    Route::post('/registry-setup/test-pvc', [RegistrySetupController::class, 'testPvc']);
    Route::post('/registry-setup/test-s3', [RegistrySetupController::class, 'testS3']);
    Route::post('/registry-setup/test-external', [RegistrySetupController::class, 'testExternal']);

    // Registry status/management (per-region)
    Route::get('/knative-regions/{knativeRegion}/registry-status', [RegistryController::class, 'status']);
    Route::post('/knative-regions/{knativeRegion}/registry', [RegistryController::class, 'store'])->middleware('region.mutable');
    Route::delete('/knative-regions/{knativeRegion}/registry', [RegistryController::class, 'destroy'])->middleware('region.mutable');

    // Region demo projects
    Route::post('/knative-regions/{knativeRegion}/demo-projects', [RegionDemoController::class, 'store'])->middleware('region.mutable');
    Route::delete('/knative-regions/{knativeRegion}/demo-projects', [RegionDemoController::class, 'destroy'])->middleware('region.mutable');

    // Gateways
    Route::get('/gateways', [GatewayController::class, 'index']);
    Route::get('/gateways/{gateway}', [GatewayController::class, 'show']);
    Route::patch('/gateways/{gateway}', [GatewayController::class, 'update']);
    Route::get('/gateways/{gateway}/routes', [GatewayController::class, 'routes']);
    Route::post('/gateways/{gateway}/routes', [GatewayController::class, 'addRoute']);
    Route::patch('/gateways/{gateway}/routes/{route}', [GatewayController::class, 'updateRoute']);
    Route::delete('/gateways/{gateway}/routes/{route}', [GatewayController::class, 'removeRoute']);

    Route::post('/demo', [DemoController::class, 'store']);
    Route::delete('/demo', [DemoController::class, 'destroy']);

    // Historical metrics
    Route::get('/projects/{project}/metrics/history', [HistoricalMetricsController::class, 'history']);
    Route::get('/projects/{project}/metrics/summary', [HistoricalMetricsController::class, 'summary']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications', [NotificationController::class, 'destroyAll']);

    // User management
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
    Route::post('/users/{user}/reset-2fa', [UserController::class, 'reset2FA']);

    // API Tokens
    Route::get('/tokens', [ApiTokenController::class, 'index']);
    Route::post('/tokens', [ApiTokenController::class, 'store']);
    Route::delete('/tokens/{token}', [ApiTokenController::class, 'destroy']);

    // Account (self-service)
    Route::get('/account', [AccountController::class, 'show']);
    Route::patch('/account/profile', [AccountController::class, 'updateProfile']);
    Route::post('/account/avatar', [AccountController::class, 'uploadAvatar']);
    Route::delete('/account/avatar', [AccountController::class, 'removeAvatar']);
    Route::post('/account/password', [AccountController::class, 'changePassword'])->middleware('throttle:auth');
    Route::post('/account/disable', [AccountController::class, 'disableAccount']);
    Route::delete('/account', [AccountController::class, 'deleteAccount']);

    // Two-Factor Authentication
    Route::post('/account/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('/account/2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::delete('/account/2fa', [TwoFactorController::class, 'disable']);
    Route::post('/account/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);

    // Sessions
    Route::get('/account/sessions', [SessionController::class, 'index']);
    Route::delete('/account/sessions/{tokenId}', [SessionController::class, 'destroy']);
    Route::post('/account/sessions/revoke-all', [SessionController::class, 'destroyAll']);

    // Notification Preferences
    Route::get('/account/notification-preferences', [NotificationPreferenceController::class, 'index']);
    Route::put('/account/notification-preferences', [NotificationPreferenceController::class, 'update']);

    // Contact form
    Route::post('/contact', [ContactController::class, 'store']);

    // Secret env vars of a project: write-only values (docs/security.md)
    Route::get('/projects/{project}/secrets', [ProjectSecretController::class, 'index']);
    Route::put('/projects/{project}/secrets/{name}', [ProjectSecretController::class, 'upsert']);
    Route::delete('/projects/{project}/secrets/{name}', [ProjectSecretController::class, 'destroy']);
    Route::get('/projects/{project}/secrets/{name}/value', [ProjectSecretController::class, 'reveal']);

    // Audit log (docs/security.md) — export before {id}
    Route::get('/audit-events', [AuditEventController::class, 'index']);
    Route::get('/audit-events/export', [AuditEventController::class, 'export']);
    Route::get('/audit-events/{id}', [AuditEventController::class, 'show']);
    Route::get('/audit-settings', [AuditEventController::class, 'settings']);
    Route::put('/audit-settings', [AuditEventController::class, 'updateSettings']);

    // Horizon dashboard: a single-use sign-in link for platform administrators (docs/install.md)
    Route::post('/horizon/link', HorizonLinkController::class);

    Route::post('/tutorial/complete', function (Request $request) {
        $request->user()->update(['tutorial_completed_at' => now()]);

        return response()->json(['status' => 'ok']);
    });
});
