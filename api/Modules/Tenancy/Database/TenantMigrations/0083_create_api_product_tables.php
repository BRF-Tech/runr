<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant copy of database/migrations/2026_10_21_250001_create_api_product_tables.php: the API
 * product of the organization's gateways (docs/api-product.md) — plans, consumers, API keys (hash
 * and visible prefix only), hourly usage, the routes' authentication and "only through the
 * gateway", the gateway's custom domain verification and developer page. The region list of the
 * gateway-auth sync (gateway_auth_scopes) is central only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gateways') || ! Schema::hasTable('gateway_routes')) {
            return;
        }

        if (! Schema::hasTable('api_plans')) {
            Schema::create('api_plans', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('gateway_id', 26)->nullable()->index();
                $table->string('name', 100);
                $table->string('description', 500)->nullable();
                $table->unsignedInteger('rate_per_second')->nullable();
                $table->unsignedInteger('burst')->nullable();
                $table->unsignedInteger('rate_per_minute')->nullable();
                $table->unsignedBigInteger('quota_per_day')->nullable();
                $table->unsignedBigInteger('quota_per_month')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('gateway_id')->references('id')->on('gateways')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('api_consumers')) {
            Schema::create('api_consumers', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('gateway_id', 26)->nullable()->index();
                $table->string('name', 120);
                $table->string('email')->nullable();
                $table->string('external_id', 191)->nullable()->index();
                $table->string('jwt_subject', 191)->nullable()->index();
                $table->char('plan_id', 26)->nullable();
                $table->string('status', 16)->default('active');
                $table->json('metadata')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('gateway_id')->references('id')->on('gateways')->cascadeOnDelete();
                $table->foreign('plan_id')->references('id')->on('api_plans')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('consumer_id', 26)->index();
                $table->string('name', 120);
                $table->string('prefix', 16);
                $table->char('key_hash', 64)->unique();
                $table->json('scopes')->nullable();
                $table->char('plan_id', 26)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->char('rotated_from_id', 26)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('consumer_id')->references('id')->on('api_consumers')->cascadeOnDelete();
                $table->foreign('plan_id')->references('id')->on('api_plans')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('api_usage_hours')) {
            Schema::create('api_usage_hours', function (Blueprint $table) {
                $table->id();
                $table->string('bucket', 191)->unique();
                $table->timestamp('hour')->index();
                $table->char('gateway_id', 26);
                $table->char('route_id', 26)->nullable();
                $table->char('consumer_id', 26)->nullable();
                $table->char('api_key_id', 26)->nullable();
                $table->string('subject', 191)->nullable();
                $table->unsignedBigInteger('requests')->default(0);
                $table->unsignedBigInteger('unauthorized')->default(0);
                $table->unsignedBigInteger('forbidden')->default(0);
                $table->unsignedBigInteger('rate_limited')->default(0);
                $table->unsignedBigInteger('quota_exceeded')->default(0);
                $table->timestamps();

                $table->index(['gateway_id', 'hour']);
                $table->index(['consumer_id', 'hour']);
                $table->foreign('gateway_id')->references('id')->on('gateways')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('gateway_routes', 'auth_mode')) {
            Schema::table('gateway_routes', function (Blueprint $table) {
                $table->string('auth_mode', 16)->default('public');
                $table->json('jwt_config')->nullable();
                $table->boolean('gateway_only')->default(false);
            });
        }

        if (! Schema::hasColumn('gateways', 'custom_domain_pending')) {
            Schema::table('gateways', function (Blueprint $table) {
                $table->string('custom_domain_pending')->nullable();
                $table->string('custom_domain_token', 64)->nullable();
                $table->timestamp('custom_domain_verified_at')->nullable();
                $table->boolean('developer_page')->default(false);
                $table->text('description')->nullable();
                // why the gateway is pending / in error (e.g. gateway-auth not ready: its key and
                // JWT routes answer 503 until it is)
                $table->text('status_message')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gateways', 'custom_domain_pending')) {
            Schema::table('gateways', function (Blueprint $table) {
                $table->dropColumn(['custom_domain_pending', 'custom_domain_token', 'custom_domain_verified_at', 'developer_page', 'description', 'status_message']);
            });
        }
        if (Schema::hasColumn('gateway_routes', 'auth_mode')) {
            Schema::table('gateway_routes', function (Blueprint $table) {
                $table->dropColumn(['auth_mode', 'jwt_config', 'gateway_only']);
            });
        }

        Schema::dropIfExists('api_usage_hours');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('api_consumers');
        Schema::dropIfExists('api_plans');
    }
};
