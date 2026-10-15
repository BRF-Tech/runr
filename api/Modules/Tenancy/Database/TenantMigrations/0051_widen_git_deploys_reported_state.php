<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_11_200001_widen_git_deploys_reported_state.php:
 * reported_state ("pending|deploying") did not fit in 16 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('git_deploys')) {
            return;
        }

        Schema::table('git_deploys', function (Blueprint $table) {
            $table->string('reported_state', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing again could cut stored values: the wider column stays
    }
};
