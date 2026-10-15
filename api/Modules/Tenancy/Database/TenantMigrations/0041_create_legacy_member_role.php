<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Upgrade from 0.4.x: tenant members (tenant_users.role = "member") keep exactly what they could
 * do before roles existed. Tenants that have members (or open invitations) for the "member" role
 * get it as a custom role (config permissions.legacy_member_role); owners, admins and viewers map
 * to the built-in Owner, Admin and Viewer roles. Tenants created later do not get it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = config('permissions.legacy_member_role');
        $tenant = function_exists('tenant') ? tenant() : null;

        if (! $legacy || ! $tenant || DB::table('rbac_roles')->where('key', $legacy['key'])->exists()) {
            return;
        }

        $central = DB::connection(config('tenancy.database.central_connection', 'central'));
        $used = $central->table('tenant_users')->where('tenant_id', $tenant->getTenantKey())->where('role', $legacy['key'])->exists()
            || $central->table('tenant_invitations')->where('tenant_id', $tenant->getTenantKey())->where('role', $legacy['key'])->whereNull('accepted_at')->exists();

        if (! $used) {
            return;
        }

        DB::table('rbac_roles')->insert([
            'id' => (string) Str::ulid(),
            'key' => $legacy['key'],
            'name' => $legacy['name'],
            'description' => $legacy['description'],
            'permissions' => json_encode($legacy['permissions']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('rbac_roles')->where('key', config('permissions.legacy_member_role.key', 'member'))->delete();
    }
};
