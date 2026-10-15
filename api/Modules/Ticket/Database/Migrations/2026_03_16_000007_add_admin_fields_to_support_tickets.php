<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->json('tags')->nullable();
        });

        Schema::table('support_ticket_replies', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn('tags');
        });

        Schema::table('support_ticket_replies', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};
