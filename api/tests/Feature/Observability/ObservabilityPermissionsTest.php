<?php

namespace Tests\Feature\Observability;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * The observability permissions in the catalog and the built-in role matrix, project access
 * for the project-scoped ones, and the public / internal routes outside the sign-in.
 */
class ObservabilityPermissionsTest extends TestCase
{
    use ActsWithRoles, FakesObservabilityCluster, RefreshDatabase;

    private const ALL = ['traces.view', 'replay.manage', 'uptime.manage', 'alerts.manage', 'status_page.manage', 'regions.observability.manage'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();
    }

    public function test_catalog_entries(): void
    {
        foreach (self::ALL as $permission) {
            $this->assertContains($permission, PermissionCatalog::keys());
        }
        $this->assertTrue(PermissionCatalog::isProjectScoped('traces.view'));
        $this->assertTrue(PermissionCatalog::isProjectScoped('replay.manage'));
        $this->assertTrue(PermissionCatalog::isProjectScoped('uptime.manage'));
        $this->assertFalse(PermissionCatalog::isProjectScoped('alerts.manage'));
        $this->assertFalse(PermissionCatalog::isProjectScoped('status_page.manage'));
        $this->assertContains('regions.view', PermissionCatalog::expand(['regions.observability.manage']));
        $this->assertContains('metrics.view', PermissionCatalog::expand(['uptime.manage']));
    }

    public static function matrix(): array
    {
        return [
            'owner' => ['owner', self::ALL, []],
            'admin' => ['admin', self::ALL, []],
            'developer' => ['developer', ['traces.view', 'replay.manage', 'uptime.manage'], ['alerts.manage', 'status_page.manage', 'regions.observability.manage']],
            'viewer' => ['viewer', ['traces.view'], ['replay.manage', 'uptime.manage', 'alerts.manage', 'status_page.manage', 'regions.observability.manage']],
        ];
    }

    #[DataProvider('matrix')]
    public function test_built_in_roles(string $role, array $allowed, array $denied): void
    {
        $this->userWithRole('owner');
        $user = $role === 'owner' ? $this->userWithRole('owner') : $this->userWithRole($role);

        foreach ($allowed as $permission) {
            $this->assertTrue($this->can($user, $permission), "{$role} may {$permission}");
        }
        foreach ($denied as $permission) {
            $this->assertFalse($this->can($user, $permission), "{$role} may not {$permission}");
        }
    }

    public function test_project_access_grants_the_project_scoped_permissions(): void
    {
        $this->userWithRole('owner');
        $this->customRole('outsider', ['tickets.view']);
        $member = $this->userWithRole('outsider');
        $region = $this->obsRegion();
        $project = $this->obsProject($region);
        $other = $this->obsProject($region, 'billing');

        $this->signedIn($member)->getJson("/api/projects/{$project->id}/uptime-checks")->assertForbidden();

        $this->grantProject($member, $project, 'developer');

        $this->assertAllowed($this->signedIn($member)->getJson("/api/projects/{$project->id}/uptime-checks"));
        $this->assertAllowed($this->signedIn($member)->getJson("/api/projects/{$project->id}/replays"));
        $this->signedIn($member)->getJson("/api/projects/{$other->id}/replays")->assertForbidden();
        $this->signedIn($member)->getJson('/api/observability/alert-channels')->assertForbidden();
    }

    public function test_public_and_internal_routes_need_no_sign_in(): void
    {
        $this->getJson('/api/public/status-pages/nobody')->assertNotFound();
        $this->postJson('/api/internal/observability/replay/garbage', ['a' => 1])->assertNotFound();
        $this->getJson('/api/internal/observability/agent/01JNOREGION000000000000000/uptime-checks')->assertUnauthorized();
        $this->getJson('/api/observability/alert-channels')->assertUnauthorized();
    }
}
