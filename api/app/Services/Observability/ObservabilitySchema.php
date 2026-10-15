<?php

namespace App\Services\Observability;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of observability v2, defined once for the central migration
 * (database/migrations/2026_10_11_000001_create_observability_tables.php) and the tenant copy
 * (Modules/Tenancy/Database/TenantMigrations/0050_create_observability_tables.php).
 *
 *  - per tenant (tenant schema, or the single database without Tenancy): project settings, replay
 *    captures, uptime checks / results / incidents, the status page, alert channels / rules /
 *    events / deliveries — project rows are removed with their project (ObservabilityServiceProvider)
 *  - central only: region tracing configuration (keyed by region id; platform and tenant-owned
 *    regions alike) and the public lookup of status pages (slug / custom domain → tenant)
 */
final class ObservabilitySchema
{
    public const TENANT_TABLES = [
        'alert_deliveries', 'alert_events', 'alert_rules', 'alert_channels',
        'status_pages', 'uptime_incidents', 'uptime_results', 'uptime_checks',
        'replay_captures', 'project_observability_settings',
    ];

    public const CENTRAL_TABLES = ['status_page_routes', 'region_tracing_configs'];

    public static function createCentralTables(): void
    {
        if (! Schema::hasTable('region_tracing_configs')) {
            Schema::create('region_tracing_configs', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('region_id', 26)->unique();
                $table->string('tenant_id')->nullable()->index();
                $table->string('mode', 16)->default('managed');
                $table->string('status', 16)->nullable();
                $table->text('status_message')->nullable();
                $table->string('tempo_url', 255)->nullable();
                $table->string('tempo_otlp_endpoint', 255)->nullable();
                $table->string('tempo_org_id', 255)->nullable();
                $table->string('export_endpoint', 500)->nullable();
                $table->text('export_headers')->nullable();
                $table->float('knative_sample_rate')->default(0.1);
                $table->unsignedInteger('retention_hours')->default(72);
                $table->timestamp('installed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('status_page_routes')) {
            Schema::create('status_page_routes', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('slug', 63)->unique();
                $table->string('custom_domain', 253)->nullable()->unique();
                $table->string('tenant_id')->nullable()->index();
                $table->string('status_page_id', 26);
                $table->boolean('is_public')->default(false);
                $table->timestamps();
            });
        }
    }

    public static function createTenantTables(): void
    {
        Schema::create('project_observability_settings', function (Blueprint $table) {
            $table->string('project_id', 26)->primary();
            $table->boolean('tracing_enabled')->default(false);
            $table->float('tracing_sample_ratio')->default(1);
            $table->boolean('replay_enabled')->default(false);
            $table->unsignedInteger('replay_max_body_bytes')->default(65536);
            $table->unsignedSmallInteger('replay_retention_days')->default(7);
            $table->json('replay_redact_headers')->nullable();
            $table->json('replay_redact_query')->nullable();
            $table->string('replay_salt', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('replay_captures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->timestamp('captured_at');
            $table->timestamp('expires_at')->index();
            $table->string('trigger', 16)->default('http');
            $table->string('request_id', 128)->nullable();
            $table->string('trace_id', 32)->nullable()->index();
            $table->string('revision', 128)->nullable();
            $table->string('method', 16);
            $table->text('path');
            $table->text('query')->nullable();
            $table->text('headers')->nullable();
            $table->json('redacted_headers')->nullable();
            $table->longText('body')->nullable();
            $table->boolean('body_base64')->default(false);
            $table->unsignedInteger('body_size')->default(0);
            $table->boolean('body_truncated')->default(false);
            $table->unsignedSmallInteger('status');
            $table->boolean('timed_out')->default(false);
            $table->float('duration_ms')->nullable();
            $table->text('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->boolean('response_body_base64')->default(false);
            $table->boolean('response_truncated')->default(false);
            $table->unsignedInteger('replay_count')->default(0);
            $table->longText('last_replay')->nullable();
            $table->timestamp('last_replayed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'captured_at']);
        });

        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('project_id', 26)->index();
            $table->string('name', 100);
            $table->string('url', 2048)->nullable();
            $table->string('path', 1024)->default('/');
            $table->string('method', 8)->default('GET');
            $table->text('headers')->nullable();
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedInteger('timeout_seconds')->default(10);
            $table->string('expected_status', 64)->default('200-399');
            $table->string('body_regex', 500)->nullable();
            $table->string('locations', 16)->default('control_plane');
            $table->json('region_ids')->nullable();
            $table->unsignedTinyInteger('failure_threshold')->default(3);
            $table->unsignedTinyInteger('recovery_threshold')->default(2);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('show_on_status_page')->default(false);
            $table->string('status_page_name', 100)->nullable();
            $table->unsignedInteger('status_page_order')->default(0);
            $table->string('status', 16)->default('unknown');
            $table->json('location_state')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('uptime_results', function (Blueprint $table) {
            $table->id();
            $table->string('uptime_check_id', 26);
            $table->string('location', 64);
            $table->boolean('ok');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at');

            $table->index(['uptime_check_id', 'checked_at']);
        });

        Schema::create('uptime_incidents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('uptime_check_id', 26)->index();
            $table->string('project_id', 26)->index();
            $table->string('status', 16)->default('open')->index();
            $table->string('title', 200);
            $table->text('message')->nullable();
            $table->text('cause')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('failed_checks')->default(0);
            $table->timestamps();
        });

        Schema::create('status_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 63)->unique();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('custom_domain', 253)->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('show_incident_history')->default(true);
            $table->unsignedSmallInteger('history_days')->default(90);
            $table->timestamps();
        });

        Schema::create('alert_channels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('type', 16);
            $table->text('config');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_error', 500)->nullable();
            $table->string('created_by', 26)->nullable();
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('type', 32)->index();
            $table->string('project_id', 26)->nullable()->index();
            $table->string('region_id', 26)->nullable();
            $table->json('params')->nullable();
            $table->string('severity', 16)->default('warning');
            $table->unsignedInteger('cooldown_minutes')->default(15);
            $table->json('channel_ids')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('state', 16)->default('ok');
            $table->float('last_value')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('alert_rule_id', 26)->nullable()->index();
            $table->string('fingerprint', 191)->index();
            $table->string('type', 32);
            $table->string('severity', 16);
            $table->string('status', 16)->default('firing');
            $table->string('title', 255);
            $table->text('message')->nullable();
            $table->json('data')->nullable();
            $table->string('project_id', 26)->nullable()->index();
            $table->timestamp('fired_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('alert_event_id', 26)->nullable()->index();
            $table->string('alert_channel_id', 26)->index();
            $table->string('kind', 16)->default('alert');
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public static function dropTenantTables(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public static function dropCentralTables(): void
    {
        foreach (self::CENTRAL_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
}
