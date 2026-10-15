<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xaas_configs', function (Blueprint $table) {
            $table->string('cpu')->default('250m')->after('memory_mb');
        });

        Schema::table('faas_configs', function (Blueprint $table) {
            $table->string('cpu')->default('250m')->after('memory_mb');
        });
    }

    public function down(): void
    {
        Schema::table('xaas_configs', function (Blueprint $table) {
            $table->dropColumn('cpu');
        });

        Schema::table('faas_configs', function (Blueprint $table) {
            $table->dropColumn('cpu');
        });
    }
};
