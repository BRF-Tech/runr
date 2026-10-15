<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * runr-agent authentication: SHA-256 hash of the per-region agent token.
 * The token itself only lives in the region's Secret observability/runr-agent-token.
 * Existing regions stay NULL (agent rejected) until the agent is redeployed:
 * `php artisan agent:redeploy`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('agent_token_hash', 64)->nullable()->after('loki_status');
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn('agent_token_hash');
        });
    }
};
