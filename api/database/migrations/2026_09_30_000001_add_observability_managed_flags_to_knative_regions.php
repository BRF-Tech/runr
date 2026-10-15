<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes a RunR-installed Prometheus/Loki from one supplied externally
 * (region option to reuse an already-running Prometheus/Loki instead of
 * installing kube-prometheus-stack / loki-stack — e.g. on a
 * platform-managed cluster that already runs its own monitoring stack).
 * Existing regions default to true: RunR installed and manages their
 * observability stack today, so patch/reinstall behaviour is unchanged for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->boolean('prometheus_managed')->default(true)->after('agent_token_hash');
            $table->boolean('loki_managed')->default(true)->after('prometheus_managed');
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn(['prometheus_managed', 'loki_managed']);
        });
    }
};
