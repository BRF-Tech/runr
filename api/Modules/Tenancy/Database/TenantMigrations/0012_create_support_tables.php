<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create tenant-scoped support ticket tables.
     *
     * Note: search_path is "tenant_X, public" so Schema::create() creates
     * in the first schema (tenant) without conflicting with public tables.
     */
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('subject');
            $table->text('description');
            $table->string('category');
            $table->string('priority')->default('medium');
            $table->string('status')->default('open');
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('support_ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_ticket_id');
            $table->unsignedBigInteger('user_id');
            $table->text('message');
            $table->boolean('is_staff')->default(false);
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->foreign('support_ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_replies');
        Schema::dropIfExists('support_tickets');
    }
};
