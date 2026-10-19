<?php

namespace Tests\Feature\Rbac;

use App\Support\Rbac\PermissionCatalog;
use Tests\TestCase;

/**
 * config/permissions.php: the catalog other features rely on, the built-in roles of each
 * edition, implied permissions and the 0.4.x token scope translation.
 */
class PermissionCatalogTest extends TestCase
{
    /** Names other parts of RunR authorize with (audit log, runtimes, secrets …) */
    private const REQUIRED = [
        'projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.deploy',
        'code.edit', 'env.view', 'env.edit', 'secrets.view_values', 'domains.manage', 'shell.exec',
        'logs.view', 'metrics.view', 'regions.view', 'regions.manage', 'sandbox.manage',
        'registry.manage', 'members.view', 'members.invite', 'members.remove', 'roles.manage',
        'tokens.manage', 'billing.view', 'billing.manage', 'billing.approve', 'settings.manage',
        // project owners and billing operators (docs/billing.md)
        'projects.billing', 'projects.stop',
        'tickets.view', 'tickets.create',
        'audit.view', 'audit.export', 'runtimes.manage', 'runtimes.use_custom',
        'models.view', 'models.deploy', 'models.manage', 'regions.gpu.manage',
        // white-label (docs/white-label.md)
        'branding.manage', 'signup.manage',
        // Sandbox API (docs/sandbox-api.md)
        'sandboxes.view', 'sandboxes.create', 'sandboxes.exec', 'sandboxes.manage',
    ];

    public function test_the_catalog_has_every_permission_other_features_use(): void
    {
        foreach (self::REQUIRED as $permission) {
            $this->assertContains($permission, PermissionCatalog::allKeys(), "{$permission} is part of the catalog");
        }
    }

    public function test_every_permission_has_a_group_and_a_scope(): void
    {
        foreach (config('permissions.permissions') as $key => $meta) {
            // area.action, or area.sub.action (regions.gpu.manage, regions.observability.manage);
            // areas may contain underscores (status_page.manage)
            $this->assertMatchesRegularExpression('/^[a-z_]+(\.[a-z_]+)+$/', $key);
            $this->assertNotEmpty($meta['group'] ?? null, "{$key} group");
            $this->assertContains($meta['scope'] ?? null, ['org', 'project'], "{$key} scope");
            foreach ($meta['implies'] ?? [] as $implied) {
                $this->assertArrayHasKey($implied, config('permissions.permissions'), "{$key} implies an unknown {$implied}");
            }
        }
    }

    public function test_built_in_roles_only_name_catalog_permissions(): void
    {
        foreach (config('permissions.roles') as $key => $role) {
            foreach ($role['permissions'] as $permission) {
                $this->assertTrue(
                    $permission === '*' || in_array($permission, PermissionCatalog::allKeys(), true),
                    "role {$key}: unknown permission {$permission}",
                );
            }
        }
        foreach (config('permissions.legacy_member_role.permissions') as $permission) {
            $this->assertContains($permission, PermissionCatalog::allKeys());
        }
    }

    public function test_billing_is_part_of_both_editions(): void
    {
        // The Billing module works with tenancy off and on (docs/billing.md)
        foreach ([false, true] as $multi) {
            config(['tenancy.enabled' => $multi]);

            $this->assertSame(['owner', 'admin', 'developer', 'viewer', 'billing'], array_keys(PermissionCatalog::builtinRoles()));
            foreach (['billing.view', 'billing.manage', 'billing.approve', 'projects.billing', 'projects.stop'] as $permission) {
                $this->assertContains($permission, PermissionCatalog::keys());
            }
            $this->assertTrue(PermissionCatalog::isProjectScoped('projects.billing'), 'a project owner role can hold it per project');

            $billing = PermissionCatalog::builtinRoles()['billing']['permissions'];
            $this->assertContains('billing.approve', $billing);
            $this->assertContains('projects.stop', $billing, 'billing operators stop and resume projects');
        }
    }

