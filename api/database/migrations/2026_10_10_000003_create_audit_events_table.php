<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log (docs/security.md, "Audit log"): one append-only table in the central schema, with
 * tenant_id for tenant actions. Central storage survives a tenant's deletion (its schema is
 * dropped), keeps platform actions, impersonation and cross-tenant admin work in one trail, and
 * gives retention and SIEM forwarding a single place; tenant isolation is the tenant_id scope of
 * every read (AuditEventController).
 *
 * PostgreSQL enforces append-only: UPDATE and TRUNCATE always fail, DELETE only passes inside
 * the retention prune (transaction-local setting runr.audit_prune = on, AuditRetention).
 * SQLite (development / tests): UPDATE fails; DELETE is limited by the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamp('occurred_at', 6)->index();
            $table->string('tenant_id')->nullable();

            // Who: user / api_token / system / anonymous (+ the platform admin acting in a tenant)
            $table->string('actor_type', 20);
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('auth_method', 40)->nullable();
            $table->string('impersonator_id', 64)->nullable();
            $table->string('impersonator_name')->nullable();

            // What
            $table->string('action', 100);
            $table->string('result', 10);
            $table->string('target_type', 60)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->string('target_name')->nullable();

            // Where from
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->string('route', 255)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();

            $table->json('metadata')->nullable();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('request_id');
        });

        $this->appendOnlyGuards();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_truncate ON audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS audit_events_append_only()');
        }

        Schema::dropIfExists('audit_events');
    }

    private function appendOnlyGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_events_append_only() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' AND coalesce(current_setting('runr.audit_prune', true), '') = 'on' THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'audit_events is append-only (% rejected)', TG_OP
                        USING ERRCODE = 'insufficient_privilege';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::unprepared('CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION audit_events_append_only()');
            DB::unprepared('CREATE TRIGGER audit_events_no_truncate BEFORE TRUNCATE ON audit_events FOR EACH STATEMENT EXECUTE FUNCTION audit_events_append_only()');

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'audit_events is append-only (UPDATE rejected)');
                END
            SQL);
        }
    }
};
