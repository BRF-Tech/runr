<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's network in the tenant schema, as in the central one
 * (database/migrations/2026_10_21_100001): projects.access_mode / allowed_cidrs and the
 * project_internal_callers allow list of the organization's projects.
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
