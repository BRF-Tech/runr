<?php

namespace Tests\Feature;

use App\Services\Audit\AuditCatalog;
use Illuminate\Support\Facades\Route as Router;
use Modules\Tenancy\Http\Middleware\RequireAdmin;
use Tests\TestCase;

/**
 * Every API route that changes something is audited (AuditCatalog) — or listed here with the
 * reason it is not. A new route has to choose one of the two.
 */
class AuditCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs with the modules off: register their routes the way their providers do
        // (as Tests\Feature\Rbac\RouteAuthorizationTest does)
        $this->app['router']->aliasMiddleware('tenancy.admin', RequireAdmin::class);
        Router::middleware(['api', 'auth:sanctum'])->prefix('api')->group(base_path('Modules/Tenancy/routes/api.php'));
        Router::middleware('api')->prefix('api')->group(base_path('Modules/Tenancy/routes/public.php'));
        Router::middleware(['api', 'auth:sanctum'])->prefix('api')->group(base_path('Modules/Tenancy/routes/white-label.php'));
        Router::prefix('api')->group(base_path('Modules/Billing/routes/api.php'));
        Router::middleware(['api', 'auth:sanctum'])->prefix('api')->group(base_path('Modules/AuthProvider/routes/api.php'));
        Router::middleware('api')->prefix('api')->group(base_path('Modules/AuthProvider/routes/public.php'));
        Router::prefix('api')->group(base_path('Modules/Ticket/routes/api.php'));
        Router::getRoutes()->refreshNameLookups();
        Router::getRoutes()->refreshActionLookups();
    }

    /** "METHOD uri" => why the route is not in the audit catalog */
    private const UNAUDITED = [
        'DELETE api/notifications' => 'own notification inbox',
        'DELETE api/notifications/{id}' => 'own notification inbox',
        'PATCH api/notifications/{id}/read' => 'own notification inbox',
        'POST api/notifications/read-all' => 'own notification inbox',
        'POST api/contact' => 'contact form, sends a mail',
        'POST api/tutorial/complete' => 'UI onboarding flag',
        'POST api/knative-regions/check-namespace-preview' => 'read-only preview of a kubeconfig',
        'POST api/knative-regions/preview-nodes' => 'read-only preview of a kubeconfig',
        'POST api/knative-regions/preview-storage-classes' => 'read-only preview of a kubeconfig',
        'POST api/knative-regions/validate-kubeconfig' => 'read-only preview of a kubeconfig',
        'POST api/registry-setup/test-external' => 'connectivity test, changes nothing',
        'POST api/registry-setup/test-pvc' => 'connectivity test, changes nothing',
        'POST api/registry-setup/test-s3' => 'connectivity test, changes nothing',
        'POST api/tenants/{tenant}/users/search' => 'lookup by e-mail, changes nothing',
        'POST api/runtimes/validate' => 'manifest check, changes nothing',
        'POST api/events' => 'workflow event ingestion from functions; stored in workflow_events',
        'POST api/internal/workflows/queue/{context}/{projectId}/{token}' => 'queue bridge delivery (signed path token); stored in workflow_events',
        'POST api/broadcasting/auth' => 'channel subscription; a refusal is recorded as authorization.denied',
        'POST api/oauth/exchange' => 'one-time sign-in code; the sign-in itself is auth.sso.login',
        'POST api/saml/{provider}/acs' => 'recorded by SamlController (auth.sso.login)',
        'POST api/support-tickets' => 'end-user ticket, not an admin action',
        'POST api/support-tickets/{supportTicket}/replies' => 'end-user ticket, not an admin action',
        'POST api/support-tickets/{supportTicket}/read' => 'read marker',
        'POST api/admin/support-tickets/{id}/read' => 'read marker',
        'POST api/internal/observability/replay/{key}' => 'failed-request capture posted by function pods (per-project token); stored as a replay capture',
        'POST api/internal/observability/agent/{region}/uptime-results' => 'probe results of the region agent (agent token); stored as uptime results',
        'POST api/sandboxes/{sandbox}/keepalive' => 'resets the idle timer of a sandbox, changes nothing else',
        'PUT api/models/{model}/uploads/{upload}/chunk' => 'one chunk of a model upload; starting, completing and aborting the upload are audited',
        'PUT api/images/uploads/{upload}/chunk' => 'one chunk of an image upload; starting, completing and aborting the upload, and its push result, are audited',
        'POST api/model-services/{service}/invoke' => 'playground request to a model service, changes nothing',
        'POST api/projects/{project}/git/repos' => 'repository picker: lists what a provider token can see, changes nothing',
        'POST api/projects/{project}/git/github/install' => 'returns the GitHub App installation URL; the link itself is git.installation.link',
        'POST api/projects/{project}/git/gitlab/authorize' => 'returns the GitLab authorization URL; the connection itself is git.repo.connect',
        'POST api/git/webhooks/github/{provider}' => 'signed provider webhook; deliveries are kept in git_webhook_deliveries, bad signatures recorded as git.webhook.rejected',
        'POST api/git/webhooks/gitlab/{link}' => 'signed provider webhook; deliveries are kept in git_webhook_deliveries, bad tokens recorded as git.webhook.rejected',
        'POST api/git/webhooks/forgejo/{link}' => 'signed provider webhook; deliveries are kept in git_webhook_deliveries, bad signatures recorded as git.webhook.rejected',
    ];

    public function test_every_mutating_api_route_is_audited_or_listed_as_unaudited(): void
    {
        $catalog = AuditCatalog::routes();
        $missing = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
                $key = "{$method} {$route->uri()}";
                if (! isset($catalog[$key]) && ! isset(self::UNAUDITED[$key])) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertSame([], $missing, "Add these routes to AuditCatalog (or UNAUDITED with a reason):\n".implode("\n", $missing));
    }

    public function test_every_catalog_entry_matches_a_route(): void
    {
        $registered = [];
        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $registered["{$method} {$route->uri()}"] = true;
            }
        }

        foreach (array_keys(AuditCatalog::routes()) as $key) {
            $this->assertArrayHasKey($key, $registered, "catalog entry {$key} matches no route");
        }
    }

    public function test_action_names_follow_the_naming_scheme(): void
    {
        $actions = AuditCatalog::actions();

        foreach (AuditCatalog::EXPLICIT_ACTIONS as $explicit) {
            $this->assertContains($explicit, $actions);
        }
        foreach (['deployment.rollback', 'member.invite', 'project.secret.reveal', 'impersonation.start', 'workflow.signing_key.reveal'] as $action) {
            $this->assertContains($action, $actions);
        }
        foreach ($actions as $action) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $action);
        }
    }
}
