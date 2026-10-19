<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projects get an explicit owner — the payer of the project's bills (docs/billing.md), the creator
 * by default, transferable — and a stop state: a stopped project's workloads are torn down and it
 * cannot be deployed until it is resumed; the reason is shown to its members.
 *
 * Existing projects: the owner is the creator (created_by), except projects created by the system.
 * Every tenant schema gets the same columns (Modules/Tenancy/Database/TenantMigrations/0058).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'owner_id')) {
                $table->string('owner_id')->nullable()->index();
            }
            if (! Schema::hasColumn('projects', 'stopped_at')) {
                $table->timestamp('stopped_at')->nullable();
                $table->string('stopped_by')->nullable();
                $table->text('stop_reason')->nullable();
                $table->string('stopped_version_id', 26)->nullable();
            }
        });

        DB::table('projects')
            ->whereNull('owner_id')
            ->whereNotNull('created_by')
            ->where('created_by', '<>', 'system')
            ->update(['owner_id' => DB::raw('created_by')]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['owner_id']);
            $table->dropColumn(['owner_id', 'stopped_at', 'stopped_by', 'stop_reason', 'stopped_version_id']);
        });
    }
};
