<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global projects (docs/global-projects.md): one project running in several regions behind one
 * global host name <slug>.<global zone>.
 *
 *  - projects.placement: regional (one region, as before) | global; projects.global_mode: ha |
 *    proximity for a global project.
 *  - project_regions: the regions a global project runs in — its primary (projects
 *    .knative_region_id) included — with the state of the copy in each (the version it serves,
 *    the one being rolled out, the per-region build image, the last health check).
 *  - region_global_dns (platform-wide, central schema only): the PowerDNS server RunR runs in a
 *    region for the global zone — its status, LoadBalancer address, API key (encrypted), the last
 *    zone sync and the region's wildcard certificate.
 *
 * Every tenant schema gets the project columns and project_regions
 * (Modules/Tenancy/Database/TenantMigrations/0082): the organizations' projects live there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'placement')) {
                $table->string('placement', 16)->default('regional')->index();
            }
            if (! Schema::hasColumn('projects', 'global_mode')) {
                $table->string('global_mode', 16)->nullable();
            }
        });

        if (! Schema::hasTable('project_regions')) {
            Schema::create('project_regions', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('project_id', 26);
                $table->string('knative_region_id', 26)->index();
                // order of the regions: the HA failover order after the primary
                $table->unsignedSmallInteger('position')->default(0);
                // pending | building | deploying | ready | failed | removing
                $table->string('status', 16)->default('pending');
                $table->text('status_message')->nullable();
                // the version the copy serves, and the one being rolled out to it
                $table->string('served_version_id', 26)->nullable();
                $table->string('target_version_id', 26)->nullable();
                // the primary deployment the rollout follows
                $table->string('deployment_id', 26)->nullable();
                // the version's image in this region's registry (built here)
                $table->string('build_image')->nullable();
                // the rollout these polls belong to (a newer rollout starts a new one)
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

        if (! Schema::hasTable('region_global_dns')) {
            Schema::create('region_global_dns', function (Blueprint $table) {
                $table->string('knative_region_id', 26)->primary();
                // installing | ready | error | uninstalling
                $table->string('status', 16)->default('installing');
                $table->text('status_message')->nullable();
                // the LoadBalancer address of the PowerDNS Service (public :53)
                $table->string('address')->nullable();
                $table->text('api_key')->nullable();
                $table->string('image')->nullable();
                $table->boolean('reachable')->nullable();
                // in_sync | drift | error
                $table->string('sync_status', 16)->nullable();
                $table->text('sync_message')->nullable();
                $table->timestamp('synced_at')->nullable();
                // the region's wildcard certificate of the zone: none | pending | ready | error
                $table->string('tls_status', 16)->nullable();
                $table->text('tls_message')->nullable();
                $table->timestamp('tls_expires_at')->nullable();
                $table->string('tls_spec_hash', 64)->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('region_global_dns');
        Schema::dropIfExists('project_regions');

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
};
