<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Upgrade of a single-tenant install: before roles existed every signed-in user could do
 * everything, so existing users keep that as Admin — the administrator account
 * (ADMIN_USERNAME, else the oldest user) becomes Owner. Users created later get the default role.
 * Multi-tenant installs keep the role on the tenant membership (tenant_users.role) instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('tenancy.enabled')) {
            return;
        }

        $users = DB::table('users')->orderBy('created_at')->orderBy('id')->get(['id', 'username']);
        if ($users->isEmpty()) {
            return;
        }

        $assigned = DB::table('rbac_role_assignments')
            ->where('subject_type', 'user')
            ->whereNull('project_id')
            ->pluck('subject_id')
            ->flip();

        $owner = $users->firstWhere('username', config('app.admin.username')) ?? $users->first();
        $now = now();
        $rows = [];

        foreach ($users as $user) {
            if (isset($assigned[(string) $user->id])) {
                continue;
            }
            $rows[] = [
                'id' => (string) Str::ulid(),
                'subject_type' => 'user',
                'subject_id' => (string) $user->id,
                'role' => $user->id === $owner->id ? 'owner' : 'admin',
                'project_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('rbac_role_assignments')->insert($chunk);
        }
    }

    public function down(): void
    {
        // Assignments are kept: dropping rbac_role_assignments (previous migration) removes them
    }
};
