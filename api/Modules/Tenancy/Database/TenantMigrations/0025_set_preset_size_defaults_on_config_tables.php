<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same as the central 2026_10_01_100001_set_preset_size_defaults_on_config_tables:
 * column defaults follow the default instance sizes — XaaS / FaaS "micro" (256 MiB), CaaS
 * "small" (512 MiB). Existing rows keep their values.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->memoryDefaults(['xaas_configs' => 256, 'faas_configs' => 256, 'container_configs' => 512]);
    }

    public function down(): void
    {
        $this->memoryDefaults(['xaas_configs' => 128, 'faas_configs' => 128, 'container_configs' => 128]);
    }

    private function memoryDefaults(array $defaults): void
    {
        foreach ($defaults as $table => $memoryMb) {
            if (! Schema::hasColumn($table, 'memory_mb')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) use ($memoryMb) {
                $table->integer('memory_mb')->default($memoryMb)->change();
            });
        }
    }
};
