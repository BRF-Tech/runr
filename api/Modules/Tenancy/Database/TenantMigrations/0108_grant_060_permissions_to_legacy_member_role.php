<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade to 0.6.0: the custom role Member that the upgrade to 0.5.0 created for tenant members
 * (0041_create_legacy_member_role, config permissions.legacy_member_role) gets the permissions new
 * in 0.6.0 that the built-in Developer role — a fresh tenant's default role for members — has:
 * environments, the organization's secret stores (seeing them), a project's network and topics.
 *
 * Only a Member role that still holds what the upgrade gave it (0.5.0 to 0.5.10: one list,
 * possibly with some of the new permissions already added) is changed; one the organization
 * changed otherwise keeps its permissions (docs/upgrade.md). Built-in roles need nothing: their
 * permissions come from config/permissions.php.
 */
return new class extends Migration
{
    /** What 0041 gave the Member role from 0.5.0 to 0.5.10 (config permissions.legacy_member_role then). */
    private const EARLIER = [
        'projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.deploy',
        'code.edit', 'env.view', 'env.edit', 'domains.manage', 'connections.manage',
        'git.pull', 'git.push', 'git.manage', 'previews.manage',
        'shell.exec', 'logs.view', 'metrics.view',
        'traces.view', 'replay.manage', 'uptime.manage',
        'workflows.view', 'workflows.run', 'workflows.manage', 'events.send',
        'sandboxes.view', 'sandboxes.create', 'sandboxes.exec',
        'security.view',
        'regions.view', 'runtimes.use_custom', 'models.view',
        'images.view', 'images.upload', 'images.delete',
        'bindings.view', 'bindings.manage',
        'members.view', 'tokens.manage', 'settings.manage', 'sso.manage',
        'tickets.view', 'tickets.create',
    ];

    /** New in 0.6.0 and part of the built-in Developer role. */
    public const ADDED = ['environments.manage', 'environments.promote', 'secret_stores.view', 'network.manage', 'topics.manage'];

    public function up(): void
    {
        $role = $this->memberRole();
        if (! $role) {
            return;
        }

        $stored = $this->permissions($role);
        $unchanged = $this->same(array_diff($stored, self::ADDED), self::EARLIER)
            && array_diff($stored, [...self::EARLIER, ...self::ADDED]) === [];
        if (! $unchanged) {
            return;
        }

        DB::table('rbac_roles')->where('id', $role->id)->update([
            'permissions' => json_encode(array_values(array_unique([...$stored, ...self::ADDED]))),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $role = $this->memberRole();
        if ($role && $this->same($this->permissions($role), [...self::EARLIER, ...self::ADDED])) {
            DB::table('rbac_roles')->where('id', $role->id)->update([
                'permissions' => json_encode(self::EARLIER),
                'updated_at' => now(),
            ]);
        }
    }

    private function memberRole(): ?object
    {
        return DB::table('rbac_roles')->where('key', config('permissions.legacy_member_role.key', 'member'))->first();
    }

    /** @return list<string> */
    private function permissions(object $role): array
    {
        $permissions = json_decode((string) $role->permissions, true);

        return is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [];
    }

    /**
     * The same permissions, in any order.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function same(array $a, array $b): bool
    {
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));
        sort($a);
        sort($b);

        return $a === $b;
    }
};
