<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same column as the central knative_regions (2026_09_29_000001): the runr-agent token hash.
 * The agent deploy of tenant-owned regions writes this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('knative_regions', 'agent_token_hash')) {
            return;
        }

        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('agent_token_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn('agent_token_hash');
        });
    }
};
