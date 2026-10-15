<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateways', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('knative_region_id')->constrained()->cascadeOnDelete();
            $table->string('subdomain');
            $table->string('custom_domain')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['knative_region_id', 'subdomain']);
            $table->unique(['user_id', 'knative_region_id']);
        });

        Schema::create('gateway_routes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('gateway_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->json('methods')->nullable();
            $table->boolean('strip_prefix')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['gateway_id', 'path']);
            $table->unique(['project_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('endpoint_type')->default('subdomain')->after('knative_region_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('endpoint_type');
        });
        Schema::dropIfExists('gateway_routes');
        Schema::dropIfExists('gateways');
    }
};
