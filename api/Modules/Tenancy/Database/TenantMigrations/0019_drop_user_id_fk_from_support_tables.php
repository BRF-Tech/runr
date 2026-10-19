<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop user_id FK constraints from support tables.
     * Admin users (central schema) need to reply to tickets
     * in tenant schemas where they don't exist in the users table.
     */
    public function up(): void
    {
        if ($this->hasForeignKey('support_tickets', 'support_tickets_user_id_foreign')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        if ($this->hasForeignKey('support_ticket_replies', 'support_ticket_replies_user_id_foreign')) {
            Schema::table('support_ticket_replies', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('support_ticket_replies', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * The table of the tenant's own schema (the first one on the search path; the database file on
     * SQLite) — Schema::getForeignKeys() looks there, on every driver.
     */
    private function hasForeignKey(string $table, string $fkName): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['name'] === $fkName || $foreignKey['columns'] === ['user_id']) {
                return true;
            }
        }

        return false;
    }
};
