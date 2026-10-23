<?php

namespace App\Services\Audit;

use App\Mcp\Models\HostedServer;
use App\Mcp\Models\OAuthClient;
use App\Models\AiHubCredential;
use App\Models\AiModel;
use App\Models\AiModelBinding;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use App\Models\ApiProduct\ApiPlan;
use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\BindingRegionService;
use App\Models\Branding;
use App\Models\ContainerConfig;
use App\Models\FaasConfig;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\Git\GitInstallation;
use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use App\Models\Git\PreviewEnvironment;
use App\Models\Git\ProjectGitSettings;
use App\Models\Group;
use App\Models\ImageUpload;
use App\Models\KnativeRegion;
use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertRule;
use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\RegionTracingConfig;
use App\Models\Observability\StatusPage;
use App\Models\Observability\UptimeCheck;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectRegion;
use App\Models\ProjectSecret;
use App\Models\RegionSecretSetting;
use App\Models\RegionSupplyChainSetting;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Runtime;
use App\Models\SecretStore;
use App\Models\SecurityPolicyOverride;
use App\Models\Setting;
use App\Models\TenantSecurityPolicy;
use App\Models\UploadedImage;
use App\Models\User;
use App\Models\XaasConfig;
use App\Services\Sandboxes\SandboxLog;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The audit event catalog (docs/security.md, "Audited events"): which API routes are audited
 * under which action name, and how their target is resolved. RecordAuditTrail records a mapped
 * route after the response with result success (< 400), denied (401/403/429) or failure; any
 * other route answered 403 is recorded as authorization.denied. Events that do not map to one
 * API request (SSO sign-in, shell sessions, retention) are recorded where they happen —
 * EXPLICIT_ACTIONS.
 */
final class AuditCatalog
{
    /** Recorded by the code that performs them, not by a route. */
    public const EXPLICIT_ACTIONS = [
        'auth.sso.login',
        'shell.session.open',
        'shell.session.close',
        'authorization.denied',
        'audit.retention.pruned',
        'secrets.reencrypted',
        // Pass-through upgrade: secret values moved from the database into the regions (secrets:move-to-region)
        'secrets.moved_to_region',
        // The upgrade from 0.5.10 finalized: the copies kept for a rollback dropped (secrets:move-to-region --finalize)
        'secrets.pass_through_finalized',
        // Sandbox API: the reaper ending a sandbox at its TTL, idle timeout or quota (SandboxLog)
        'sandbox.expire',
        // Bindings: the hourly purge deletes a binding's data after its retention (BindingManager)
        'binding.purged',
        // Software supply chain + data residency (SupplyChainAudit): decisions inside a request
        // and the results of region jobs
        'deploy.policy_blocked',
        'deploy.policy_warned',
        'deploy.policy_overridden',
        'residency.denied',
        'residency.migration_denied',
        'image.signed',
        'region.signing_key.generated',
        'region.signing_key.rotated',
        'region.signing_key.failed',
        'region.admission.installed',
        'region.admission.removed',
        'region.admission.failed',
        'region.scanner_db.refreshed',
        // Git deploy (App\Services\GitDeploy\GitAudit): pushes to the RunR git remote (smart
        // HTTP, not an API route), the GitHub App setup callback, rejected provider webhooks
        'git.push',
        'git.installation.link',
        'git.webhook.rejected',
        // Uploaded images (App\Services\Images\ImageUploadService): the region's push result,
        // after the request that completed the upload
        'image.upload.pushed',
        'image.upload.failed',
        // Organization export / import (App\Services\OrgTransfer, docs/org-export.md): the jobs'
        // results, in the trail of the organization exported from / imported into
        'org.export.completed',
        'org.export.failed',
        'org.import.rejected',
        'org.import.fetched',
        'org.import.fetch_failed',
        'org.import.completed',
        'org.import.failed',
        // AI agents (docs/mcp.md, McpAudit): MCP sessions, every tools/call, the OAuth server's
        // registrations and tokens, calls through the hosted-server gateway (when audited)
        'mcp.session.start',
        'mcp.session.end',
        'mcp.tool.call',
        'mcp.server.call',
        'oauth.client.register',
        'oauth.token.issue',
        'oauth.token.revoke',
        // Runtime patches (RuntimePatchMigrations): RunR moving a project onto a patch at its
        // deadline (or a platform administrator's force), the rollback of one that failed
        'runtime_patch.migrate',
        'runtime_patch.rollback',
        // Deploy strategies (App\Services\Rollouts\RolloutService): what a blue-green / canary
        // rollout does by itself — the user's promote / abort / rollback are audited by their route
        'deployment.rollout.auto_promoted',
        'deployment.rollout.auto_rolled_back',
        'deployment.rollout.failed',
        'deployment.rollout.released',
        // Environments (App\Services\Environments\Promotions): the deployment of an approved
        // promotion went live or failed, after the request that approved it
        'environment.promotion.deployed',
        'environment.promotion.failed',
    ];

    /** Models whose changes during an audited request are recorded (AuditChanges) => target type. */
    private const MODEL_TYPES = [
        Project::class => 'project',
        XaasConfig::class => 'project_config',
        FaasConfig::class => 'project_config',
        ContainerConfig::class => 'project_config',
        ProjectSecret::class => 'project_secret',
        // The organization's secret stores (settings only: addresses, roles — never a credential)
        SecretStore::class => 'secret_store',
        RegionSecretSetting::class => 'region_secrets',
        Workflow::class => 'workflow',
        WorkflowApp::class => 'workflow_app',
        ProjectDomain::class => 'domain',
        // Global projects: the regions of a global project (docs/global-projects.md)
        ProjectRegion::class => 'project_region',
        Gateway::class => 'gateway',
        GatewayRoute::class => 'gateway_route',
        // API product (docs/api-product.md): a key's hash appears by name only (hidden)
        ApiConsumer::class => 'api_consumer',
        ApiKey::class => 'api_key',
        ApiPlan::class => 'api_plan',
        PrivateDatabaseBinding::class => 'private_connection',
        Binding::class => 'binding',
        BindingAttachment::class => 'binding_attachment',
        BindingRegionService::class => 'data_service',
        'Modules\\Tenancy\\Models\\TenantBindingQuota' => 'binding_quota',
        KnativeRegion::class => 'region',
        RegionSupplyChainSetting::class => 'region_supply_chain',
        TenantSecurityPolicy::class => 'tenant_security_policy',
        SecurityPolicyOverride::class => 'policy_override',
        Setting::class => 'setting',
        Branding::class => 'branding',
        Runtime::class => 'runtime',
        // Uploaded images (docs/images.md)
        UploadedImage::class => 'uploaded_image',
        ImageUpload::class => 'image_upload',
        // Git deploy (docs/git-deploy.md)
        GitProvider::class => 'git_provider',
        GitInstallation::class => 'git_installation',
        GitRepoLink::class => 'git_repo_link',
        ProjectGitSettings::class => 'git_settings',
        PreviewEnvironment::class => 'preview',
        // AI: Hugging Face models and model services (docs/huggingface.md)
        AiModel::class => 'model',
        AiModelSnapshot::class => 'model_snapshot',
        AiModelService::class => 'model_service',
        AiModelBinding::class => 'model_binding',
        AiHubCredential::class => 'hub_token',
        Role::class => 'role',
        // AI agents: hosted MCP servers (keys are encrypted: changes by name only), OAuth clients
        HostedServer::class => 'mcp_server',
        OAuthClient::class => 'oauth_client',
        Group::class => 'group',
        RoleAssignment::class => 'role_assignment',
        'Modules\\AuthProvider\\Models\\SsoGroupMapping' => 'sso_group_mapping',
        User::class => 'user',
        'Modules\\Tenancy\\Models\\Tenant' => 'tenant',
        'Modules\\Tenancy\\Models\\TenantUser' => 'member',
        'Modules\\Tenancy\\Models\\TenantInvitation' => 'invitation',
        // Billing module (docs/billing.md)
        'Modules\\Billing\\Models\\Price' => 'billing_price',
        'Modules\\Billing\\Models\\RegionSetting' => 'billing_region',
        'Modules\\Billing\\Models\\Markup' => 'billing_markup',
        'Modules\\Billing\\Models\\FixedRequest' => 'fixed_request',
        'Modules\\Billing\\Models\\Bill' => 'bill',
        'Modules\\AuthProvider\\Models\\AuthProvider' => 'auth_provider',
        'Modules\\Ticket\\Models\\SupportTicket' => 'ticket',
        // Observability (secret columns are hidden + encrypted: never part of a change record)
        RegionTracingConfig::class => 'region_tracing',
        ProjectObservabilitySetting::class => 'project_observability',
        UptimeCheck::class => 'uptime_check',
        StatusPage::class => 'status_page',
        AlertChannel::class => 'alert_channel',
        AlertRule::class => 'alert_rule',
    ];

    /** Copies kept in sync by the Tenancy module: their change is the central row's change. */
    private const NOT_TRACKED = [
        'Modules\\Tenancy\\Models\\TenantLocalRegion',
        'Modules\\Tenancy\\Models\\CentralSyncableRegion',
        'Modules\\Tenancy\\Models\\CentralSyncableUser',
        'Modules\\Tenancy\\Models\\TenantLocalUser',
    ];

    /** @var array<string, AuditRoute>|null "METHOD uri" => route */
    private static ?array $routes = null;

    public static function forRequest(Request $request): ?AuditRoute
    {
        $route = $request->route();
        if (! $route) {
            return null;
        }

        return self::routes()[strtoupper($request->method()).' '.$route->uri()] ?? null;
    }

    /** @return array<string, AuditRoute> */
    public static function routes(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }

        $out = [];
        foreach (self::definitions() as $key => $route) {
            [$methods, $uri] = explode(' ', $key, 2);
            foreach (explode('|', $methods) as $method) {
                $out["{$method} {$uri}"] = $route;
            }
        }

