<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule triggers get a time zone (config.timezone, docs/triggers.md#schedules-and-time-zones);
 * one without follows the organization's zone. Every schedule that existed before ran in UTC and
 * keeps running there: it gets timezone UTC. Every tenant schema runs the same
 * (Modules/Tenancy/Database/TenantMigrations/0090).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_triggers')) {
            return;
        }

        DB::table('project_triggers')->where('type', 'schedule')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                $config = json_decode((string) ($row->config ?? ''), true);
                $config = is_array($config) ? $config : [];
                if (is_string($config['timezone'] ?? null) && trim($config['timezone']) !== '') {
                    continue;
                }
                $config['timezone'] = 'UTC';
                DB::table('project_triggers')->where('id', $row->id)->update(['config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void
    {
        // UTC is what these schedules ran in before: nothing to undo
    }
};
