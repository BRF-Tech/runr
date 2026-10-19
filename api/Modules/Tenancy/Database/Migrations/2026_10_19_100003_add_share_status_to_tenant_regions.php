<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform region shared with a tenant can wait for the tenant's acceptance
 * (Modules\Tenancy\Support\TenantRegionShares): with billing on the tenant's administrators see
 * the region's billing type and prices and accept it with their markup before their users can
 * use it (docs/tenancy.md, docs/billing.md). A pending share has no copy of the region in the
 * tenant's schema.
 *
 * status: active | pending. Existing assignments stay active.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('tenant_regions', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('tenant_regions', 'status')) {
                $table->string('status', 16)->default('active');
                $table->timestamp('offered_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->string('accepted_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('tenant_regions', function (Blueprint $table) {
            $table->dropColumn(['status', 'offered_at', 'accepted_at', 'accepted_by']);
        });
    }
};
