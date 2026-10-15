<?php

namespace App\Workflows\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of the durable workflow engine (docs/workflows.md § Data model). One schema shared by
 * the central migration (database/migrations/2026_10_10_000001_create_workflow_tables.php) and
 * the tenant migration (Modules/Tenancy/Database/TenantMigrations/0040_create_workflow_tables.php):
 * with tenancy every tenant schema holds its own workflow tables, so all rows are tenant-scoped.
 */
final class WorkflowSchema
{
    public const TABLES = [
        'workflow_usage',
        'workflow_steps',
        'workflow_runs',
        'workflow_events',
        'workflow_triggers',
        'workflows',
        'workflow_apps',
    ];

    public static function up(): void
    {
        // Per project: the signing key the engine signs requests with, endpoint override, sync state
        Schema::create('workflow_apps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('signing_key');
            $table->text('signing_key_previous')->nullable();
            $table->timestamp('previous_key_expires_at')->nullable();
            $table->string('endpoint_url', 2048)->nullable();
            $table->string('sync_status', 16)->default('never');   // never | ok | error
            $table->text('sync_error')->nullable();
            $table->json('sdk')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        // Workflow definitions, discovered from the SDK (introspection) or declared (manifest)
        Schema::create('workflows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->json('triggers')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source', 16)->default('sdk');           // sdk | manifest | api
            $table->string('definition_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        // Denormalized triggers for lookups by event name / cron ticks / cancel events
        Schema::create('workflow_triggers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('type', 16);                             // event | cron | cancel
            $table->string('event', 200)->nullable();
            $table->string('cron', 120)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->text('expression')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'event']);
        });

        Schema::create('workflow_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 200);
            $table->json('data')->nullable();
            $table->string('external_id', 200)->nullable()->unique();
            $table->string('source', 16)->default('api');          // api | workflow | queue | cron | test
            $table->ulid('project_id')->nullable()->index();
            $table->string('sent_by')->nullable();
            $table->unsignedBigInteger('ts');                       // event time, ms since epoch
            $table->unsignedInteger('runs_started')->default(0);
            $table->unsignedInteger('waits_resumed')->default(0);
            $table->unsignedInteger('runs_cancelled')->default(0);
            $table->timestamps();

            $table->index(['name', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->ulid('project_id');
            $table->string('status', 16);
            $table->string('trigger_source', 16)->default('api');  // api | event | cron | queue | invoke | replay | test
            $table->ulid('event_id')->nullable()->index();
            $table->json('event')->nullable();
            $table->json('output')->nullable();
            $table->json('error')->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('idempotency_key', 200)->nullable();
            $table->string('concurrency_key', 200)->nullable();
            $table->ulid('parent_run_id')->nullable()->index();
            $table->ulid('parent_step_id')->nullable();
            $table->ulid('replay_of_run_id')->nullable()->index();
            $table->string('replay_from_step', 255)->nullable();
            $table->string('started_by')->nullable();
            $table->string('lease_token', 40)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('steps_count')->default(0);
            $table->timestamps();

            $table->unique(['workflow_id', 'idempotency_key']);
            $table->index(['workflow_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->string('step_key', 255);
            $table->string('name', 255);
            $table->string('type', 16);                             // run | sleep | wait_for_event | invoke | send_event
            $table->string('status', 16);
            $table->unsignedInteger('position');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('max_attempts')->default(1);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('error')->nullable();
            $table->json('attempts')->nullable();                   // [{attempt, started_at, ended_at, duration_ms, error}]
            $table->timestamp('wake_at')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->string('wait_event', 200)->nullable();
            $table->text('wait_expression')->nullable();
            $table->ulid('child_run_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'step_key']);
            $table->index(['wait_event', 'status']);
            $table->index(['status', 'wake_at']);
        });

        // Metering: step executions + started runs per workflow per day (docs/workflows.md § Usage)
        Schema::create('workflow_usage', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->date('date');
            $table->ulid('project_id');
            $table->ulid('workflow_id');
            $table->unsignedBigInteger('step_executions')->default(0);
            $table->unsignedBigInteger('runs_started')->default(0);
            $table->timestamps();

            $table->unique(['date', 'workflow_id']);
            $table->index(['project_id', 'date']);
        });
    }

    public static function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
}
