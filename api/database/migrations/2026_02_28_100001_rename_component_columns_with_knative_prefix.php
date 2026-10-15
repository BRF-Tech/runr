<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RENAMES = [
        'operator_status' => 'knative_operator_status',
        'operator_status_message' => 'knative_operator_status_message',
        'operator_namespace' => 'knative_operator_namespace',
        'serving_status' => 'knative_serving_status',
        'serving_status_message' => 'knative_serving_status_message',
        'serving_namespace' => 'knative_serving_namespace',
        'serving_ingress_class' => 'knative_serving_ingress_class',
        'serving_version' => 'knative_serving_version',
        'eventing_status' => 'knative_eventing_status',
        'eventing_status_message' => 'knative_eventing_status_message',
        'eventing_namespace' => 'knative_eventing_namespace',
        'eventing_version' => 'knative_eventing_version',
        'redis_status' => 'knative_redis_status',
        'redis_status_message' => 'knative_redis_status_message',
    ];

    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            foreach (self::RENAMES as $old => $new) {
                $table->renameColumn($old, $new);
            }
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            foreach (self::RENAMES as $old => $new) {
                $table->renameColumn($new, $old);
            }
        });
    }
};
