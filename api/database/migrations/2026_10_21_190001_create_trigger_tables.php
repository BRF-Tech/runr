<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Triggers (docs/triggers.md): a project runs with a list of triggers instead of the single
 * trigger_type of its config row.
 *
 *  - project_triggers: type (http | schedule | queue | workflow | webhook | topic |
 *    platform_event | …, App\Triggers\TriggerTypes), name, config, encrypted secrets, enabled,
 *    position (the first one is the primary trigger the config's trigger_type mirrors) and the
 *    public key of a webhook inbox URL
 *  - trigger_events: what a webhook inbox received and the platform events sent to functions,
 *    kept for their retention, delivered by the project's event gateway and replayable
 *  - topics: the organization's fan-out topics (Redis Streams of a region)
 *
 * Every existing xaas / faas / caas project gets one trigger from its config (trigger_type,
 * schedule, queue_stream). Every tenant schema gets the same tables
 * (Modules/Tenancy/Database/TenantMigrations/0077).
 */
return new class extends Migration
{
    public function up(): void
    {
        self::createTables();
        self::backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('trigger_events');
        Schema::dropIfExists('project_triggers');
        Schema::dropIfExists('topics');
    }

    public static function createTables(): void
    {
        if (! Schema::hasTable('project_triggers') && Schema::hasTable('projects')) {
            Schema::create('project_triggers', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('project_id', 26);
                $table->string('name', 63);
                $table->string('type', 32);
                $table->json('config')->nullable();
                // Encrypted JSON (App\Casts\Encrypted): signing secrets, broker credentials
                $table->text('secrets')->nullable();
                $table->boolean('enabled')->default(true);
                $table->unsignedSmallInteger('position')->default(0);
                // Public key of a webhook inbox URL (/api/hooks/{context}/{key})
                $table->string('ingress_key', 64)->nullable()->unique();
                $table->string('created_by')->nullable();
                $table->timestamps();

                $table->unique(['project_id', 'name']);
                $table->index(['type', 'enabled']);
                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('trigger_events') && Schema::hasTable('project_triggers')) {
            Schema::create('trigger_events', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('project_id', 26);
                $table->char('trigger_id', 26);
                // webhook | platform
                $table->string('kind', 16);
                $table->string('event_type', 128);
                $table->string('subject')->nullable();
                // Sender's delivery id (webhook) / the platform event's identity: dedupe key
                $table->string('delivery_id')->nullable();
                $table->string('content_type')->nullable();
                $table->json('headers')->nullable();
                // Raw body, base64 (a webhook body can be binary)
                $table->longText('body')->nullable();
                $table->unsignedInteger('body_size')->default(0);
                // pending | delivering | delivered | failed
                $table->string('status', 16)->default('pending');
                $table->unsignedInteger('attempts')->default(0);
                $table->unsignedInteger('replays')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('lease_until')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->unique(['trigger_id', 'delivery_id']);
                $table->index(['trigger_id', 'status']);
                $table->index(['trigger_id', 'received_at']);
                $table->index('expires_at');
                $table->foreign('trigger_id')->references('id')->on('project_triggers')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('topics')) {
            Schema::create('topics', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('name', 100)->unique();
                $table->char('knative_region_id', 26);
                $table->string('description', 500)->nullable();
                $table->string('created_by')->nullable();
                $table->timestamps();

                $table->index('knative_region_id');
            });
        }
    }

    /** One trigger per existing project from its config row (trigger_type, schedule, queue_stream). */
    public static function backfill(): void
    {
        if (! Schema::hasTable('project_triggers')) {
            return;
        }

        foreach (['xaas_configs', 'faas_configs', 'container_configs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->orderBy('project_id')->chunk(500, function ($rows) {
                $existing = DB::table('project_triggers')
                    ->whereIn('project_id', $rows->pluck('project_id')->all())
                    ->pluck('project_id')
                    ->flip();
                $now = now();
                $insert = [];

                foreach ($rows as $row) {
                    if ($existing->has($row->project_id)) {
                        continue;
                    }

                    $type = (string) ($row->trigger_type ?? 'http');
                    $config = match ($type) {
                        'http' => [],
                        'schedule' => ['cron' => $row->schedule ?: '*/5 * * * *'],
                        'queue' => ['stream' => $row->queue_stream ?: 'default-stream'],
                        'workflow' => array_filter(['queue_stream' => $row->queue_stream ?: null]),
                        // The retired `event` trigger and anything unknown: no trigger row, the
                        // project keeps running from its config (App\Triggers\ProjectTriggers)
                        default => null,
                    };
                    if ($config === null) {
                        continue;
                    }

                    $insert[] = [
                        'id' => (string) Str::ulid(),
                        'project_id' => $row->project_id,
                        'name' => $type,
                        'type' => $type,
                        'config' => json_encode((object) $config),
                        'enabled' => true,
                        'position' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($insert) {
                    DB::table('project_triggers')->insert($insert);
                }
            });
        }
    }
};
