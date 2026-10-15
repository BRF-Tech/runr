<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RoutePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Modules\Tenancy\Http\Middleware\RequireAdmin;
use ReflectionMethod;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Hard to forget: every authenticated API route — core and module routes — needs a rule in
 * config/route_permissions.php (or `can:` middleware), rules name real permissions and real
 * routes, and `@policy` controllers authorize. An unmapped route is refused at runtime.
 */
class RouteAuthorizationTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs with the modules off: register their routes the way their providers do,
        // with the configuration they merge first (the OAuth routes are constrained to the drivers)
        config(['authprovider' => array_merge(require base_path('Modules/AuthProvider/Config/config.php'), config('authprovider', []))]);
        $this->app['router']->aliasMiddleware('tenancy.admin', RequireAdmin::class);
        Router::middleware(['api', 'auth:sanctum'])->prefix('api')->group(base_path('Modules/Tenancy/routes/api.php'));
        Router::middleware('api')->prefix('api')->group(base_path('Modules/Tenancy/routes/public.php'));
        // White-label and billing (PlanServiceProvider): plans, onboarding, tenant branding, the
        // billing API + the public plan list
        Router::middleware(['api', 'auth:sanctum'])->prefix('api')->group(base_path('Modules/Tenancy/routes/white-label.php'));
        Router::middleware(['api', 'auth:sanctum'])->prefix('api')->group(base_path('Modules/Tenancy/routes/billing.php'));
        Router::middleware('api')->prefix('api')->group(base_path('Modules/Tenancy/routes/white-label-public.php'));
        Router::middleware(['api', 'auth:sanctum'])->prefix('api')->group(base_path('Modules/AuthProvider/routes/api.php'));
        Router::middleware('api')->prefix('api')->group(base_path('Modules/AuthProvider/routes/public.php'));
        Router::prefix('api')->group(base_path('Modules/Ticket/routes/api.php'));
        Router::getRoutes()->refreshNameLookups();
        Router::getRoutes()->refreshActionLookups();
    }

    /** @return list<Route> API routes plus the RunR MCP server (/mcp, authenticated, outside /api) */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $r) => str_starts_with($r->uri(), 'api/') || $r->uri() === 'mcp',
        ));
    }

    public function test_every_authenticated_api_route_has_a_permission_rule(): void
    {
        $missing = [];
        $checked = 0;
        foreach ($this->apiRoutes() as $route) {
            if (! RoutePermissions::requiresAuthentication($route)) {
                continue;
            }
            $checked++;
            if (RoutePermissions::requirement($route) === null && ! RoutePermissions::hasCanMiddleware($route)) {
                $missing[] = implode(' / ', RoutePermissions::keys($route));
            }
        }

        $this->assertGreaterThan(150, $checked, 'core and module routes are loaded');
        $this->assertSame([], $missing, "API routes without a permission rule — add them to config/route_permissions.php:\n".implode("\n", $missing));
    }

    public function test_rules_name_catalog_permissions(): void
    {
        $markers = [RoutePermissions::AUTHENTICATED, RoutePermissions::PLATFORM, RoutePermissions::POLICY];

        foreach (config('route_permissions') as $key => $rule) {
            [$mode, $permissions] = RoutePermissions::parse($rule);
            if (str_starts_with($mode, '@')) {
                $this->assertContains($mode, $markers, "{$key}: unknown marker {$mode}");

                continue;
            }
            $this->assertNotEmpty($permissions, $key);
            foreach ($permissions as $permission) {
                $this->assertContains($permission, PermissionCatalog::allKeys(), "{$key}: unknown permission {$permission}");
            }
        }
    }

    public function test_every_rule_belongs_to_a_route(): void
    {
        $keys = [];
        foreach ($this->apiRoutes() as $route) {
            foreach (RoutePermissions::keys($route) as $key) {
                $keys[$key] = true;
            }
        }

        $stale = array_values(array_diff(array_keys(config('route_permissions')), array_keys($keys)));
        $this->assertSame([], $stale, "Rules without a route — remove them from config/route_permissions.php:\n".implode("\n", $stale));
    }

    public function test_policy_routes_authorize_in_their_controller(): void
    {
        foreach ($this->apiRoutes() as $route) {
            if (RoutePermissions::requirement($route) !== RoutePermissions::POLICY) {
                continue;
            }
            [$class, $method] = explode('@', $route->getActionName());
            $reflection = new ReflectionMethod($class, $method);
            $lines = array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);

            $this->assertMatchesRegularExpression(
                '/Gate::authorize\(|->authorize\(/',
                implode('', $lines),
                "{$route->getActionName()} is marked @policy but does not authorize",
            );
        }
    }

    public function test_project_routes_are_checked_for_the_project(): void
    {
        foreach ($this->apiRoutes() as $route) {
            $rule = RoutePermissions::requirement($route);
            if ($rule === null || ! str_contains($route->uri(), '{project}') || ! RoutePermissions::requiresAuthentication($route)) {
                continue;
            }
            [$mode, $permissions] = RoutePermissions::parse($rule);
            $this->assertNotSame(RoutePermissions::AUTHENTICATED, $mode, "{$route->uri()} is a project route");
            foreach ($permissions as $permission) {
                $this->assertTrue(
                    PermissionCatalog::isProjectScoped($permission) || $permission === 'projects.create',
                    "{$route->uri()} requires {$permission}, which cannot be granted per project",
                );
            }
        }
    }

    public function test_resource_update_routes_answer_patch_with_their_put_rule(): void
    {
        foreach (['projects/{project}', 'knative-regions/{knative_region}'] as $uri) {
            $route = collect($this->apiRoutes())->first(fn (Route $r) => $r->uri() === "api/{$uri}" && in_array('PATCH', $r->methods(), true));
            $this->assertNotNull($route, "PATCH api/{$uri} is registered");
            $this->assertContains('PUT', $route->methods(), "api/{$uri}: one PUT|PATCH route (Route::apiResource)");
            $this->assertSame(config('route_permissions')["PUT {$uri}"], RoutePermissions::requirement($route), "PATCH api/{$uri} is authorized with the PUT rule");
        }
    }

    public function test_an_unmapped_authenticated_route_is_refused(): void
    {
        Router::middleware(['api', 'auth:sanctum'])->get('/api/rbac-test/unmapped', fn () => 'ok');
        $owner = $this->userWithRole('owner');

        $this->signedIn($owner)->getJson('/api/rbac-test/unmapped')
            ->assertForbidden()
            ->assertJsonPath('message', __('rbac.route_not_mapped'));
    }

    public function test_can_middleware_authorizes_a_route_without_a_rule(): void
    {
        Router::middleware(['api', 'auth:sanctum', 'can:audit.view'])->get('/api/rbac-test/audit', fn () => 'ok');
        $owner = $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');

        $this->signedIn($owner)->get('/api/rbac-test/audit')->assertOk();
        $this->signedIn($viewer)->getJson('/api/rbac-test/audit')->assertForbidden();
    }

    public function test_public_routes_stay_public(): void
    {
        $this->getJson('/api/auth/registration-status')->assertOk();
        $this->getJson('/api/projects')->assertUnauthorized();
    }

    public function test_a_denied_request_names_the_permission(): void
    {
        $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');

        $this->signedIn($viewer)->postJson('/api/knative-regions', [])
            ->assertForbidden()
            ->assertJsonPath('permissions', ['regions.manage']);
    }

    public function test_platform_rules_with_a_permission_scope_tokens(): void
    {
        $this->assertSame([RoutePermissions::PLATFORM_SCOPED, ['billing.view']], RoutePermissions::parse('@platform:billing.view'));
        $this->assertSame([RoutePermissions::PLATFORM_SCOPED, ['billing.view', 'billing.manage']], RoutePermissions::parse('@platform:billing.view|billing.manage'));
        $this->assertSame([RoutePermissions::PLATFORM, []], RoutePermissions::parse('@platform'));

        $route = Router::getRoutes()->match(Request::create('/api/billing/charges', 'GET'));
        $this->assertSame('@platform:billing.view', RoutePermissions::requirement($route));
        $route = Router::getRoutes()->match(Request::create('/api/billing/charges/x', 'PATCH'));
        $this->assertSame('@platform:billing.manage', RoutePermissions::requirement($route));
    }

    public function test_platform_routes_need_a_platform_admin(): void
    {
        $owner = $this->userWithRole('owner');
        $route = Router::getRoutes()->match(Request::create('/api/tenants', 'GET'));
        $this->assertSame(RoutePermissions::PLATFORM, RoutePermissions::requirement($route));

        // The organization's owner is not the platform's operator
        $this->signedIn($owner)->getJson('/api/resource-usage')->assertForbidden();
        $this->assertInstanceOf(User::class, $owner);
    }
}
