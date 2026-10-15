<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            // Operator
            $table->string('operator_status', 30)->default('not_installed');
            $table->text('operator_status_message')->nullable();
            $table->string('operator_namespace', 63)->default('knative-operator');

            // Serving
            $table->string('serving_status', 30)->default('not_installed');
            $table->text('serving_status_message')->nullable();
            $table->string('serving_namespace', 63)->default('knative-serving');
            $table->string('serving_ingress_class', 30)->default('traefik');

            // Eventing
            $table->string('eventing_status', 30)->default('not_installed');
            $table->text('eventing_status_message')->nullable();
            $table->string('eventing_namespace', 63)->default('knative-eventing');
            $table->string('eventing_version', 20)->nullable();
        });

        // Data migration: existing regions with knative_status=ready → 3 components ready
        DB::table('knative_regions')
            ->where('knative_status', 'ready')
            ->update([
                'operator_status' => 'ready',
                'serving_status' => 'ready',
                'eventing_status' => 'ready',
            ]);
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn([
                'operator_status', 'operator_status_message', 'operator_namespace',
                'serving_status', 'serving_status_message', 'serving_namespace', 'serving_ingress_class',
                'eventing_status', 'eventing_status_message', 'eventing_namespace', 'eventing_version',
            ]);
        });
    }
};
