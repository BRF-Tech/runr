<?php

namespace App\Services\Audit;

use App\Mcp\Models\HostedServer;
use App\Mcp\Models\OAuthClient;
use App\Models\AiHubCredential;
use App\Models\AiModel;
use App\Models\AiModelBinding;
use App\Models\AiModelService;
use App\Models\AiModelSnapshot;
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
use App\Models\ProjectSecret;
use App\Models\RegionSupplyChainSetting;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Runtime;
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
        // AI agents (docs/mcp.md, McpAudit): MCP sessions, every tools/call, the OAuth server's
        // registrations and tokens, calls through the hosted-server gateway (when audited)
        'mcp.session.start',
        'mcp.session.end',
        'mcp.tool.call',
        'mcp.server.call',
        'oauth.client.register',
        'oauth.token.issue',
        'oauth.token.revoke',
    ];

    /** Models whose changes during an audited request are recorded (AuditChanges) => target type. */
    private const MODEL_TYPES = [
        Project::class => 'project',
        XaasConfig::class => 'project_config',
        FaasConfig::class => 'project_config',
        ContainerConfig::class => 'project_config',
        ProjectSecret::class => 'project_secret',
        Workflow::class => 'workflow',
        WorkflowApp::class => 'workflow_app',
        ProjectDomain::class => 'domain',
        Gateway::class => 'gateway',
        GatewayRoute::class => 'gateway_route',
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
        'Modules\\Tenancy\\Models\\Plan' => 'plan',
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
        'Modules\\Tenancy\\Models\\CostConfig' => 'cost_config',
        'Modules\\Tenancy\\Models\\CapacityDeal' => 'capacity_deal',
        'Modules\\Tenancy\\Models\\Charge' => 'charge',
        'Modules\\Tenancy\\Models\\Purchase' => 'purchase',
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
            'DELETE api/social-accounts/{socialAccount}' => $r('social_account.unlink', 'social_account', 'param:socialAccount'),

            // ── Projects ─────────────────────────────────────────────────────────
            'POST api/projects' => $r('project.create', 'project', 'response:id', 'response:name',
                meta: fn (Request $q) => ['service_type' => $q->input('service_type'), 'runtime' => $q->input('runtime'), 'knative_region_id' => $q->input('knative_region_id')]),
            'PUT|PATCH api/projects/{project}' => $r('project.update', 'project', $p),
            'DELETE api/projects/{project}' => $r('project.delete', 'project', $p),
            'POST api/projects/{project}/code' => $r('project.code.save', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['version' => $j['number'] ?? $j['data']['number'] ?? null, 'message' => $q->input('message')]),
            'POST api/projects/{project}/files' => $r('project.code.save', 'project', $p,
                meta: fn (Request $q, ?array $j) => ['files' => array_map('strval', array_keys((array) $q->input('files', []))), 'version' => $j['number'] ?? $j['data']['number'] ?? null]),
            'PUT api/projects/{project}/secrets/{name}' => $r('project.secret.set', 'project', $p, meta: fn (Request $q) => ['name' => $q->route('name')]),
            'DELETE api/projects/{project}/secrets/{name}' => $r('project.secret.delete', 'project', $p, meta: fn (Request $q) => ['name' => $q->route('name')]),
            'GET api/projects/{project}/secrets/{name}/value' => $r('project.secret.reveal', 'project', $p, meta: fn (Request $q) => ['name' => $q->route('name')]),

            // ── Durable workflows (routes/workflows.php) ─────────────────────────
            'PUT api/projects/{project}/workflows' => $r('workflow.declare', 'project', $p),
            'POST api/projects/{project}/workflows/sync' => $r('workflow.sync', 'project', $p),
            'PATCH api/projects/{project}/workflows/app' => $r('workflow.app.update', 'project', $p, meta: fn (Request $q) => ['endpoint_url' => $q->input('endpoint_url')]),
            'POST api/projects/{project}/workflows/app/rotate-key' => $r('workflow.signing_key.rotate', 'project', $p),
            'GET api/projects/{project}/workflows/app/signing-key' => $r('workflow.signing_key.reveal', 'project', $p),
            'PATCH api/projects/{project}/workflows/{workflow}' => $r('workflow.update', 'project', $p,
                meta: fn (Request $q) => ['workflow' => self::routeKey($q, 'workflow'), 'fields' => array_keys($q->all())]),
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
                    + ($q->boolean('override_policy') ? ['policy_override' => true] : [])),
            'POST api/projects/{project}/versions/{version}/security/scan' => $r('security.scan.request', 'project', $p, meta: fn (Request $q) => ['version_id' => self::routeKey($q, 'version')]),
            'DELETE api/projects/{project}/deployments/{deployment}' => $r('deployment.delete', 'deployment', 'param:deployment', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            'PATCH api/projects/{project}/deployments/{deployment}/status' => $r('deployment.status.update', 'deployment', 'param:deployment', meta: fn (Request $q) => ['status' => $q->input('status'), 'project_id' => $q->route('project')?->id]),
            'POST api/projects/{project}/deployments/{deployment}/exec' => $r('shell.session.request', 'deployment', 'param:deployment', meta: fn (Request $q, ?array $j) => ['project_id' => $q->route('project')?->id, 'session_id' => $j['session_id'] ?? null]),

            // ── Domains, private connections, gateways ───────────────────────────
            'POST api/projects/{project}/domains' => $r('domain.create', 'domain', 'response:data.id', 'input:domain', meta: fn (Request $q) => ['project_id' => $q->route('project')?->id]),
            'POST api/projects/{project}/domains/{domain}/verify' => $r('domain.verify', 'domain', 'param:domain'),
            'PUT api/projects/{project}/domains/{domain}/tls' => $r('domain.tls.update', 'domain', 'param:domain', meta: fn (Request $q) => ['mode' => $q->input('mode')]),
            'DELETE api/projects/{project}/domains/{domain}' => $r('domain.delete', 'domain', 'param:domain'),
            'POST api/projects/{project}/private-connections' => $r('private_connection.create', 'project', $p,
                meta: fn (Request $q) => ['name' => $q->input('name'), 'routing_key' => $q->input('routing_key'), 'env_prefix' => $q->input('env_prefix')]),
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
                ]),
            'PATCH api/projects/{project}/bindings/{attachment}' => $r('binding.attachment.update', 'project', $p,
                meta: fn (Request $q) => ['attachment_id' => self::routeKey($q, 'attachment'), 'env_prefix' => $q->input('env_prefix')]),
            'DELETE api/projects/{project}/bindings/{attachment}' => $r('binding.detach', 'project', $p,
                meta: fn (Request $q) => ['attachment_id' => self::routeKey($q, 'attachment')]),
            'PATCH api/gateways/{gateway}' => $r('gateway.update', 'gateway', 'param:gateway'),
            'POST api/gateways/{gateway}/routes' => $r('gateway.route.create', 'gateway', 'param:gateway', meta: fn (Request $q) => ['path' => $q->input('path'), 'project_id' => $q->input('project_id')]),
            'PATCH api/gateways/{gateway}/routes/{route}' => $r('gateway.route.update', 'gateway_route', 'param:route'),
            'DELETE api/gateways/{gateway}/routes/{route}' => $r('gateway.route.delete', 'gateway_route', 'param:route'),

            // ── Regions ──────────────────────────────────────────────────────────
            'POST api/knative-regions' => $r('region.create', 'region', 'response:data.id', 'input:name'),
            // apiResource parameter name
            'PUT|PATCH api/knative-regions/{knative_region}' => $r('region.update', 'region', 'param:knative_region'),
            'DELETE api/knative-regions/{knative_region}' => $r('region.delete', 'region', 'param:knative_region'),
            'GET api/knative-regions/{knativeRegion}/kubeconfig' => $r('region.kubeconfig.download', 'region', 'param:knativeRegion'),
            'PUT api/knative-regions/{knativeRegion}/settings' => $r('region.settings.update', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'PUT api/knative-regions/{knativeRegion}/function-tls' => $r('region.tls.update', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['mode' => $q->input('mode')]),
            'POST api/knative-regions/{knativeRegion}/skupper/site' => $r('region.skupper.site.ensure', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/install-knative' => $r('region.knative.install', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/uninstall-knative' => $r('region.knative.uninstall', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/install-operator' => $r('region.knative.operator.install', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/install-serving' => $r('region.knative.serving.install', 'region', 'param:knativeRegion'),
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
            'POST api/knative-regions/{knativeRegion}/wasm' => $r('region.wasm.install', 'region', 'param:knativeRegion', meta: fn (Request $q) => ['shims' => $q->input('shims'), 'nodes' => $q->input('nodes')]),
            'DELETE api/knative-regions/{knativeRegion}/wasm' => $r('region.wasm.uninstall', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/migrate' => $r('region.migration.start', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/complete-migration' => $r('region.migration.complete', 'region', 'param:knativeRegion'),
            'POST api/knative-regions/{knativeRegion}/rollback-migration' => $r('region.migration.rollback', 'region', 'param:knativeRegion'),
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

            // ── Billing (Tenancy module) ─────────────────────────────────────────
            'POST api/cost-configs' => $r('billing.cost_config.create', 'cost_config', 'response:id'),
            'PATCH api/cost-configs/{id}' => $r('billing.cost_config.update', 'cost_config', 'param:id'),
            'DELETE api/cost-configs/{id}' => $r('billing.cost_config.delete', 'cost_config', 'param:id'),
            'POST api/tenants/{tenant}/capacity-deals' => $r('billing.capacity_deal.create', 'tenant', 'param:tenant'),
            'PATCH api/tenants/{tenant}/capacity-deals/{deal}' => $r('billing.capacity_deal.update', 'tenant', 'param:tenant', meta: fn (Request $q) => ['deal_id' => self::routeKey($q, 'deal')]),
            // Billing API (docs/billing.md): the operator's billing system
            'PUT api/billing/tenants/{tenant}/subscription' => $r('billing.subscription.update', 'tenant', 'param:tenant', meta: fn (Request $q) => $q->only(['plan', 'interval', 'external_ref', 'cancel_at_period_end'])),
            'POST api/billing/tenants/{tenant}/charges' => $r('billing.charge.create', 'charge', 'response:charge.id', meta: fn (Request $q) => ['tenant' => self::routeKey($q, 'tenant')] + $q->only(['amount', 'currency', 'period_start', 'period_end'])),
            'PATCH api/billing/charges/{charge}' => $r('billing.charge.update', 'charge', 'param:charge', meta: fn (Request $q) => $q->only(['external_ref'])),
            'POST api/billing/charges/{charge}/void' => $r('billing.charge.void', 'charge', 'param:charge', meta: fn (Request $q) => $q->only(['reason'])),
            'POST api/billing/tenants/{tenant}/purchases' => $r('billing.purchase.record', 'purchase', 'response:purchase.id', meta: fn (Request $q) => ['tenant' => self::routeKey($q, 'tenant')] + $q->only(['kind', 'external_ref', 'amount', 'currency', 'plan', 'interval'])),
            'POST api/billing/purchases/{purchase}/void' => $r('billing.purchase.void', 'purchase', 'param:purchase'),
            'POST api/billing/events/test' => $r('billing.event.test'),
            'POST api/billing/events/{event}/redeliver' => $r('billing.event.redeliver', meta: fn (Request $q) => ['event_id' => self::routeKey($q, 'event')]),

            // ── White-label: branding, signup, plans ─────────────────────────────
            // Settings payloads carry secrets (webhook secret, CAPTCHA secret): field names only
            'PUT api/admin/branding' => $r('branding.update', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'POST api/admin/branding/assets/{kind}' => $r('branding.asset.upload', meta: fn (Request $q) => ['kind' => $q->route('kind')]),
            'DELETE api/admin/branding/assets/{kind}' => $r('branding.asset.delete', meta: fn (Request $q) => ['kind' => $q->route('kind')]),
            'PUT api/admin/signup-settings' => $r('signup.settings.update', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'PUT api/tenant/branding' => $r('tenant.branding.update', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'POST api/tenant/branding/assets/{kind}' => $r('tenant.branding.asset.upload', meta: fn (Request $q) => ['kind' => $q->route('kind')]),
            'DELETE api/tenant/branding/assets/{kind}' => $r('tenant.branding.asset.delete', meta: fn (Request $q) => ['kind' => $q->route('kind')]),
            'POST api/onboarding' => $r('tenant.onboarding.complete', meta: fn (Request $q) => $q->only(['region_id'])),
            'POST api/admin/plans' => $r('plan.create', 'plan', 'response:id', 'response:name', meta: fn (Request $q) => $q->only(['slug'])),
            'PATCH api/admin/plans/{plan}' => $r('plan.update', 'plan', 'param:plan', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),
            'DELETE api/admin/plans/{plan}' => $r('plan.delete', 'plan', 'param:plan'),
            'PUT api/admin/billing-settings' => $r('billing.settings.update', meta: fn (Request $q) => ['fields' => array_keys($q->all())]),

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
