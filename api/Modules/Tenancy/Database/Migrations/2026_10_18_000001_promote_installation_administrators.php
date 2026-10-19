<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform administrators (users.is_admin) for an installation that has none. The Tenancy
 * migrations run when an installation that ran with one organization switches to the
 * multi-tenant edition: users.is_admin arrives false for every account, and the chart's bootstrap
 * creates an administrator only on installs without accounts — the installation would have no
 * platform administrator. Its owners and admins (organization-wide role assignments of the single
 * organization) become platform administrators then.
 *
 * Nothing changes where a platform administrator exists (every multi-tenant installation that
 * runs) or where there are no accounts (a fresh installation).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_admin') || ! Schema::hasTable('rbac_role_assignments')) {
            return;
        }
        if (DB::table('users')->where('is_admin', true)->exists()) {
            return;
        }

        $ids = DB::table('rbac_role_assignments')
            ->where('subject_type', 'user')
            ->whereNull('project_id')
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('subject_id')
            ->filter(fn ($id) => ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id);

        foreach ($ids->chunk(500) as $chunk) {
            DB::table('users')->whereIn('id', $chunk->values()->all())->update(['is_admin' => true]);
        }
    }

    public function down(): void
    {
        // Platform administrators stay what they are
    }
};
