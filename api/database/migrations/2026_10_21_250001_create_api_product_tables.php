<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API product of the gateways (docs/api-product.md):
 *
 *  - api_plans          rate limits and quotas (per second + burst, per minute, per day, per month)
 *  - api_consumers      who calls a gateway's API — of one gateway, or of the organization (gateway_id null)
 *  - api_keys           a consumer's keys: only the SHA-256 hash and a visible prefix are stored
 *  - api_usage_hours    requests and refusals per hour, gateway, route, consumer and key
 *  - gateway_routes     authentication per route (public / key / jwt) and "only through the gateway"
 *  - gateways           the gateway's own custom domain (pending until its TXT record is found),
 *                       its public developer page and why it is pending / in error
 *  - gateway_auth_scopes (central only) the organizations with routes behind the gateway-auth service
 *                       of a region, with the revision that service's sync compares (GatewayAuthRevision)
 *
 * Tenant copy (without gateway_auth_scopes): Modules/Tenancy/Database/TenantMigrations/0083.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_plans')) {
            Schema::create('api_plans', function (Blueprint $table) {
                $table->ulid('id')->primary();
                // null: a plan of the organization, offered on every gateway
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
                // null: a consumer of the organization, accepted on every gateway
                $table->char('gateway_id', 26)->nullable()->index();
                $table->string('name', 120);
                $table->string('email')->nullable();
                // the customer number of the invoicing system that sells the API
                $table->string('external_id', 191)->nullable()->index();
                // JWT routes: the claim value that names this consumer
                $table->string('jwt_subject', 191)->nullable()->index();
                $table->char('plan_id', 26)->nullable();
                // active | suspended
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
                // [{route_id, methods|null}] — null: every route
                $table->json('scopes')->nullable();
                // overrides the consumer's plan
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
                // hour | gateway | route | consumer | key | subject hash (ApiUsageHour::bucket())
                $table->string('bucket', 191)->unique();
                $table->timestamp('hour')->index();
                $table->char('gateway_id', 26);
                $table->char('route_id', 26)->nullable();
                $table->char('consumer_id', 26)->nullable();
                $table->char('api_key_id', 26)->nullable();
                // JWT subject of a caller that is no consumer
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
                // public | key | jwt
                $table->string('auth_mode', 16)->default('public');
                // {jwks_url, issuer, audience, subject_claim, require_consumer}
                $table->json('jwt_config')->nullable();
                // the project's own URL stops serving (its Knative Service is cluster-local)
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

        if (! Schema::hasTable('gateway_auth_scopes')) {
            Schema::create('gateway_auth_scopes', function (Blueprint $table) {
                $table->id();
                $table->char('knative_region_id', 26);
                // tenant id, "_" for the central database
                $table->string('scope', 64);
                $table->unsignedBigInteger('revision')->default(0);
                $table->timestamps();

                $table->unique(['knative_region_id', 'scope']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_auth_scopes');

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
