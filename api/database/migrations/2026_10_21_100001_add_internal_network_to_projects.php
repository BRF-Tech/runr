<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's network (docs/internal-network.md):
 *
 *  - projects.access_mode: who reaches the project from outside the cluster — public (the
 *    default, what every project did before), allowlist (public, from the addresses in
 *    projects.allowed_cidrs only) or internal (no public route; other projects reach it on the
 *    internal network)
 *  - project_internal_callers: the projects that may call it on the internal network, by their
 *    internal name <project>.<organization> (another organization's project: a grant)
 *
 * Every tenant schema gets the same (Modules/Tenancy/Database/TenantMigrations/0067): the
 * organizations' projects live there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('projects') && ! Schema::hasColumn('projects', 'access_mode')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('access_mode', 16)->default('public');
                $table->json('allowed_cidrs')->nullable();
            });
        }

        if (! Schema::hasTable('project_internal_callers')) {
            Schema::create('project_internal_callers', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
                $table->string('caller', 120);
                $table->string('created_by')->nullable();
                $table->timestamps();

                $table->unique(['project_id', 'caller']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_internal_callers');

        if (Schema::hasTable('projects') && Schema::hasColumn('projects', 'access_mode')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn(['access_mode', 'allowed_cidrs']);
            });
        }
    }
};
