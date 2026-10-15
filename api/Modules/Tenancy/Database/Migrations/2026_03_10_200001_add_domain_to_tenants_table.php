<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Domain moved to per-tenant settings (base_domain key in tenant schema).
 * This migration is intentionally empty — kept for ordering consistency.
 */
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
