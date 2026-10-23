<?php

namespace App\Services\GlobalProjects;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The schema of global projects' phase 2 (docs/global-projects.md): one body for the central
 * migration (2026_10_22_300001) and the tenant migration (0095), every step idempotent.
 *
 *  - projects.global_failover          automatic (null: the default) | manual
 *  - project_regions.triggers_active   the region runs the trigger workloads (the primary)
 *  - project_regions.data_status       null (no regional data) | syncing | ready | failed: the
 *                                      region's copy reaches the project's bindings
 *  - project_regions.data_message
 *  - project_regions.unhealthy_since   first failed health check of a run of failures (failover)
 *  - project_regions.fenced_at         the region lost the primary in a failover; it rejoins
 *                                      (its data rebuilt from the new primary) once it answers
 *  - bindings.replication              the binding's data link (CA, server and client
 *                                      certificates; encrypted)
 *  - binding_replicas                  where a binding of a global project is reached from:
 *                                      home (its data), replica (a Postgres replica cluster)
 *                                      or link (the data link to the home)
 *  - project_domains.region_states     a global project's custom domain in each region
 */
final class GlobalProjectsSchema
{
    public static function up(): void
    {
        if (Schema::hasTable('projects') && ! Schema::hasColumn('projects', 'global_failover')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('global_failover', 16)->nullable();
            });
        }

        if (Schema::hasTable('project_regions')) {
            Schema::table('project_regions', function (Blueprint $table) {
                if (! Schema::hasColumn('project_regions', 'triggers_active')) {
                    $table->boolean('triggers_active')->default(false);
                }
                if (! Schema::hasColumn('project_regions', 'data_status')) {
                    $table->string('data_status', 16)->nullable();
                }
                if (! Schema::hasColumn('project_regions', 'data_message')) {
                    $table->text('data_message')->nullable();
                }
                if (! Schema::hasColumn('project_regions', 'unhealthy_since')) {
                    $table->timestamp('unhealthy_since')->nullable();
                }
                if (! Schema::hasColumn('project_regions', 'fenced_at')) {
                    $table->timestamp('fenced_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('bindings') && ! Schema::hasColumn('bindings', 'replication')) {
            Schema::table('bindings', function (Blueprint $table) {
                $table->text('replication')->nullable();
            });
        }

        if (Schema::hasTable('bindings') && ! Schema::hasTable('binding_replicas')) {
            Schema::create('binding_replicas', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('binding_id', 26);
                // the global project whose regions the binding is reached from
                $table->char('project_id', 26)->nullable()->index();
                $table->string('knative_region_id', 26)->index();
                // home | replica | link
                $table->string('kind', 16);
                // pending | provisioning | ready | promoting | recloning | failed | removing
                $table->string('status', 16)->default('pending');
                $table->text('status_message')->nullable();
                // the region's name in the CloudNativePG topology (replica clusters)
                $table->string('topology', 64)->nullable();
                // seconds the replica's replay is behind (null: unknown)
                $table->integer('lag_seconds')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamps();

                $table->unique(['binding_id', 'knative_region_id']);
                $table->foreign('binding_id')->references('id')->on('bindings')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('project_domains') && ! Schema::hasColumn('project_domains', 'region_states')) {
            Schema::table('project_domains', function (Blueprint $table) {
                $table->json('region_states')->nullable();
            });
        }
    }

    public static function down(): void
    {
        Schema::dropIfExists('binding_replicas');

        foreach ([
            'projects' => ['global_failover'],
            'project_regions' => ['triggers_active', 'data_status', 'data_message', 'unhealthy_since', 'fenced_at'],
            'bindings' => ['replication'],
            'project_domains' => ['region_states'],
        ] as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $present = array_values(array_filter($columns, fn (string $c) => Schema::hasColumn($tableName, $c)));
            if ($present !== []) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($present));
            }
        }
    }

    /**
     * The builds global projects' regions kept on their rows (project_regions.build_image) go into
     * the image store environments use (version_region_images): one build per version, region
     * and registry for both. A copy that serves a built version (not a CaaS image) gets its row,
     * ready, unless the store has one. Idempotent.
     */
    public static function moveBuildImages(): void
    {
        foreach (['project_regions', 'projects', 'versions', 'knative_regions', 'version_region_images'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $rows = DB::table('project_regions')
            ->join('projects', 'projects.id', '=', 'project_regions.project_id')
            ->join('versions', 'versions.id', '=', 'project_regions.served_version_id')
            ->join('knative_regions', 'knative_regions.id', '=', 'project_regions.knative_region_id')
            ->whereNotNull('project_regions.build_image')
            ->where('projects.service_type', '!=', 'caas')
            ->whereColumn('project_regions.knative_region_id', '!=', 'projects.knative_region_id')
            ->select([
                'project_regions.project_id', 'project_regions.knative_region_id', 'project_regions.build_image',
                'project_regions.served_version_id', 'projects.slug', 'versions.number', 'knative_regions.registry_address',
            ])
            ->get();

        foreach ($rows as $row) {
            $registry = rtrim((string) $row->registry_address, '/');
            if ($registry === '' || ! str_starts_with((string) $row->build_image, $registry.'/')) {
                continue;
            }
            $exists = DB::table('version_region_images')->where('version_id', $row->served_version_id)
                ->where('knative_region_id', $row->knative_region_id)->where('registry', $registry)->exists();
            if ($exists) {
                continue;
            }
            DB::table('version_region_images')->insert([
                'id' => strtolower((string) Str::ulid()),
                'project_id' => $row->project_id,
                'version_id' => $row->served_version_id,
                'knative_region_id' => $row->knative_region_id,
                'registry' => $registry,
                'status' => 'ready',
                'image' => $row->build_image,
                'job_name' => mb_substr("build-{$row->slug}-v{$row->number}", 0, 63),
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Central only: the offline GeoIP source of a region's PowerDNS server. */
    public static function upCentral(): void
    {
        if (! Schema::hasTable('region_global_dns')) {
            return;
        }

        Schema::table('region_global_dns', function (Blueprint $table) {
            // default (globalDns.geoipUrl) | url | file | none
            if (! Schema::hasColumn('region_global_dns', 'geoip_source')) {
                $table->string('geoip_source', 16)->nullable();
            }
            if (! Schema::hasColumn('region_global_dns', 'geoip_url')) {
                $table->text('geoip_url')->nullable();
            }
            // loaded | missing | off | unknown
            if (! Schema::hasColumn('region_global_dns', 'geoip_status')) {
                $table->string('geoip_status', 16)->nullable();
            }
            if (! Schema::hasColumn('region_global_dns', 'geoip_message')) {
                $table->text('geoip_message')->nullable();
            }
            if (! Schema::hasColumn('region_global_dns', 'geoip_checked_at')) {
                $table->timestamp('geoip_checked_at')->nullable();
            }
        });
    }

    public static function downCentral(): void
    {
        if (! Schema::hasTable('region_global_dns')) {
            return;
        }
        $columns = array_values(array_filter(
            ['geoip_source', 'geoip_url', 'geoip_status', 'geoip_message', 'geoip_checked_at'],
            fn (string $c) => Schema::hasColumn('region_global_dns', $c),
        ));
        if ($columns !== []) {
            Schema::table('region_global_dns', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
}
