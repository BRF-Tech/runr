<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

    private function hasForeignKey(string $table, string $fkName): bool
    {
        $schema = DB::connection()->getConfig('search_path') ?? 'public';
        $schema = explode(',', $schema)[0];
        $schema = trim($schema);

        return DB::table('information_schema.table_constraints')
            ->where('constraint_name', $fkName)
            ->where('table_name', $table)
            ->where('table_schema', $schema)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
