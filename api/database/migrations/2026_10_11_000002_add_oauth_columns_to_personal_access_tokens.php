<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API tokens the MCP OAuth server issues (docs/mcp.md, "OAuth"): the client that holds the token
 * (Settings → AI agents → Connected clients) and the resource it was issued for (audience: /mcp
 * or one hosted MCP server). Personal API tokens leave both empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('personal_access_tokens', 'oauth_client_id')) {
                $table->string('oauth_client_id', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('personal_access_tokens', 'oauth_resource')) {
                $table->string('oauth_resource', 2048)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('personal_access_tokens', 'oauth_client_id')) {
                $table->dropIndex(['oauth_client_id']);
                $table->dropColumn('oauth_client_id');
            }
            if (Schema::hasColumn('personal_access_tokens', 'oauth_resource')) {
                $table->dropColumn('oauth_resource');
            }
        });
    }
};