    public function test_tickets_manage_is_single_tenant_only(): void
    {
        config(['tenancy.enabled' => false]);
        $this->assertSame('single', PermissionCatalog::edition());
        $this->assertContains('tickets.manage', PermissionCatalog::keys());

        // Answering tickets is the platform operator's job in multi-tenant installs
        config(['tenancy.enabled' => true]);
        $this->assertSame('multi', PermissionCatalog::edition());
        $this->assertNotContains('tickets.manage', PermissionCatalog::keys());
    }

    public function test_owner_and_admin_hold_every_permission(): void
    {
        $roles = PermissionCatalog::builtinRoles();

        $this->assertSame(PermissionCatalog::keys(), $roles['owner']['permissions']);
        $this->assertSame(PermissionCatalog::keys(), $roles['admin']['permissions']);
    }

    public function test_developer_and_viewer(): void
    {
        $roles = PermissionCatalog::builtinRoles();
        $developer = $roles['developer']['permissions'];
        $viewer = $roles['viewer']['permissions'];

        foreach (['projects.deploy', 'code.edit', 'env.edit', 'shell.exec', 'domains.manage'] as $p) {
            $this->assertContains($p, $developer);
            $this->assertNotContains($p, $viewer);
        }
        foreach (['regions.manage', 'members.invite', 'roles.manage', 'settings.manage', 'sso.manage', 'secrets.view_values', 'branding.manage', 'signup.manage'] as $p) {
            $this->assertNotContains($p, $developer, "developer must not hold {$p}");
        }
        foreach (['projects.view', 'logs.view', 'metrics.view'] as $p) {
            $this->assertContains($p, $viewer);
        }
        $this->assertNotContains('env.view', $viewer, 'environment variables often carry credentials');
    }

    public function test_implied_permissions_are_added(): void
    {
        $this->assertSame(
            ['projects.view', 'env.view', 'env.edit'],
            PermissionCatalog::expand(['env.edit']),
        );
        $this->assertContains('regions.view', PermissionCatalog::expand(['sandbox.manage']));
        $this->assertContains('audit.view', PermissionCatalog::expand(['audit.export']));
    }

    public function test_wildcard_and_unknown_names(): void
    {
        $this->assertSame(PermissionCatalog::keys(), PermissionCatalog::expand(['*']));
        $this->assertSame([], PermissionCatalog::expand(['nope.nothing']));
    }

    public function test_legacy_token_scopes_are_translated(): void
    {
        $this->assertSame(['projects.view', 'projects.deploy'], PermissionCatalog::translateLegacyAbilities(['deployments:write']));
        $this->assertSame(
            // projects:read covers reading the project's git remote too (git deploy)
            ['projects.view', 'logs.view', 'metrics.view', 'git.pull', 'regions.view'],
            PermissionCatalog::translateLegacyAbilities(['projects:read', 'deployments:read', 'settings:read']),
        );
        $this->assertSame(['env.view'], PermissionCatalog::translateLegacyAbilities(['env.view']));
    }

    public function test_every_permission_and_built_in_role_has_console_labels_in_english_and_turkish(): void
    {
        foreach (['en', 'tr'] as $locale) {
            $file = base_path("../vue/src/locales/{$locale}/rbac.js");
            if (! is_file($file)) {
                $this->markTestSkipped('vue/ is not part of this checkout');
            }
            $source = file_get_contents($file);
            foreach (PermissionCatalog::allKeys() as $permission) {
                $key = str_replace('.', '_', $permission);
                // any JS string literal: labels with an apostrophe (Turkish suffixes) use double quotes
                $this->assertMatchesRegularExpression("/\\b{$key}:\\s*['\"`]/", $source, "{$locale}: label of {$permission}");
            }
            foreach (array_keys(config('permissions.roles')) as $role) {
                $this->assertStringContainsString("roleName_{$role}:", $source, "{$locale}: name of role {$role}");
            }
        }
    }
}
