<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI agents (docs/mcp.md) — central schema in both editions, rows carry tenant_id:
 *
 *   mcp_sessions        sessions of the RunR MCP server (id = SHA-256 of the Mcp-Session-Id)
 *   mcp_oauth_clients   OAuth clients registered dynamically (client secret as SHA-256)
 *   mcp_oauth_requests  authorization requests and their one-time codes (code as SHA-256)
 *   mcp_servers         projects served as MCP servers behind the gateway (keys encrypted)
 *   mcp_server_calls    the gateway's call log
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->unsignedBigInteger('token_id')->nullable()->index();
                $table->string('user_id', 64)->index();
                $table->string('tenant_id')->nullable()->index();
                $table->string('protocol_version', 20);
                $table->string('client_name')->nullable();
                $table->string('client_version', 100)->nullable();
                $table->json('client_capabilities')->nullable();
                $table->string('log_level', 16)->default('info');
                $table->timestamp('initialized_at')->nullable();
                $table->timestamp('last_seen_at')->index();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('mcp_oauth_clients')) {
            Schema::create('mcp_oauth_clients', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->string('name', 100);
                $table->json('redirect_uris');
                $table->json('grant_types');
                $table->string('token_endpoint_auth_method', 32)->default('none');
                $table->string('secret_hash', 64)->nullable();
                $table->string('client_uri', 500)->nullable();
                $table->string('logo_uri', 500)->nullable();
                $table->string('software_id')->nullable();
                $table->string('software_version', 100)->nullable();
                $table->string('created_ip', 45)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mcp_oauth_requests')) {
            Schema::create('mcp_oauth_requests', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('client_id', 64)->index();
                $table->text('redirect_uri');
                $table->text('state')->nullable();
                $table->json('scopes')->nullable();
                $table->string('resource', 2048);
                $table->string('code_challenge', 128);
                $table->string('code_challenge_method', 10)->default('S256');
                $table->string('status', 16)->default('pending')->index();
                $table->string('user_id', 64)->nullable();
                $table->string('tenant_id')->nullable();
                $table->json('abilities')->nullable();
                $table->unsignedSmallInteger('lifetime_days')->nullable();
                $table->string('code_hash', 64)->nullable()->unique();
                $table->timestamp('code_expires_at')->nullable();
                $table->unsignedBigInteger('token_id')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mcp_servers')) {
            Schema::create('mcp_servers', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('handle', 80)->unique();
                $table->string('tenant_id')->nullable()->index();
                $table->char('project_id', 26)->unique();
                $table->string('access_mode', 16)->default('token');
                $table->string('path', 200)->default('/mcp');
                $table->boolean('audit_calls')->default(false);
                $table->text('signing_key');
                $table->text('signing_key_previous')->nullable();
                $table->timestamp('previous_key_expires_at')->nullable();
                $table->timestamp('key_rotated_at')->nullable();
                $table->string('created_by', 64)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mcp_server_calls')) {
            Schema::create('mcp_server_calls', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('mcp_server_id', 26);
                $table->string('tenant_id')->nullable();
                $table->char('project_id', 26);
                $table->timestamp('occurred_at', 3);
                $table->string('method', 100);
                $table->string('name')->nullable();
                $table->string('jsonrpc_id', 100)->nullable();
                $table->unsignedSmallInteger('status_code');
                $table->string('outcome', 16);
                $table->string('error', 500)->nullable();
                $table->unsignedInteger('duration_ms')->default(0);
                $table->string('actor_type', 16);
                $table->string('actor_id', 64)->nullable();
                $table->string('actor_name')->nullable();
                $table->string('auth', 16);
                $table->string('ip_address', 45)->nullable();
                $table->string('session_id', 32)->nullable();
                $table->unsignedInteger('request_bytes')->nullable();
                $table->unsignedBigInteger('response_bytes')->nullable();

                $table->index(['mcp_server_id', 'occurred_at']);
                $table->index(['project_id', 'occurred_at']);
                $table->index('occurred_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_server_calls');
        Schema::dropIfExists('mcp_servers');
        Schema::dropIfExists('mcp_oauth_requests');
        Schema::dropIfExists('mcp_oauth_clients');
        Schema::dropIfExists('mcp_sessions');
    }
};
