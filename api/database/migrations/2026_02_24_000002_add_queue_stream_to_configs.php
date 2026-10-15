<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xaas_configs', function (Blueprint $table) {
            $table->string('queue_stream')->nullable();
        });

        Schema::table('faas_configs', function (Blueprint $table) {
            $table->string('queue_stream')->nullable();
        });

        Schema::table('container_configs', function (Blueprint $table) {
            $table->string('queue_stream')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('xaas_configs', function (Blueprint $table) {
            $table->dropColumn('queue_stream');
        });

        Schema::table('faas_configs', function (Blueprint $table) {
            $table->dropColumn('queue_stream');
        });

        Schema::table('container_configs', function (Blueprint $table) {
            $table->dropColumn('queue_stream');
        });
    }
};
