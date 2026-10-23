<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_21_240001_create_global_projects.php: the project
 * columns of global projects (placement, global_mode) and project_regions, next to the
 * organization's projects. region_global_dns stays central: the PowerDNS servers of the global
 * zone run in platform regions and belong to the platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projects')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'placement')) {
                $table->string('placement', 16)->default('regional')->index();
            }
            if (! Schema::hasColumn('projects', 'global_mode')) {
                $table->string('global_mode', 16)->nullable();
            }
        });

        if (Schema::hasTable('project_regions')) {
            return;
        }

        Schema::create('project_regions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('project_id', 26);
            $table->string('knative_region_id', 26)->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 16)->default('pending');
            $table->text('status_message')->nullable();
            $table->string('served_version_id', 26)->nullable();
            $table->string('target_version_id', 26)->nullable();
            $table->string('deployment_id', 26)->nullable();
            $table->string('build_image')->nullable();
            $table->string('rollout_token', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('healthy')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'knative_region_id']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_regions');

        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $table) {
                if (Schema::hasColumn('projects', 'placement')) {
                    $table->dropIndex(['placement']);
                    $table->dropColumn('placement');
                }
                if (Schema::hasColumn('projects', 'global_mode')) {
                    $table->dropColumn('global_mode');
                }
            });
        }
    }
};