        return self::$routes = $out;
    }

    /** Every action name the catalog can produce (UI filter). @return list<string> */
    public static function actions(): array
    {
        $names = self::EXPLICIT_ACTIONS;
        foreach (self::routes() as $route) {
            if (is_string($route->action)) {
                $names[] = $route->action;
            }
        }
        array_push($names, 'deployment.create', 'deployment.rollback', 'member.add', 'member.invite', 'mcp_server.create', 'mcp_server.update');

        $names = array_unique($names);
        sort($names);

        return $names;
    }

    public static function modelType(Model $model): string
    {
        foreach (self::MODEL_TYPES as $class => $type) {
            if (is_a($model, $class)) {
                return $type;
            }
        }

        return Str::snake(class_basename($model));
    }

    /** Target type of a change-tracked model, null for models whose changes are not recorded. */
    public static function changeTrackedType(Model $model): ?string
    {
        foreach (self::NOT_TRACKED as $class) {
            if (is_a($model, $class)) {
                return null;
            }
        }
        foreach (self::MODEL_TYPES as $class => $type) {
            if (is_a($model, $class)) {
                return $type;
            }
        }

        return null;
    }

    /** A human-readable name of a target model (never a secret field). */
    public static function displayName(Model $model): ?string
    {
        foreach (['name', 'slug', 'domain', 'email', 'subject', 'title', 'username', 'path', 'key', 'subdomain'] as $field) {
            $value = $model->getAttributes()[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return null;
    }

    /** The command of a sandbox exec / process request for the audit log (at most 1000 characters). */
    private static function sandboxCommand(Request $request): array
    {
        $cmd = $request->input('cmd');
        if ($cmd === null && $request->filled('code')) {
            return ['language' => $request->input('language'), 'code_bytes' => strlen((string) $request->input('code'))];
        }

        return ['command' => SandboxLog::commandLine(is_array($cmd) ? array_map('strval', array_filter($cmd, 'is_scalar')) : (string) $cmd)];
    }

    /** Key of a route parameter, bound model or plain value */
    private static function routeKey(Request $request, string $parameter): string
    {
        $value = $request->route($parameter);

        return $value instanceof Model ? (string) $value->getKey() : (string) $value;
    }

    /** @return array<string, AuditRoute> */
    private static function definitions(): array
    {
        $r = fn (...$args) => new AuditRoute(...$args);
        $p = 'param:project';

        $deployAction = function (Request $request): string {
            $project = $request->route('project');
            if (! $project instanceof Project) {
                return 'deployment.create';
            }
            $number = $project->versions()->whereKey($request->input('version_id'))->value('number');
            $latest = $project->versions()->max('number');

            return $number !== null && $latest !== null && (int) $number < (int) $latest
                ? 'deployment.rollback'
                : 'deployment.create';
        };

        return [
            // ── Authentication & account ──────────────────────────────────────────
            'POST api/auth/register' => $r('user.register', 'user', 'response:user.id', 'response:user.email', actor: 'response:user.id'),
            'POST api/auth/login' => $r('auth.login', actor: 'login'),
            'POST api/auth/logout' => $r('auth.logout'),
            // Self-service signup with e-mail verification (white-label): the account is created here
            'POST api/auth/signup/verify' => $r('user.signup.verify', 'user', 'response:user.id', 'response:user.email', actor: 'response:user.id'),
            'POST api/auth/signup/resend' => $r('user.signup.resend', meta: fn (Request $q) => ['email' => mb_substr((string) $q->input('email'), 0, 190)]),
            'POST api/account/password' => $r('auth.password.change'),
            'POST api/account/2fa/setup' => $r('auth.2fa.setup'),
            'POST api/account/2fa/confirm' => $r('auth.2fa.enable'),
            'DELETE api/account/2fa' => $r('auth.2fa.disable'),
            'POST api/account/2fa/recovery-codes' => $r('auth.2fa.recovery_codes.regenerate'),
            'PATCH api/account/profile' => $r('account.profile.update', meta: fn (Request $q) => ['fields' => array_keys($q->except(['password', 'current_password']))]),
            'POST api/account/avatar' => $r('account.avatar.update'),
            'DELETE api/account/avatar' => $r('account.avatar.remove'),
            'POST api/account/disable' => $r('account.disable'),
            'DELETE api/account' => $r('account.delete'),
            'PUT api/account/notification-preferences' => $r('account.notification_preferences.update'),
            'DELETE api/account/sessions/{tokenId}' => $r('session.revoke', 'session', 'param:tokenId'),
            'POST api/account/sessions/revoke-all' => $r('session.revoke_all'),
            'POST api/tokens' => $r('api_token.create', 'api_token', 'response:token.id', 'input:name',
                meta: fn (Request $q) => ['abilities' => (array) $q->input('abilities', ['*']), 'expires_at' => $q->input('expires_at')]),
            'DELETE api/tokens/{token}' => $r('api_token.revoke', 'api_token', 'param:token'),
            'POST api/oauth/link/complete' => $r('social_account.link', 'social_account', 'response:account.id', 'response:account.provider'),
            'DELETE api/social-accounts/{socialAccount}' => $r('social_account.unlink', 'social_account', 'param:socialAccount'),

            // ── Projects ─────────────────────────────────────────────────────────
            'POST api/projects' => $r('project.create', 'project', 'response:id', 'response:name',
                meta: fn (Request $q) => ['service_type' => $q->input('service_type'), 'runtime' => $q->input('runtime'), 'knative_region_id' => $q->input('knative_region_id')]),
            'PUT|PATCH api/projects/{project}' => $r('project.update', 'project', $p),
            'DELETE api/projects/{project}' => $r('project.delete', 'project', $p),
            'PUT api/projects/{project}/owner' => $r('project.owner.transfer', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['owner_id' => (string) $q->input('user_id'), 'previous_owner_id' => $j['previous_owner_id'] ?? null]),
            // Global projects (docs/global-projects.md)
            'PUT api/projects/{project}/global' => $r('project.global.update', 'project', $p, meta: fn (Request $q) => array_filter([
                'mode' => $q->input('mode'),
                'failover' => $q->input('failover'),
                'primary_region_id' => $q->input('primary_region_id'),
                'region_ids' => $q->input('region_ids'),
            ], fn ($v) => $v !== null)),
            'POST api/projects/{project}/global/sync' => $r('project.global.sync', 'project', $p),
            'POST api/projects/{project}/global/failover' => $r('project.global.failover', 'project', $p, meta: fn (Request $q) => array_filter([
                'region_id' => $q->input('region_id'),
            ], fn ($v) => $v !== null)),
            'POST api/projects/{project}/stop' => $r('project.stop', 'project', $p, meta: fn (Request $q) => ['reason' => mb_substr((string) $q->input('reason'), 0, 1000)]),
            'POST api/projects/{project}/resume' => $r('project.resume', 'project', $p, meta: fn (Request $q, ?array $j) => ['deployment_id' => $j['deployment_id'] ?? null]),
            // Rightsizing (docs/monitoring.md#rightsizing): the applied size saves a version
            'POST api/projects/{project}/rightsizing/apply' => $r('project.rightsizing.apply', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['recommended' => $j['data']['recommended'] ?? null, 'previous' => $j['data']['current'] ?? null, 'version' => $j['version']['number'] ?? null]),
            'POST api/projects/{project}/rightsizing/dismiss' => $r('project.rightsizing.dismiss', 'project', $p,
                meta: fn (Request $q) => ['snooze_days' => $q->input('snooze_days')]),
            'POST api/projects/{project}/code' => $r('project.code.save', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['version' => $j['number'] ?? $j['data']['number'] ?? null, 'message' => $q->input('message')]),
            'POST api/projects/{project}/files' => $r('project.code.save', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['files' => array_map('strval', array_keys((array) $q->input('files', []))), 'version' => $j['number'] ?? $j['data']['number'] ?? null]),
            'PUT api/projects/{project}/secrets/{name}' => $r('project.secret.set', 'project', $p, meta: fn (Request $q) => ['name' => $q->route('name')]),
            'DELETE api/projects/{project}/secrets/{name}' => $r('project.secret.delete', 'project', $p, meta: fn (Request $q) => ['name' => $q->route('name')]),
            'GET api/projects/{project}/secrets/{name}/value' => $r('project.secret.reveal', 'project', $p, meta: fn (Request $q) => ['name' => $q->route('name')]),
            'POST api/projects/{project}/secrets/refresh' => $r('project.secret.refresh', 'project', $p),
            // Secret stores (docs/secret-stores.md): settings only, never a credential
            'POST api/secret-stores' => $r('secret_store.create', 'secret_store', 'response:data.id', 'input:name',
                meta: fn (Request $q) => ['provider' => $q->input('provider'), 'auth' => $q->input('auth')]),
            'PUT api/secret-stores/{secretStore}' => $r('secret_store.update', 'secret_store', 'param:secretStore'),
            'DELETE api/secret-stores/{secretStore}' => $r('secret_store.delete', 'secret_store', 'param:secretStore'),
            'POST api/secret-stores/{secretStore}/verify' => $r('secret_store.verify', 'secret_store', 'param:secretStore',
                meta: fn (Request $q, ?array $j) => ['region_id' => $q->input('region_id'), 'status' => $j['data']['status'] ?? null]),

            // ── Runtime patches (routes/runtime-patches.php) ─────────────────────
            'POST api/projects/{project}/runtime-patches/apply' => $r('runtime_patch.apply', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['patch_id' => $j['data']['patch_id'] ?? null, 'migration_id' => $j['data']['id'] ?? null]),
            'PUT api/projects/{project}/runtime-patches/pin' => $r('runtime_patch.pin', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['patch_id' => $q->input('patch_id'), 'deploy' => $q->boolean('deploy', true), 'migration_id' => $j['migration']['id'] ?? null]),
            'DELETE api/projects/{project}/runtime-patches/pin' => $r('runtime_patch.unpin', 'project', $p),
            'POST api/admin/runtime-patches/check' => $r('runtime_patch.feed.check', meta: fn (Request $q, ?array $j) => ['status' => $j['data']['status'] ?? null, 'digest' => $j['data']['manifest_digest'] ?? null]),
            'PUT api/admin/runtime-patches/policy' => $r('runtime_patch.policy.update', meta: fn (Request $q) => ['auto_migrate' => $q->boolean('auto_migrate')]),
            'POST api/admin/runtime-patches/{patch}/remind' => $r('runtime_patch.remind', 'runtime_patch', 'param:patch', meta: fn (Request $q, ?array $j) => ['told' => $j['data']['told'] ?? null]),
            'POST api/admin/runtime-patches/{patch}/force' => $r('runtime_patch.force', 'runtime_patch', 'param:patch', meta: fn (Request $q, ?array $j) => ['started' => $j['data']['started'] ?? null]),
            'PUT api/admin/runtime-patches/{patch}/deadline' => $r('runtime_patch.deadline.update', 'runtime_patch', 'param:patch', meta: fn (Request $q) => ['deadline_at' => $q->input('deadline_at')]),

            // ── Durable workflows (routes/workflows.php) ─────────────────────────
            'PUT api/projects/{project}/workflows' => $r('workflow.declare', 'project', $p),
            'POST api/projects/{project}/workflows/sync' => $r('workflow.sync', 'project', $p),
            'PATCH api/projects/{project}/workflows/app' => $r('workflow.app.update', 'project', $p, meta: fn (Request $q) => ['endpoint_url' => $q->input('endpoint_url')]),
            'POST api/projects/{project}/workflows/app/rotate-key' => $r('workflow.signing_key.rotate', 'project', $p),
            'GET api/projects/{project}/workflows/app/signing-key' => $r('workflow.signing_key.reveal', 'project', $p),
            'PATCH api/projects/{project}/workflows/{workflow}' => $r('workflow.update', 'project', $p,
                meta: fn (Request $q) => ['workflow' => self::routeKey($q, 'workflow'), 'fields' => array_keys($q->all())]),
            'PUT api/projects/{project}/workflows/{workflow}' => $r('workflow.declare', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['workflow' => self::routeKey($q, 'workflow'), 'source' => $j['data']['source'] ?? null]),
            'DELETE api/projects/{project}/workflows/{workflow}' => $r('workflow.archive', 'project', $p,
                meta: fn (Request $q) => ['workflow' => self::routeKey($q, 'workflow')]),
            'POST api/projects/{project}/workflows/{workflow}/runs' => $r('workflow.run.start', 'workflow_run', 'response:data.id',
                meta: fn (Request $q) => ['project_id' => $q->route('project')?->id, 'workflow' => self::routeKey($q, 'workflow')]),
            'POST api/projects/{project}/workflow-runs/{run}/cancel' => $r('workflow.run.cancel', 'workflow_run', 'param:run', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            'POST api/projects/{project}/workflow-runs/{run}/retry' => $r('workflow.run.retry', 'workflow_run', 'param:run', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            'POST api/projects/{project}/workflow-runs/{run}/replay' => $r('workflow.run.replay', 'workflow_run', 'param:run', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),

            // ── Observability (routes/observability.php) — never secret values: channel
            //    credentials, check headers and export headers are named, not recorded ──
            'PUT api/observability/regions/{knativeRegion}/tracing' => $r('region.tracing.update', 'region', 'param:knativeRegion',
                meta: fn (Request $q) => ['mode' => $q->input('mode'), 'install' => $q->boolean('install'), 'knative_sample_rate' => $q->input('knative_sample_rate'),
                    'export_endpoint' => $q->input('export_endpoint'), 'export_header_names' => array_map('strval', array_keys((array) $q->input('export_headers', [])))]),
            'POST api/observability/regions/{knativeRegion}/tracing/install' => $r('region.tracing.install', 'region', 'param:knativeRegion'),
            'DELETE api/observability/regions/{knativeRegion}/tracing' => $r('region.tracing.uninstall', 'region', 'param:knativeRegion'),
            'PUT api/projects/{project}/observability/tracing' => $r('project.tracing.update', 'project', $p, meta: fn (Request $q) => $q->only(['enabled', 'sample_ratio'])),
            'PUT api/projects/{project}/observability/replay' => $r('project.replay.update', 'project', $p,
                meta: fn (Request $q) => $q->only(['enabled', 'max_body_bytes', 'retention_days', 'redact_headers', 'redact_query'])),
            'POST api/projects/{project}/replays/{capture}/replay' => $r('replay.replay', 'replay_capture', 'param:capture',
                meta: fn (Request $q, ?array $j) => ['project_id' => $q->route('project')?->id, 'status' => $j['data']['replay']['status'] ?? null,
                    'override_headers' => array_map('strval', array_keys((array) $q->input('headers', [])))]),
            'DELETE api/projects/{project}/replays/{capture}' => $r('replay.delete', 'replay_capture', 'param:capture', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            'DELETE api/projects/{project}/replays' => $r('replay.delete_all', 'project', $p, meta: fn (Request $q, ?array $j) => ['deleted' => $j['deleted'] ?? null]),
            'POST api/projects/{project}/uptime-checks' => $r('uptime_check.create', 'uptime_check', 'response:data.id', 'input:name',
                meta: fn (Request $q) => ['project_id' => $q->route('project')?->id, 'url' => $q->input('url'), 'path' => $q->input('path'), 'locations' => $q->input('locations'),
                    'header_names' => array_map('strval', array_keys((array) $q->input('headers', [])))]),
            'PUT api/projects/{project}/uptime-checks/{check}' => $r('uptime_check.update', 'uptime_check', 'param:check',
                meta: fn (Request $q) => ['project_id' => $q->route('project')?->id, 'fields' => array_keys($q->except(['headers'])), 'header_names' => array_map('strval', array_keys((array) $q->input('headers', [])))]),
            'DELETE api/projects/{project}/uptime-checks/{check}' => $r('uptime_check.delete', 'uptime_check', 'param:check', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            'POST api/projects/{project}/uptime-checks/{check}/run' => $r('uptime_check.run', 'uptime_check', 'param:check', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            'PATCH api/projects/{project}/uptime-incidents/{incident}' => $r('uptime_incident.update', 'uptime_incident', 'param:incident', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id, 'fields' => array_keys($q->all())]),
            // Load tests (docs/load-test.md): the request body is named, not recorded
            'POST api/projects/{project}/load-tests' => $r('load_test.start', 'load_test', 'response:data.id',
                meta: fn (Request $q) => ['project_id' => $q->route('project')?->id, 'host' => $q->input('host'), 'method' => $q->input('method', 'GET'), 'path' => $q->input('path', '/'),
                    'users' => $q->input('users'), 'duration_seconds' => $q->input('duration_seconds'), 'has_body' => $q->filled('body')]),
            'POST api/projects/{project}/load-tests/{loadTest}/cancel' => $r('load_test.cancel', 'load_test', 'param:loadTest', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            'PUT api/observability/status-page' => $r('status_page.update', 'status_page', 'response:data.page.id', 'input:slug',
                meta: fn (Request $q) => $q->only(['slug', 'custom_domain', 'is_public', 'show_incident_history']) + ['components' => count((array) $q->input('components', []))]),
            'POST api/observability/alert-channels' => $r('alert_channel.create', 'alert_channel', 'response:data.id', 'input:name', meta: fn (Request $q) => ['type' => $q->input('type')]),
            'PUT api/observability/alert-channels/{channel}' => $r('alert_channel.update', 'alert_channel', 'param:channel',
                meta: fn (Request $q) => ['name' => $q->input('name'), 'is_enabled' => $q->input('is_enabled'), 'config_keys' => array_map('strval', array_keys((array) $q->input('config', [])))]),
            'DELETE api/observability/alert-channels/{channel}' => $r('alert_channel.delete', 'alert_channel', 'param:channel'),
            'POST api/observability/alert-channels/{channel}/test' => $r('alert_channel.test', 'alert_channel', 'param:channel', meta: fn (Request $q, ?array $j) => ['ok' => $j['data']['ok'] ?? null]),
            'POST api/observability/alert-rules' => $r('alert_rule.create', 'alert_rule', 'response:data.id', 'input:name',
                meta: fn (Request $q) => $q->only(['type', 'project_id', 'region_id', 'severity', 'cooldown_minutes', 'params', 'channel_ids', 'is_enabled'])),
            'PUT api/observability/alert-rules/{rule}' => $r('alert_rule.update', 'alert_rule', 'param:rule',
                meta: fn (Request $q) => $q->only(['severity', 'cooldown_minutes', 'params', 'channel_ids', 'is_enabled'])),
            'DELETE api/observability/alert-rules/{rule}' => $r('alert_rule.delete', 'alert_rule', 'param:rule'),
            'POST api/observability/alert-deliveries/{delivery}/retry' => $r('alert_delivery.retry', 'alert_delivery', 'param:delivery'),

            // ── Deployments & shell ──────────────────────────────────────────────
            'POST api/projects/{project}/deploy' => $r($deployAction, 'project', $p,
                meta: fn (Request $q, ?array $j) => ['version_id' => $q->input('version_id'), 'deployment_id' => $j['id'] ?? null, 'overrides' => array_keys((array) $q->input('deploy_config', []))]
                    + ($q->filled('strategy') ? ['strategy' => $q->input('strategy')] : [])
                    + (isset($j['rollout']['id']) ? ['rollout_id' => $j['rollout']['id'], 'rollout_strategy' => $j['rollout']['strategy'] ?? null] : [])
                    // An environment of the project; a rollback that restores the config (docs/environments.md, docs/versioning.md)
                    + ($q->filled('environment') ? ['environment' => mb_substr((string) $q->input('environment'), 0, 64)] : [])
                    + ($q->boolean('restore_config') ? ['restore_config' => true] : [])
                    + ($q->boolean('override_policy') ? ['policy_override' => true] : [])
                    // The version is built again for the region's registry first (VersionRebuilds)
                    + (isset($j['queued_deploy']['id']) ? ['queued_deploy_id' => $j['queued_deploy']['id']] : [])),
            'POST api/projects/{project}/versions/{version}/security/scan' => $r('security.scan.request', 'project', $p, meta: fn (Request $q) => ['version_id' => self::routeKey($q, 'version')]),
            'DELETE api/projects/{project}/deployments/{deployment}' => $r('deployment.delete', 'deployment', 'param:deployment', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            'POST api/projects/{project}/deployments/{deployment}/rollback' => $r('deployment.rollback', 'deployment', 'param:deployment',
                meta: fn (Request $q, ?array $j) => ['project_id' => $q->route('project')?->id, 'deployment_id' => $j['id'] ?? null, 'keep_current_config' => $q->boolean('keep_current_config')]),

            // ── Triggers and topics (docs/triggers.md) ──────────────────────────
            'POST api/projects/{project}/triggers' => $r('project.trigger.create', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['trigger' => $j['data']['name'] ?? $q->input('name'), 'type' => $q->input('type'), 'trigger_id' => $j['data']['id'] ?? null]),
            'PUT api/projects/{project}/triggers' => $r('project.trigger.replace', 'project', $p,
                meta: fn (Request $q) => ['triggers' => array_values(array_filter(array_map(fn ($t) => is_array($t) ? ($t['name'] ?? $t['type'] ?? null) : null, (array) $q->input('triggers', []))))]),
            'PATCH api/projects/{project}/triggers/{trigger}' => $r('project.trigger.update', 'project', $p,
                meta: fn (Request $q) => ['trigger' => $q->route('trigger'), 'fields' => array_keys($q->except(['secrets'])), 'secrets' => array_keys((array) $q->input('secrets', []))]),
            'DELETE api/projects/{project}/triggers/{trigger}' => $r('project.trigger.delete', 'project', $p, meta: fn (Request $q) => ['trigger' => $q->route('trigger')]),
            'POST api/projects/{project}/triggers/{trigger}/events/{event}/replay' => $r('project.trigger.event.replay', 'project', $p,
                meta: fn (Request $q) => ['trigger' => $q->route('trigger'), 'event_id' => $q->route('event')]),
            'POST api/projects/{project}/triggers/{trigger}/events/replay' => $r('project.trigger.events.replay', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['trigger' => $q->route('trigger'), 'status' => $q->input('status'), 'ids' => count((array) $q->input('ids', [])), 'replayed' => $j['replayed'] ?? null]),
            'POST api/topics' => $r('topic.create', 'topic', 'response:data.id', 'response:data.name',
                meta: fn (Request $q) => ['name' => $q->input('name'), 'region_id' => $q->input('knative_region_id')]),
            'PATCH api/topics/{topic}' => $r('topic.update', 'topic', 'param:topic', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'DELETE api/topics/{topic}' => $r('topic.delete', 'topic', 'param:topic'),
            'POST api/topics/{topic}/publish' => $r('topic.publish', 'topic', 'param:topic',
                meta: fn (Request $q, ?array $j) => ['type' => $q->input('type'), 'event_id' => $j['data']['id'] ?? null, 'stream_id' => $j['data']['stream_id'] ?? null]),

            // ── Internal network (docs/internal-network.md) ──────────────────────
            'PUT api/projects/{project}/network' => $r('project.network.update', 'project', $p,
                meta: fn (Request $q) => ['access_mode' => $q->input('access_mode'), 'allowed_cidrs' => count((array) $q->input('allowed_cidrs', []))]),
            'POST api/projects/{project}/network/callers' => $r('project.network.caller.add', 'project', $p,
                meta: fn (Request $q) => ['caller' => mb_substr((string) $q->input('caller'), 0, 120)]),
            'DELETE api/projects/{project}/network/callers/{caller}' => $r('project.network.caller.remove', 'project', $p,
                meta: fn (Request $q) => ['caller' => $q->route('caller')]),

            // ── Environments and promotion (routes/environments.php, docs/environments.md) ──
            'POST api/projects/{project}/environments' => $r('environment.create', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['environment' => $q->input('name'), 'environment_id' => $j['data']['id'] ?? null, 'region_id' => $q->input('knative_region_id'), 'overrides' => array_keys((array) $q->input('overrides', []))]),
            'PATCH api/projects/{project}/environments/{environment}' => $r('environment.update', 'project', $p,
                meta: fn (Request $q) => ['environment' => $q->route('environment'), 'fields' => array_keys($q->except(['overrides'])), 'overrides' => array_keys((array) $q->input('overrides', []))]),
            'DELETE api/projects/{project}/environments/{environment}' => $r('environment.delete', 'project', $p, meta: fn (Request $q) => ['environment' => $q->route('environment')]),
            'PUT api/projects/{project}/environments/{environment}/secrets/{name}' => $r('environment.secret.set', 'project', $p,
                meta: fn (Request $q) => ['environment' => $q->route('environment'), 'name' => $q->route('name')]),
            'DELETE api/projects/{project}/environments/{environment}/secrets/{name}' => $r('environment.secret.delete', 'project', $p,
                meta: fn (Request $q) => ['environment' => $q->route('environment'), 'name' => $q->route('name')]),
            'POST api/projects/{project}/environments/{environment}/promote' => $r('environment.promotion.request', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['from' => $q->route('environment'), 'to' => $j['data']['target'] ?? $q->input('to'), 'promotion_id' => $j['data']['id'] ?? null, 'version' => $j['data']['version']['number'] ?? null, 'status' => $j['data']['status'] ?? null]),
            'POST api/projects/{project}/promotions/{promotion}/approve' => $r('environment.promotion.approve', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['promotion_id' => self::routeKey($q, 'promotion'), 'to' => $j['data']['target'] ?? null, 'deployment_id' => $j['data']['deployment_id'] ?? null]),
            'POST api/projects/{project}/promotions/{promotion}/reject' => $r('environment.promotion.reject', 'project', $p,
                meta: fn (Request $q) => ['promotion_id' => self::routeKey($q, 'promotion')]),
            'POST api/projects/{project}/promotions/{promotion}/cancel' => $r('environment.promotion.cancel', 'project', $p,
                meta: fn (Request $q) => ['promotion_id' => self::routeKey($q, 'promotion')]),
            'PATCH api/projects/{project}/deployments/{deployment}/status' => $r('deployment.status.update', 'deployment', 'param:deployment', meta: fn (Request $q) => ['status' => $q->input('status'), 'project_id' => $q->route('project')?->id]),
            // Deploy strategies (docs/deploy-strategies.md): the settings, and a user's rollout actions
            'PUT api/projects/{project}/deploy-strategy' => $r('project.deploy_strategy.update', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['strategy' => $j['data']['strategy'] ?? $q->input('strategy'), 'fields' => array_keys($q->only(['strategy', 'blue_green', 'canary']))]),
            'POST api/projects/{project}/deployments/{deployment}/rollout/promote' => $r('deployment.rollout.promote', 'deployment', 'param:deployment',
                meta: fn (Request $q, ?array $j) => ['project_id' => $q->route('project')?->id, 'rollout_id' => $j['data']['id'] ?? null, 'strategy' => $j['data']['strategy'] ?? null]),
            'POST api/projects/{project}/deployments/{deployment}/rollout/abort' => $r('deployment.rollout.abort', 'deployment', 'param:deployment',
                meta: fn (Request $q, ?array $j) => ['project_id' => $q->route('project')?->id, 'rollout_id' => $j['data']['id'] ?? null, 'reason' => $q->input('reason')]),
            'POST api/projects/{project}/deployments/{deployment}/rollout/rollback' => $r('deployment.rollout.rollback', 'deployment', 'param:deployment',
                meta: fn (Request $q, ?array $j) => ['project_id' => $q->route('project')?->id, 'rollout_id' => $j['data']['id'] ?? null, 'reason' => $q->input('reason')]),
            'POST api/projects/{project}/deployments/{deployment}/exec' => $r('shell.session.request', 'deployment', 'param:deployment', meta: fn (Request $q, ?array $j) => ['project_id' => $q->route('project')?->id, 'session_id' => $j['session_id'] ?? null]),

            // ── Domains, private connections, gateways ───────────────────────────
            'POST api/projects/{project}/domains' => $r('domain.create', 'domain', 'response:data.id', 'input:domain', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            'POST api/projects/{project}/domains/{domain}/verify' => $r('domain.verify', 'domain', 'param:domain'),
            'PUT api/projects/{project}/domains/{domain}/tls' => $r('domain.tls.update', 'domain', 'param:domain', meta: fn (Request $q) => ['mode' => $q->input('mode')]),
            'DELETE api/projects/{project}/domains/{domain}' => $r('domain.delete', 'domain', 'param:domain'),
            'POST api/projects/{project}/private-connections' => $r('private_connection.create', 'project', $p,
                meta: fn (Request $q) => ['name' => $q->input('name'), 'routing_key' => $q->input('routing_key'), 'env_prefix' => $q->input('env_prefix'), 'environment' => $q->input('environment')]),
            'DELETE api/projects/{project}/private-connections/{privateConnection}' => $r('private_connection.delete', 'private_connection', 'param:privateConnection'),
            // Bindings (docs/bindings.md; routes/bindings.php) — never credential values
            'POST api/bindings' => $r('binding.create', 'binding', 'response:data.id', 'input:name',
                meta: fn (Request $q) => ['type' => $q->input('type'), 'plan' => $q->input('plan'), 'knative_region_id' => $q->input('knative_region_id'), 'settings' => (array) $q->input('settings', [])]),
            'PATCH api/bindings/{binding}' => $r('binding.update', 'binding', 'param:binding',
                meta: fn (Request $q) => ['settings' => (array) $q->input('settings', []), 'values_changed' => $q->has('values')]),
            'DELETE api/bindings/{binding}' => $r('binding.delete', 'binding', 'param:binding', meta: fn (Request $q, ?array $j) => ['purge_at' => $j['data']['purge_at'] ?? null]),
            'POST api/bindings/{binding}/restore' => $r('binding.restore', 'binding', 'param:binding'),
            'POST api/bindings/{binding}/rotate' => $r('binding.credentials.rotate', 'binding', 'param:binding'),
            'GET api/bindings/{binding}/credentials' => $r('binding.credentials.reveal', 'binding', 'param:binding'),
            'POST api/projects/{project}/bindings' => $r('binding.attach', 'project', $p,
                meta: fn (Request $q, ?array $j) => [
                    'binding_id' => $j['data']['binding_id'] ?? $q->input('binding_id'),
                    'binding' => $j['data']['binding']['name'] ?? $q->input('binding.name'),
                    'created' => $q->has('binding'),
                    'env_prefix' => $q->input('env_prefix'),
                    'credentials' => $j['data']['credential_mode'] ?? $q->input('credentials', 'static'),
                ]),
            'PATCH api/projects/{project}/bindings/{attachment}' => $r('binding.attachment.update', 'project', $p,
                meta: fn (Request $q) => ['attachment_id' => self::routeKey($q, 'attachment'), 'env_prefix' => $q->input('env_prefix')]
                    + ($q->has('credentials') ? ['credentials' => $q->input('credentials')] : [])),
            'DELETE api/projects/{project}/bindings/{attachment}' => $r('binding.detach', 'project', $p,
                meta: fn (Request $q) => ['attachment_id' => self::routeKey($q, 'attachment')]),
            'POST api/gateways' => $r('gateway.create', 'gateway', 'response:data.id', meta: fn (Request $q) => ['knative_region_id' => $q->input('knative_region_id')]),
            'PATCH api/gateways/{gateway}' => $r('gateway.update', 'gateway', 'param:gateway'),
            'POST api/gateways/{gateway}/routes' => $r('gateway.route.create', 'gateway', 'param:gateway', meta: fn (Request $q) => ['path' => $q->input('path'), 'project_id' => $q->input('project_id'), 'environment' => $q->input('environment'),
                'auth_mode' => $q->input('auth_mode', 'public'), 'gateway_only' => $q->boolean('gateway_only')]),
            'PATCH api/gateways/{gateway}/routes/{route}' => $r('gateway.route.update', 'gateway_route', 'param:route'),
            'DELETE api/gateways/{gateway}/routes/{route}' => $r('gateway.route.delete', 'gateway_route', 'param:route'),
            // API product (docs/api-product.md) — never a key: the audit keeps its id and prefix
            'PUT api/gateways/{gateway}/custom-domain' => $r('gateway.domain.request', 'gateway', 'param:gateway', meta: fn (Request $q) => ['domain' => $q->input('domain')]),
            'POST api/gateways/{gateway}/custom-domain/verify' => $r('gateway.domain.verify', 'gateway', 'param:gateway',
                meta: fn (Request $q, ?array $j) => ['domain' => $j['data']['custom_domain'] ?? null]),
            'DELETE api/gateways/{gateway}/custom-domain' => $r('gateway.domain.remove', 'gateway', 'param:gateway'),
            'POST api/api-consumers' => $r('api_consumer.create', 'api_consumer', 'response:data.id', 'input:name',
                meta: fn (Request $q) => $q->only(['gateway_id', 'plan_id', 'external_id', 'status'])),
            'PATCH api/api-consumers/{apiConsumer}' => $r('api_consumer.update', 'api_consumer', 'param:apiConsumer',
                meta: fn (Request $q) => ['fields' => array_keys($q->all())] + $q->only(['plan_id', 'status'])),
            'DELETE api/api-consumers/{apiConsumer}' => $r('api_consumer.delete', 'api_consumer', 'param:apiConsumer'),
            'POST api/api-consumers/{apiConsumer}/keys' => $r('api_key.create', 'api_key', 'response:data.id', 'input:name',
                meta: fn (Request $q, ?array $j) => ['consumer_id' => self::routeKey($q, 'apiConsumer'), 'prefix' => $j['data']['prefix'] ?? null]
                    + $q->only(['plan_id', 'expires_at']) + ['scoped' => is_array($q->input('scopes'))]),
            'PATCH api/api-keys/{apiKey}' => $r('api_key.update', 'api_key', 'param:apiKey', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'POST api/api-keys/{apiKey}/rotate' => $r('api_key.rotate', 'api_key', 'param:apiKey',
                meta: fn (Request $q, ?array $j) => ['new_key_id' => $j['data']['id'] ?? null, 'prefix' => $j['data']['prefix'] ?? null, 'overlap_minutes' => $q->input('overlap_minutes')]),
            'POST api/api-keys/{apiKey}/revoke' => $r('api_key.revoke', 'api_key', 'param:apiKey'),
            'POST api/api-plans' => $r('api_plan.create', 'api_plan', 'response:data.id', 'input:name',
                meta: fn (Request $q) => $q->only(['gateway_id', 'rate_per_second', 'burst', 'rate_per_minute', 'quota_per_day', 'quota_per_month'])),
            'PATCH api/api-plans/{apiPlan}' => $r('api_plan.update', 'api_plan', 'param:apiPlan',
                meta: fn (Request $q) => $q->only(['name', 'rate_per_second', 'burst', 'rate_per_minute', 'quota_per_day', 'quota_per_month'])),
            'DELETE api/api-plans/{apiPlan}' => $r('api_plan.delete', 'api_plan', 'param:apiPlan'),

            // ── Regions ──────────────────────────────────────────────────────────
            'POST api/knative-regions' => $r('region.create', 'region', 'response:data.id', 'input:name'),
            // apiResource parameter name
            'PUT|PATCH api/knative-regions/{knative_region}' => $r('region.update', 'region', 'param:knative_region'),
            'DELETE api/knative-regions/{knative_region}' => $r('region.delete', 'region', 'param:knative_region'),
            'GET api/knative-regions/{knativeRegion}/kubeconfig' => $r('region.kubeconfig.download', 'region', 'param:knativeRegion'),
            'PUT api/knative-regions/{knativeRegion}/settings' => $r('region.settings.update', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'PUT api/knative-regions/{knativeRegion}/capacity' => $r('region.capacity.update', 'region', 'param:knativeRegion', meta: fn (Request $q) => $q->only(['faas_cpu_overcommit', 'faas_memory_overcommit'])),
            'PUT api/knative-regions/{knativeRegion}/monitoring' => $r('region.monitoring.update', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'PUT api/knative-regions/{knativeRegion}/function-tls' => $r('region.tls.update', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['mode' => $q->input('mode')]),
            'DELETE api/knative-regions/{knativeRegion}/function-tls' => $r('region.tls.remove', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/skupper/site' => $r('region.skupper.site.ensure', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/install-knative' => $r('region.knative.install', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/uninstall-knative' => $r('region.knative.uninstall', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/install-operator' => $r('region.knative.operator.install', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/install-serving' => $r('region.knative.serving.install', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/upgrade-serving' => $r('region.knative.serving.upgrade', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['version' => $q->input('version')]),
            'POST api/knative-regions/{knativeRegion}/uninstall-operator' => $r('region.knative.operator.uninstall', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/uninstall-serving' => $r('region.knative.serving.uninstall', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/detect-components' => $r('region.components.detect', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/workers' => $r('region.workers.update', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/test-redis' => $r('region.redis.test', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/redis' => $r('region.redis.configure', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['mode' => $q->input('mode')]),
            'DELETE api/knative-regions/{knativeRegion}/redis' => $r('region.redis.remove', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/install-sandbox' => $r('region.sandbox.install', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['runtime' => $q->input('runtime')]),
            'POST api/knative-regions/{knativeRegion}/uninstall-sandbox' => $r('region.sandbox.uninstall', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['runtime' => $q->input('runtime')]),
            'PUT api/knative-regions/{knativeRegion}/sandbox-mapping' => $r('region.sandbox.mapping.update', 'region', 'param:knativeRegion'),
            // WebAssembly support (runwasi shims on the region's nodes)
            // Global DNS: a region's PowerDNS server, the zone sync (docs/global-projects.md)
            'POST api/knative-regions/{knativeRegion}/global-dns' => $r('region.global_dns.enable', 'region', 'param:knativeRegion'),
            'DELETE api/knative-regions/{knativeRegion}/global-dns' => $r('region.global_dns.disable', 'region', 'param:knativeRegion'),
            'PUT api/knative-regions/{knativeRegion}/global-dns/geoip' => $r('region.global_dns.geoip', 'region', 'param:knativeRegion', meta: fn (Request $q) => array_filter(['source' => $q->input('source'), 'url' => $q->input('url')], fn ($v) => $v !== null)),
            'POST api/knative-regions/{knativeRegion}/global-dns/geoip/reload' => $r('region.global_dns.geoip_reload', 'region', 'param:knativeRegion'),
            'POST api/global-dns/sync' => $r('global_dns.sync'),
            'POST api/knative-regions/{knativeRegion}/wasm' => $r('region.wasm.install', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['shims' => $q->input('shims'), 'nodes' => $q->input('nodes')]),
            'DELETE api/knative-regions/{knativeRegion}/wasm' => $r('region.wasm.uninstall', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/external-secrets' => $r('region.external_secrets.install', 'region', 'param:knativeRegion'),
            'DELETE api/knative-regions/{knativeRegion}/external-secrets' => $r('region.external_secrets.uninstall', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/openbao' => $r('region.openbao.install', 'region', 'param:knativeRegion'),
            'PATCH api/knative-regions/{knativeRegion}/openbao' => $r('region.openbao.update', 'region', 'param:knativeRegion',
                meta: fn (Request $q) => ['default_for_values' => (bool) $q->input('default_for_values')]),
            'DELETE api/knative-regions/{knativeRegion}/openbao' => $r('region.openbao.uninstall', 'region', 'param:knativeRegion'),
            // cert-manager and the ClusterIssuer of custom domains
            'POST api/knative-regions/{knativeRegion}/cert-manager' => $r('region.cert_manager.install', 'region', 'param:knativeRegion'),
            'DELETE api/knative-regions/{knativeRegion}/cert-manager' => $r('region.cert_manager.uninstall', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/migrate' => $r('region.migration.start', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/complete-migration' => $r('region.migration.complete', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/rollback-migration' => $r('region.migration.rollback', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/complete-domain-migration' => $r('region.migration.domain.complete', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/registry' => $r('region.registry.setup', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['mode' => $q->input('registry_mode')]),
            'DELETE api/knative-regions/{knativeRegion}/registry' => $r('region.registry.remove', 'region', 'param:knativeRegion'),
            // Data services of bindings (no credentials in the metadata)
            'POST api/knative-regions/{knativeRegion}/data-services/{service}' => $r('region.data_service.install', 'region', 'param:knativeRegion',
                meta: fn (Request $q) => ['service' => $q->route('service'), 'mode' => $q->input('mode'), 'provider' => $q->input('provider')]),
            'DELETE api/knative-regions/{knativeRegion}/data-services/{service}' => $r('region.data_service.uninstall', 'region', 'param:knativeRegion',
                meta: fn (Request $q) => ['service' => $q->route('service')]),
            'POST api/knative-regions/{knativeRegion}/demo-projects' => $r('region.demo.create', 'region', 'param:knativeRegion'),
            'DELETE api/knative-regions/{knativeRegion}/demo-projects' => $r('region.demo.remove', 'region', 'param:knativeRegion'),
            // Supply chain (routes/supply-chain.php, docs/supply-chain.md)
            'PUT api/knative-regions/{knativeRegion}/supply-chain' => $r('region.supply_chain.update', 'region', 'param:knativeRegion',
                meta: fn (Request $q) => ['fields' => array_keys($q->all())] + $q->only(['gate_mode', 'gate_max_critical', 'gate_max_high', 'signing_enabled', 'verify_on_deploy', 'admission_mode', 'caas_policy', 'scanner_db_mode', 'data_location'])),
            'POST api/knative-regions/{knativeRegion}/supply-chain/signing-key' => $r('region.signing_key.request', 'region', 'param:knativeRegion'),
            'DELETE api/knative-regions/{knativeRegion}/supply-chain/signing-key/retired' => $r('region.signing_key.retired_remove', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/supply-chain/scanner-db/refresh' => $r('region.scanner_db.refresh', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/supply-chain/admission' => $r('region.admission.install', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['mode' => $q->input('mode')]),
            'DELETE api/knative-regions/{knativeRegion}/supply-chain/admission' => $r('region.admission.remove', 'region', 'param:knativeRegion'),
            'POST api/registry-setup/start' => $r('registry.setup.start', 'region', null, 'input:name', meta: fn (Request $q) => ['storage_type' => $q->input('storage_type'), 'registry_mode' => $q->input('registry_mode')]),
            'POST api/registry-setup/{token}/retry' => $r('registry.setup.retry', 'region', null, 'input:name'),

            // ── Git deploy: Git tab, previews, Settings → Git providers (docs/git-deploy.md) ──
            // Names only for env overrides and credentials; tokens never reach the metadata
            'PUT api/projects/{project}/git' => $r('git.settings.update', 'project', $p,
                meta: fn (Request $q) => ['fields' => array_keys($q->all())]
                    + ($q->has('preview_env') ? ['preview_env' => array_map('strval', array_keys((array) $q->input('preview_env', [])))] : [])),
            'POST api/projects/{project}/git/redeploy' => $r('git.redeploy', 'project', $p, meta: fn (Request $q, ?array $j) => ['sha' => $j['data']['sha'] ?? null]),
            'POST api/projects/{project}/git/connect' => $r('git.repo.connect', 'project', $p, meta: fn (Request $q) => $q->only(['provider_id', 'repository', 'installation_id'])),
            'DELETE api/projects/{project}/git/connect' => $r('git.repo.disconnect', 'project', $p),
            'POST api/projects/{project}/previews/{preview}/redeploy' => $r('git.preview.redeploy', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['preview_id' => self::routeKey($q, 'preview'), 'number' => $j['data']['number'] ?? null]),
            'DELETE api/projects/{project}/previews/{preview}' => $r('git.preview.delete', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['preview_id' => self::routeKey($q, 'preview'), 'number' => $j['data']['number'] ?? null]),
            'POST api/git-providers' => $r('git_provider.create', 'git_provider', 'response:data.id', 'input:name', meta: fn (Request $q) => $q->only(['type', 'base_url'])),
            'PUT api/git-providers/{provider}' => $r('git_provider.update', 'git_provider', 'param:provider', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'DELETE api/git-providers/{provider}' => $r('git_provider.delete', 'git_provider', 'param:provider'),

            // ── Runtime plugins ──────────────────────────────────────────────────
            'POST api/runtimes' => $r('runtime.create', 'runtime', 'response:data.id', 'response:data.name'),
            'PUT api/runtimes/{runtime}' => $r('runtime.update', 'runtime', 'param:runtime'),
            'DELETE api/runtimes/{runtime}' => $r('runtime.delete', 'runtime', 'param:runtime'),
            'POST api/runtimes/{runtime}/builds' => $r('runtime.build', 'runtime', 'param:runtime', meta: fn (Request $q) => $q->only(['version', 'knative_region_id'])),
            'POST api/runtimes/{runtime}/test-deploy' => $r('runtime.test_deploy', 'runtime', 'param:runtime', meta: fn (Request $q) => $q->only(['version', 'knative_region_id'])),

            // ── Sandbox API (routes/sandboxes.php, docs/sandbox-api.md) ─────────────
            // Command output and file contents are never recorded: commands, paths and sizes only
            'POST api/sandboxes' => $r('sandbox.create', 'sandbox', 'response:data.id', 'response:data.id',
                meta: fn (Request $q, ?array $j) => [
                    'runtime' => $q->input('runtime'), 'version' => $q->input('version'), 'image' => $q->input('image'),
                    'region' => $q->input('region'), 'sandbox_class' => $j['data']['sandbox_class'] ?? $q->input('sandbox_class'),
                    'network' => $j['data']['network']['mode'] ?? null, 'env_keys' => array_keys((array) $q->input('env', [])),
                    'files' => count((array) $q->input('files', [])), 'from_pool' => $j['data']['from_pool'] ?? null,
                ]),
            'DELETE api/sandboxes/{sandbox}' => $r('sandbox.delete', 'sandbox', 'param:sandbox'),
            'POST api/sandboxes/{sandbox}/timeout' => $r('sandbox.timeout.update', 'sandbox', 'param:sandbox', meta: fn (Request $q) => ['ttl_seconds' => $q->input('ttl_seconds')]),
            'POST api/sandboxes/{sandbox}/pause' => $r('sandbox.pause', 'sandbox', 'param:sandbox'),
            'POST api/sandboxes/{sandbox}/resume' => $r('sandbox.resume', 'sandbox', 'param:sandbox'),
            'PUT api/sandboxes/{sandbox}/network' => $r('sandbox.network.update', 'sandbox', 'param:sandbox', meta: fn (Request $q) => ['network' => $q->input('network')]),
            'POST api/sandboxes/{sandbox}/exec' => $r('sandbox.exec', 'sandbox', 'param:sandbox', meta: fn (Request $q) => self::sandboxCommand($q) + ['stream' => $q->boolean('stream') || str_contains((string) $q->header('Accept'), 'text/event-stream')]),
            'POST api/sandboxes/{sandbox}/exec/ws' => $r('sandbox.exec', 'sandbox', 'param:sandbox', meta: fn (Request $q, ?array $j) => self::sandboxCommand($q) + ['via' => 'websocket', 'exec_id' => $j['data']['exec_id'] ?? null]),
            'POST api/sandboxes/{sandbox}/run' => $r('sandbox.run', 'sandbox', 'param:sandbox', meta: fn (Request $q) => ['language' => $q->input('language'), 'code_bytes' => strlen((string) $q->input('code'))]),
            'POST api/sandboxes/{sandbox}/terminal' => $r('sandbox.terminal.request', 'sandbox', 'param:sandbox', meta: fn (Request $q, ?array $j) => ['session_id' => $j['data']['session_id'] ?? null]),
            'POST api/sandboxes/{sandbox}/processes' => $r('sandbox.process.start', 'sandbox', 'param:sandbox', meta: fn (Request $q, ?array $j) => self::sandboxCommand($q) + ['process_id' => $j['data']['id'] ?? null]),
            'DELETE api/sandboxes/{sandbox}/processes/{process}' => $r('sandbox.process.kill', 'sandbox', 'param:sandbox', meta: fn (Request $q) => ['process_id' => self::routeKey($q, 'process'), 'signal' => $q->query('signal', 'TERM')]),
            'PUT api/sandboxes/{sandbox}/files/content' => $r('sandbox.file.upload', 'sandbox', 'param:sandbox', meta: fn (Request $q, ?array $j) => ['path' => $j['data']['path'] ?? $q->query('path'), 'bytes' => $j['data']['size'] ?? null]),
            'PUT api/sandboxes/{sandbox}/files/archive' => $r('sandbox.file.upload', 'sandbox', 'param:sandbox', meta: fn (Request $q, ?array $j) => ['path' => $j['data']['path'] ?? $q->query('path'), 'bytes' => $j['data']['bytes'] ?? null, 'archive' => true]),
            'GET api/sandboxes/{sandbox}/files/content' => $r('sandbox.file.download', 'sandbox', 'param:sandbox', meta: fn (Request $q) => ['path' => $q->query('path')]),
            'GET api/sandboxes/{sandbox}/files/archive' => $r('sandbox.file.download', 'sandbox', 'param:sandbox', meta: fn (Request $q) => ['path' => $q->query('path'), 'archive' => true]),
            'DELETE api/sandboxes/{sandbox}/files' => $r('sandbox.file.delete', 'sandbox', 'param:sandbox', meta: fn (Request $q) => ['path' => $q->query('path'), 'recursive' => $q->boolean('recursive')]),
            'POST api/sandboxes/{sandbox}/files/mkdir' => $r('sandbox.file.mkdir', 'sandbox', 'param:sandbox', meta: fn (Request $q) => ['path' => $q->input('path')]),
            'PUT api/sandbox-regions/{knativeRegion}' => $r('sandbox.region.update', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'POST api/sandbox-pools' => $r('sandbox.pool.create', 'sandbox_pool', 'response:data.id', null, meta: fn (Request $q) => $q->only(['region', 'runtime', 'version', 'size', 'sandbox_class'])),
            'PUT api/sandbox-pools/{pool}' => $r('sandbox.pool.update', 'sandbox_pool', 'param:pool', meta: fn (Request $q) => $q->only(['size', 'is_enabled'])),
            'DELETE api/sandbox-pools/{pool}' => $r('sandbox.pool.delete', 'sandbox_pool', 'param:pool'),
            'PUT api/sandbox-quotas/{tenant}' => $r('sandbox.quota.update', 'tenant', 'param:tenant', meta: fn (Request $q) => $q->only(['max_concurrent', 'max_cpu_m', 'max_memory_mb', 'max_sandbox_seconds_month', 'max_pool_size', 'allowed_classes'])),
            'DELETE api/sandbox-quotas/{tenant}' => $r('sandbox.quota.reset', 'tenant', 'param:tenant'),
            // ── AI: GPU support of regions, models, model services (docs/ai.md, docs/huggingface.md) ──
            'POST api/knative-regions/{knativeRegion}/gpu/install' => $r('region.gpu.install', 'region', 'param:knativeRegion', meta: fn (Request $q) => $q->only(['mode', 'time_slicing_replicas', 'runtime_class', 'operator_driver', 'operator_toolkit'])),
            'POST api/knative-regions/{knativeRegion}/gpu/uninstall' => $r('region.gpu.uninstall', 'region', 'param:knativeRegion'),
            'PUT api/knative-regions/{knativeRegion}/gpu/settings' => $r('region.gpu.settings.update', 'region', 'param:knativeRegion', meta: fn (Request $q) => $q->only(['time_slicing_replicas', 'runtime_class', 'gvisor_nvproxy'])),
            'PUT api/knative-regions/{knativeRegion}/model-storage' => $r('region.model_storage.update', 'region', 'param:knativeRegion', meta: fn (Request $q) => $q->only(['storage_class', 'size_gi', 'access_mode'])),
            'PUT api/models/hub-token' => $r('model.hub_token.set', 'hub_token', null, null, meta: fn (Request $q) => ['validated' => $q->boolean('validate')]),
            'DELETE api/models/hub-token' => $r('model.hub_token.remove', 'hub_token'),
            'POST api/models' => $r('model.create', 'model', 'response:id', 'response:name', meta: fn (Request $q) => $q->only(['source', 'hub_id', 'revision', 'task', 'knative_region_id'])),
            'PATCH api/models/{model}' => $r('model.update', 'model', 'param:model', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'DELETE api/models/{model}' => $r('model.delete', 'model', 'param:model'),
            'POST api/models/{model}/snapshots' => $r('model.snapshot.create', 'model', 'param:model', meta: fn (Request $q) => $q->only(['knative_region_id'])),
            'DELETE api/models/{model}/snapshots/{snapshot}' => $r('model.snapshot.delete', 'model_snapshot', 'param:snapshot', meta: fn (Request $q) => ['model_id' => self::routeKey($q, 'model')]),
            'POST api/models/{model}/uploads' => $r('model.upload.start', 'model', 'param:model', meta: fn (Request $q) => ['knative_region_id' => $q->input('knative_region_id'), 'files' => count((array) $q->input('files', []))]),
            'POST api/models/{model}/uploads/{upload}/complete' => $r('model.upload.complete', 'model', 'param:model', meta: fn (Request $q) => ['upload_id' => self::routeKey($q, 'upload')]),
            'DELETE api/models/{model}/uploads/{upload}' => $r('model.upload.abort', 'model', 'param:model', meta: fn (Request $q) => ['upload_id' => self::routeKey($q, 'upload')]),
            'POST api/model-services' => $r('model_service.create', 'model_service', 'response:id', 'response:name', meta: fn (Request $q) => $q->only(['ai_model_id', 'hub_id', 'knative_region_id', 'mode', 'device', 'engine', 'deploy'])),
            'PATCH api/model-services/{service}' => $r('model_service.update', 'model_service', 'param:service', meta: fn (Request $q) => ['fields' => array_keys($q->except(['env_vars']))]),
            'DELETE api/model-services/{service}' => $r('model_service.delete', 'model_service', 'param:service'),
            'POST api/model-services/{service}/deploy' => $r('model_service.deploy', 'model_service', 'param:service'),
            'POST api/model-services/{service}/stop' => $r('model_service.stop', 'model_service', 'param:service'),
            'POST api/projects/{project}/models' => $r('project.model.bind', 'project', $p, meta: fn (Request $q) => $q->only(['ai_model_service_id', 'alias', 'mount_weights'])),
            'PATCH api/projects/{project}/models/{modelBinding}' => $r('project.model.binding.update', 'project', $p, meta: fn (Request $q) => ['binding_id' => self::routeKey($q, 'modelBinding')] + $q->only(['alias', 'mount_weights'])),
            'DELETE api/projects/{project}/models/{modelBinding}' => $r('project.model.unbind', 'project', $p, meta: fn (Request $q) => ['binding_id' => self::routeKey($q, 'modelBinding')]),
            'PUT api/tenants/{tenant}/regions/{region}/gpu-quota' => $r('tenant.gpu_quota.update', 'tenant', 'param:tenant', meta: fn (Request $q) => ['region_id' => self::routeKey($q, 'region'), 'gpu_quota' => $q->input('gpu_quota')]),

            // ── Uploaded images (routes/images.php, docs/images.md); chunks are not audited ──
            'POST api/images/uploads' => $r('image.upload.start', 'image_upload', 'response:data.id', 'response:data.name',
                meta: fn (Request $q, ?array $j) => ['knative_region_id' => $q->input('knative_region_id'), 'filename' => $q->input('filename'), 'size' => $q->input('size'), 'format' => $j['data']['format'] ?? $q->input('format'), 'sha256' => $q->filled('sha256')]),
            'POST api/images/uploads/{upload}/complete' => $r('image.upload.complete', 'image_upload', 'param:upload', 'response:data.name', meta: fn (Request $q, ?array $j) => ['status' => $j['data']['status'] ?? null, 'size' => $j['data']['size_bytes'] ?? null]),
            'DELETE api/images/uploads/{upload}' => $r('image.upload.abort', 'image_upload', 'param:upload'),
            // ── Organization export / import (routes/org-transfer.php, docs/org-export.md); chunks
            // are not audited, the jobs' results are (EXPLICIT_ACTIONS) — never a passphrase or
            // S3 credentials ──
            'POST api/org-transfers/export/estimate' => $r('org.export.estimate', 'organization', meta: fn (Request $q) => ['include_secrets' => $q->boolean('include_secrets'), 'include_images' => $q->boolean('include_images')]),
            'POST api/org-transfers/export' => $r('org.export.start', 'org_transfer', 'response:data.id',
                meta: fn (Request $q) => [
                    'projects' => is_array($q->input('projects')) ? count($q->input('projects')) : 'all',
                    'include_members' => $q->boolean('include_members'),
                    'include_images' => $q->boolean('include_images'),
                    'binding_data' => $q->input('binding_data') === 'all' ? 'all' : count((array) $q->input('binding_data', [])),
                    'include_secrets' => $q->boolean('include_secrets'),
                    'include_history' => $q->boolean('include_history'),
                    'destination' => $q->input('destination.type', 'download'),
                ]),
            'POST api/org-transfers/import' => $r('org.import.start', 'org_transfer', 'response:data.id', 'response:data.package.filename',
                meta: fn (Request $q) => array_filter([
                    'source' => $q->input('source.type', 'upload'),
                    'size' => $q->input('size'),
                    'sha256' => $q->filled('sha256'),
                    'binding_id' => $q->input('source.binding_id'),
                    'bucket' => $q->input('source.bucket'),
                    'key' => $q->input('source.key'),
                ], fn ($v) => $v !== null)),
            'POST api/org-transfers/{transfer}/complete' => $r('org.import.uploaded', 'org_transfer', 'param:transfer'),
            'POST api/org-transfers/{transfer}/fetch' => $r('org.import.fetch', 'org_transfer', 'param:transfer', meta: fn (Request $q, ?array $j) => ['received_bytes' => $j['data']['upload']['uploaded_bytes'] ?? null]),
            'POST api/org-transfers/{transfer}/plan' => $r('org.import.plan', 'org_transfer', 'param:transfer', meta: fn (Request $q, ?array $j) => ['blocking' => $j['data']['plan']['blocking'] ?? null, 'region_map' => $q->input('region_map'), 'renames' => $q->input('renames'), 'passphrase_given' => $q->filled('passphrase')]),
            'POST api/org-transfers/{transfer}/apply' => $r('org.import.apply', 'org_transfer', 'param:transfer', meta: fn (Request $q) => ['region_map' => $q->input('region_map'), 'renames' => $q->input('renames'), 'restore_data' => $q->input('restore_data'), 'skip_binding_data' => $q->input('skip_binding_data'), 'history' => $q->input('history'), 'images' => $q->input('images'), 'invite_members' => $q->input('invite_members'), 'deploy' => $q->input('deploy'), 'passphrase_given' => $q->filled('passphrase')]),
            'DELETE api/org-transfers/{transfer}' => $r('org.transfer.cancel', 'org_transfer', 'param:transfer'),
            'DELETE api/images/{image}' => $r('image.delete', 'uploaded_image', 'param:image', 'response:name', meta: fn (Request $q, ?array $j) => ['registry_removed' => $j['registry_removed'] ?? null, 'usages' => $j['usages'] ?? null]),
            // ── AI agents (docs/mcp.md; routes/mcp-api.php) — never token or key values ──
            'DELETE api/mcp/clients/{client}' => $r('mcp.client.revoke', 'oauth_client', 'param:client', 'response:client.name',
                meta: fn (Request $q, ?array $j) => ['revoked' => $j['revoked'] ?? null]),
            'POST api/oauth2/requests/{id}/approve' => $r('oauth.consent.approve', 'oauth_client', 'response:client.id', 'response:client.name',
                meta: fn (Request $q) => ['request_id' => self::routeKey($q, 'id'), 'abilities' => (array) $q->input('abilities', []), 'expires_in_days' => $q->input('expires_in_days')]),
            'POST api/oauth2/requests/{id}/deny' => $r('oauth.consent.deny', 'oauth_client', 'response:client.id', 'response:client.name',
                meta: fn (Request $q) => ['request_id' => self::routeKey($q, 'id')]),
            'PUT api/projects/{project}/mcp-server' => $r(
                fn (Request $q, ?array $j) => ($j['created'] ?? false) ? 'mcp_server.create' : 'mcp_server.update',
                'project', $p, meta: fn (Request $q, ?array $j) => $q->only(['access_mode', 'path', 'audit_calls']) + ['handle' => $j['data']['handle'] ?? null],
            ),
            'DELETE api/projects/{project}/mcp-server' => $r('mcp_server.delete', 'project', $p),
            'POST api/projects/{project}/mcp-server/rotate-key' => $r('mcp_server.key.rotate', 'project', $p),
            'POST api/projects/{project}/mcp-server/inspector' => $r('mcp_server.inspect', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['method' => $j['method'] ?? null, 'name' => $j['name'] ?? null, 'status' => $j['status'] ?? null]),

            // ── Platform settings, users, demo ───────────────────────────────────
            'PATCH api/settings' => $r('settings.update', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'POST api/users' => $r('user.create', 'user', 'response:user.id', 'input:email', meta: fn (Request $q) => ['username' => $q->input('username'), 'is_admin' => $q->boolean('is_admin')]),
            'PATCH api/users/{user}' => $r('user.update', 'user', 'param:user', meta: fn (Request $q) => ['fields' => array_keys($q->except(['password', 'password_confirmation']))]),
            'DELETE api/users/{user}' => $r('user.delete', 'user', 'param:user'),
            'POST api/users/{user}/reset-2fa' => $r('user.2fa.reset', 'user', 'param:user'),
            'POST api/users/{user}/toggle-admin' => $r('user.admin.toggle', 'user', 'param:user'),
            'POST api/demo' => $r('demo.seed'),
            'DELETE api/demo' => $r('demo.remove'),

            // ── Roles, groups, members, project access (RBAC) ────────────────────
            'POST api/roles' => $r('role.create', 'role', 'response:data.id', 'input:name', meta: fn (Request $q) => ['permissions' => (array) $q->input('permissions', [])]),
            'PUT api/roles/default' => $r('role.default.update', 'role', 'input:role', 'input:role'),
            'PUT api/roles/{role}' => $r('role.update', 'role', 'param:role', meta: fn (Request $q) => ['permissions' => (array) $q->input('permissions', [])]),
            'DELETE api/roles/{role}' => $r('role.delete', 'role', 'param:role'),
            'POST api/groups' => $r('group.create', 'group', 'response:data.id', 'input:name'),
            'PUT api/groups/{group}' => $r('group.update', 'group', 'param:group'),
            'DELETE api/groups/{group}' => $r('group.delete', 'group', 'param:group'),
            'POST api/groups/{group}/members' => $r('group.member.add', 'group', 'param:group', meta: fn (Request $q) => ['user_ids' => (array) $q->input('user_ids', [])]),
            'DELETE api/groups/{group}/members/{user}' => $r('group.member.remove', 'group', 'param:group', meta: fn (Request $q) => ['user_id' => self::routeKey($q, 'user')]),
            'PUT api/members/{user}/role' => $r('member.role.update', 'user', 'param:user', meta: fn (Request $q) => ['role' => $q->input('role')]),
            'POST api/projects/{project}/access' => $r('project.access.grant', 'project', $p, meta: fn (Request $q) => $q->only(['subject_type', 'subject_id', 'role'])),
            'DELETE api/projects/{project}/access/{assignment}' => $r('project.access.revoke', 'project', $p, meta: fn (Request $q) => ['assignment_id' => self::routeKey($q, 'assignment')]),
            'POST api/sso/group-mappings' => $r('sso.group_mapping.create', 'sso_group_mapping', 'response:data.id', 'input:external_group', meta: fn (Request $q) => $q->only(['auth_provider_id', 'external_group', 'group_id', 'role'])),
            'PUT api/sso/group-mappings/{mapping}' => $r('sso.group_mapping.update', 'sso_group_mapping', 'param:mapping'),
            'DELETE api/sso/group-mappings/{mapping}' => $r('sso.group_mapping.delete', 'sso_group_mapping', 'param:mapping'),

            // ── Audit log ────────────────────────────────────────────────────────
            'GET api/audit-events/export' => $r('audit.export', meta: fn (Request $q) => ['format' => $q->query('format', 'csv'), 'filters' => Arr::except($q->query(), ['format'])]),
            'PUT api/audit-settings' => $r('audit.settings.update', meta: fn (Request $q) => ['retention_days' => $q->input('retention_days')]),

            // ── Horizon dashboard: a single-use sign-in link (the link itself is never recorded) ──
            'POST api/horizon/link' => $r('horizon.link.create', meta: fn (Request $q, ?array $j) => ['expires_at' => $j['expires_at'] ?? null]),

            // ── Tenants, members, impersonation (Tenancy module) ─────────────────
            'POST api/tenants' => $r('tenant.create', 'tenant', 'response:id', 'input:name'),
            'PATCH api/tenants/{tenant}' => $r('tenant.update', 'tenant', 'param:tenant'),
            'DELETE api/tenants/{tenant}' => $r('tenant.delete', 'tenant', 'param:tenant'),
            'POST api/tenants/{tenant}/regions' => $r('tenant.region.assign', 'tenant', 'param:tenant', meta: fn (Request $q) => ['region_id' => $q->input('knative_region_id', $q->input('region_id'))]),
            'PUT api/tenants/{tenant}/binding-quotas' => $r('tenant.binding_quota.update', 'tenant', 'param:tenant',
                meta: fn (Request $q) => $q->only(['max_bindings', 'kv_mb', 'object_gb', 'postgres_gb', 'dedicated_postgres'])),
            'PATCH api/tenants/{tenant}/regions/{region}' => $r('tenant.region.quota.update', 'tenant', 'param:tenant', meta: fn (Request $q) => ['region_id' => $q->route('region')] + $q->only(['cpu_quota_m', 'memory_quota_mb'])),
            'DELETE api/tenants/{tenant}/regions/{region}' => $r('tenant.region.remove', 'tenant', 'param:tenant', meta: fn (Request $q) => ['region_id' => $q->route('region')]),
            'PUT api/tenants/{tenant}/security-policy' => $r('tenant.residency.update', 'tenant', 'param:tenant',
                meta: fn (Request $q) => $q->only(['residency_enforced', 'allowed_region_ids', 'allowed_locations', 'default_region_id', 'gate_mode', 'gate_max_critical', 'gate_max_high', 'gate_require_scan'])),
            'POST api/tenants/{tenant}/users' => $r(
                fn (Request $q, ?array $j) => ($j['action'] ?? null) === 'added' ? 'member.add' : 'member.invite',
                'tenant', 'param:tenant', meta: fn (Request $q) => ['email' => $q->input('email'), 'role' => $q->input('role', 'member')],
            ),
            'PATCH api/tenants/{tenant}/users/{user}' => $r('member.update', 'tenant', 'param:tenant', meta: fn (Request $q) => ['user_id' => $q->route('user'), 'role' => $q->input('role')]),
            'DELETE api/tenants/{tenant}/users/{user}' => $r('member.remove', 'tenant', 'param:tenant', meta: fn (Request $q) => ['user_id' => $q->route('user')]),
            'POST api/tenants/{tenant}/users/{user}/reset-2fa' => $r('member.2fa.reset', 'tenant', 'param:tenant', meta: fn (Request $q) => ['user_id' => $q->route('user')]),
            'POST api/tenants/{tenant}/invitations/{invitation}/resend' => $r('invitation.resend', 'invitation', 'param:invitation'),
            'DELETE api/tenants/{tenant}/invitations/{invitation}' => $r('invitation.cancel', 'invitation', 'param:invitation'),
            'POST api/invitations/{token}/accept' => $r('invitation.accept', actor: 'response:user.id'),
            'POST api/tenant/switch' => $r('tenant.switch', 'tenant', 'input:tenant_id'),
            'POST api/impersonate/{tenant}' => $r('impersonation.start', 'tenant', 'param:tenant'),
            'POST api/impersonate/stop' => $r('impersonation.stop', 'tenant', 'response:tenant_id'),

            // ── Billing (Billing module, docs/billing.md) ─────────────────────────
            // Settings payloads carry the webhook secret: field names only
            'PUT api/billing/settings' => $r('billing.settings.update', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'POST api/billing/prices' => $r('billing.price.create', 'billing_price', 'response:data.id',
                meta: fn (Request $q) => $q->only(['region_id', 'effective_from'])),
            'DELETE api/billing/prices/{price}' => $r('billing.price.delete', 'billing_price', 'param:price'),
            'PUT api/billing/regions/{region}' => $r('billing.region.update', 'region', 'param:region', meta: fn (Request $q) => $q->only(['billing_type', 'charge_queue_consumers'])),
            'PUT api/billing/regions/{region}/queue-consumers' => $r('billing.region.queue_consumers.update', 'region', 'param:region', meta: fn (Request $q) => $q->only(['charge'])),
            'PUT api/billing/regions/{region}/organizations/{tenant}' => $r('billing.region.organization.update', 'region', 'param:region',
                meta: fn (Request $q) => ['tenant' => $q->route('tenant'), 'charge' => $q->input('charge')]),
            'POST api/billing/markups' => $r('billing.markup.create', 'billing_markup', 'response:data.id',
                meta: fn (Request $q) => $q->only(['region_id', 'percent', 'effective_from'])),
            'DELETE api/billing/markups/{markup}' => $r('billing.markup.delete', 'billing_markup', 'param:markup'),
            'POST api/billing/region-offers/{region}/accept' => $r('billing.region_offer.accept', 'region', 'param:region', meta: fn (Request $q) => $q->only(['markup_percent'])),
            'PUT api/billing/bills/{bill}/payment-url' => $r('billing.bill.payment_url.update', 'bill', 'param:bill', meta: fn (Request $q) => ['payment_url' => $q->input('payment_url')]),
            'PUT api/billing/bills/{bill}/paid' => $r('billing.bill.paid.update', 'bill', 'param:bill', meta: fn (Request $q) => $q->only(['paid', 'paid_at', 'payment_reference'])),
            'PUT api/billing/tenant-bills/{bill}/payment-url' => $r('billing.tenant_bill.payment_url.update', 'bill', 'param:bill', meta: fn (Request $q) => ['payment_url' => $q->input('payment_url')]),
            'PUT api/billing/tenant-bills/{bill}/paid' => $r('billing.tenant_bill.paid.update', 'bill', 'param:bill', meta: fn (Request $q) => $q->only(['paid', 'paid_at', 'payment_reference'])),
            'POST api/billing/fixed-requests/{fixedRequest}/approve' => $r('billing.fixed_request.approve', 'fixed_request', 'param:fixedRequest', meta: fn (Request $q) => $q->only(['note'])),
            'POST api/billing/fixed-requests/{fixedRequest}/reject' => $r('billing.fixed_request.reject', 'fixed_request', 'param:fixedRequest', meta: fn (Request $q) => $q->only(['reason'])),
            'POST api/projects/{project}/billing/fixed-requests' => $r('billing.fixed_request.create', 'fixed_request', 'response:data.id',
                meta: fn (Request $q) => ['project_id' => $q->route('project')?->id] + $q->only(['cpu_m', 'memory_mb', 'gpu_count'])),
            'POST api/projects/{project}/billing/fixed-requests/{fixedRequest}/cancel' => $r('billing.fixed_request.cancel', 'fixed_request', 'param:fixedRequest',
                meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            // The monthly cost limit of a project (its 100 % notice and stop: billing.cost_limit.reached, project.stop by the system)
            'PUT api/projects/{project}/billing/cost-limit' => $r('billing.cost_limit.update', 'project', 'param:project', meta: fn (Request $q) => $q->only(['amount', 'action'])),
            'DELETE api/projects/{project}/billing/cost-limit' => $r('billing.cost_limit.delete', 'project', 'param:project'),
            'POST api/projects/{project}/billing/cost-limit/resume' => $r('billing.cost_limit.resume', 'project', 'param:project',
                meta: fn (Request $q, ?array $j) => ['deployment_id' => $j['deployment_id'] ?? null]),
            'POST api/billing/events/test' => $r('billing.event.test'),
            'POST api/billing/events/{event}/redeliver' => $r('billing.event.redeliver', meta: fn (Request $q) => ['event_id' => self::routeKey($q, 'event')]),

            // ── White-label: branding, signup ────────────────────────────────────
            // Settings payloads carry secrets (CAPTCHA secret): field names only
            'PUT api/admin/branding' => $r('branding.update', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'POST api/admin/branding/assets/{kind}' => $r('branding.asset.upload', meta: fn (Request $q) => ['kind' => $q->route('kind')]),
            'DELETE api/admin/branding/assets/{kind}' => $r('branding.asset.delete', meta: fn (Request $q) => ['kind' => $q->route('kind')]),
            'PUT api/admin/signup-settings' => $r('signup.settings.update', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'PUT api/tenant/branding' => $r('tenant.branding.update', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'POST api/tenant/branding/assets/{kind}' => $r('tenant.branding.asset.upload', meta: fn (Request $q) => ['kind' => $q->route('kind')]),
            'DELETE api/tenant/branding/assets/{kind}' => $r('tenant.branding.asset.delete', meta: fn (Request $q) => ['kind' => $q->route('kind')]),
            'POST api/onboarding' => $r('tenant.onboarding.complete', meta: fn (Request $q) => $q->only(['region_id'])),

            // ── SSO providers (AuthProvider module) ──────────────────────────────
            'POST api/auth-providers' => $r('auth_provider.create', 'auth_provider', 'response:id', 'input:name', meta: fn (Request $q) => ['driver' => $q->input('driver')]),
            'PUT api/auth-providers/{authProvider}' => $r('auth_provider.update', 'auth_provider', 'param:authProvider'),
            'DELETE api/auth-providers/{authProvider}' => $r('auth_provider.delete', 'auth_provider', 'param:authProvider'),
            'PATCH api/auth-providers/{authProvider}/toggle' => $r('auth_provider.toggle', 'auth_provider', 'param:authProvider'),

            // ── Support tickets, admin side (Ticket module) ──────────────────────
            'PATCH api/admin/support-tickets/{id}/status' => $r('ticket.status.update', 'ticket', 'param:id', meta: fn (Request $q) => ['status' => $q->input('status')]),
            'PATCH api/admin/support-tickets/{id}/assign' => $r('ticket.assign', 'ticket', 'param:id', meta: fn (Request $q) => ['assigned_to' => $q->input('assigned_to', $q->input('user_id'))]),
            'PATCH api/admin/support-tickets/{id}/tags' => $r('ticket.tags.update', 'ticket', 'param:id', meta: fn (Request $q) => ['tags' => (array) $q->input('tags', [])]),
            'POST api/admin/support-tickets/{id}/replies' => $r('ticket.admin_reply', 'ticket', 'param:id'),
        ];
    }
}
