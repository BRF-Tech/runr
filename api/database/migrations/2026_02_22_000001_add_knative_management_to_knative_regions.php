<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('knative_status', 30)->default('unknown');
            $table->text('knative_status_message')->nullable();
            $table->timestamp('knative_status_checked_at')->nullable();
            $table->json('selected_workers')->nullable();
            $table->json('knative_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn([
                'knative_status',
                'knative_status_message',
                'knative_status_checked_at',
                'selected_workers',
                'knative_settings',
            ]);
        });
    }
};
