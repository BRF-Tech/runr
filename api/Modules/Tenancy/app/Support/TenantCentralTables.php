<?php

namespace Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Central tables whose rows belong to one organization through a tenant column. Their data stays
 * in the central schema in both editions (the reaper, warm pools, webhooks, MCP and the public
 * status pages see every organization without walking the tenant schemas); a single-organization
 * install leaves the column null.
 *
 * - CONVERSION (tenancy:setup): the rows with a null column are the organization's and get the
 *   new tenant's id — except the tables where null means "every organization" (defaults,
 *   platform runtimes, platform branding) or "the platform" (audit trail, regions, uploaded
 *   images).
 * - DELETION (TenantDeletionService): the rows of a deleted tenant go, except the audit trail and
 *   billing records, which outlive the organization.
 *
 * A table or column that does not exist (an older schema, a module that is off) is skipped.
 */
final class TenantCentralTables
{
    /**
     * table => tenant column: rows of the single organization a conversion assigns to the tenant.
     *
     * @var array<string, string>
     */
    public const ADOPTED_ON_CONVERSION = [
        // Sandbox API: the organization's sandboxes (pods keep running in their namespace) and
        // warm pool definitions
        'sandboxes' => 'tenant_id',
        'sandbox_pools' => 'tenant_id',
        // Git deploy: installations and repository links route webhooks into the organization
        'git_installations' => 'tenant_id',
        'git_repo_links' => 'tenant_id',
        // MCP: the organization's servers, their calls, agent sessions and pending sign-ins
        'mcp_servers' => 'tenant_id',
        'mcp_server_calls' => 'tenant_id',
        'mcp_sessions' => 'tenant_id',
        'mcp_oauth_requests' => 'tenant_id',
        // Public status page lookup (slug / custom domain → organization)
        'status_page_routes' => 'tenant_id',
        // Not uploaded images: a single-organization install uploads as the platform (owner_key
        // "platform", registry repository runr-uploads/platform/…), which platform runtimes use;
        // they stay platform uploads (SingleTenantConversion::platformImageProjects())
    ];

    /**
     * table => tenant column: rows deleted with their tenant (cluster and storage resources are
     * removed first by the services that own them, TenantDeletionService).
     *
     * @var array<string, string>
     */
    public const DELETED_WITH_TENANT = [
        'sandboxes' => 'tenant_id',
        'sandbox_pools' => 'tenant_id',
        'sandbox_quotas' => 'tenant_id',
        'git_installations' => 'tenant_id',
        'git_repo_links' => 'tenant_id',
        'mcp_server_calls' => 'tenant_id',
        'mcp_servers' => 'tenant_id',
        'mcp_sessions' => 'tenant_id',
        'mcp_oauth_requests' => 'tenant_id',
        'image_uploads' => 'tenant_id',
        'uploaded_images' => 'tenant_id',
        'status_page_routes' => 'tenant_id',
        // Set for the organization's own regions only (region tracing: KEYED_BY_OWN_REGION, its
        // tenant_id may name an organization a platform region was configured from)
        'region_supply_chain_settings' => 'owner_tenant_id',
        'tenant_security_policies' => 'tenant_id',
        'brandings' => 'tenant_id',
        'notifications' => 'tenant_id',
        // Custom runtimes registered in the organization (their versions and builds below)
        'runtimes' => 'tenant_id',
    ];

    /**
     * Central rows of a sandbox (sandbox_id): processes and the activity log, deleted with the
     * organization's sandboxes.
     *
     * @var list<string>
     */
    public const SANDBOX_CHILDREN = ['sandbox_processes', 'sandbox_events'];

    /**
     * table => region column: central settings keyed by region id, deleted for the regions the
     * organization owned (they existed in its schema only).
     *
     * @var array<string, string>
     */
    public const KEYED_BY_OWN_REGION = [
        'region_tracing_configs' => 'region_id',
        'region_supply_chain_settings' => 'knative_region_id',
        'sandbox_region_settings' => 'knative_region_id',
        'runtime_builds' => 'knative_region_id',
    ];

    /**
     * Kept when the tenant goes: the audit trail (integrity chain) and billing records — metered
     * usage of platform regions, the platform's bills to the tenant, mirrored fixed allocations,
     * billing events, the tenant's own bills copied from its schema (billing_archived_bills,
     * TenantDeletionService) and the archive of the 0.5.7 billing tables (docs/billing.md).
     *
     * @var list<string>
     */
    public const RETAINED = [
        'audit_events', 'sandbox_usage_records', 'usage_records', 'binding_usage_records',
        'billing_bills', 'billing_fixed_requests', 'billing_events', 'billing_archived_bills', 'billing_legacy_archive',
    ];

    /**
     * Deletes a deleted tenant's rows from the central tables: DELETED_WITH_TENANT, the children of
     * its sandboxes, the versions and builds of its custom runtimes, and the settings of the regions
     * it owned ($ownRegionIds). A table that fails is logged and skipped (the organization is gone
     * either way); RETAINED tables are never touched.
     *
     * @param  list<string>  $ownRegionIds
     * @return array<string, int> rows deleted per table
     */
    public static function deleteTenantRows(string $connection, string $tenantId, array $ownRegionIds = []): array
    {
        $db = DB::connection($connection);
        $deleted = [];

        $attempt = function (string $table, \Closure $delete) use (&$deleted, $tenantId) {
            try {
                $count = (int) $delete();
                if ($count > 0) {
                    $deleted[$table] = ($deleted[$table] ?? 0) + $count;
                }
            } catch (\Throwable $e) {
                Log::warning('Tenant deletion: central rows not removed', ['tenant' => $tenantId, 'table' => $table, 'error' => $e->getMessage()]);
            }
        };

        if (self::has($connection, 'sandboxes', 'tenant_id')) {
            foreach (self::SANDBOX_CHILDREN as $table) {
                if (self::has($connection, $table, 'sandbox_id')) {
                    $attempt($table, fn () => $db->table($table)
                        ->whereIn('sandbox_id', fn ($q) => $q->select('id')->from('sandboxes')->where('tenant_id', $tenantId))
                        ->delete());
                }
            }
        }

        if (self::has($connection, 'runtimes', 'tenant_id')) {
            $runtimeIds = $db->table('runtimes')->where('tenant_id', $tenantId)->pluck('id')->map(fn ($id) => (string) $id)->all();
            if ($runtimeIds !== []) {
                if (self::has($connection, 'executor_images', 'runtime')) {
                    $attempt('executor_images', fn () => $db->table('executor_images')->whereIn('runtime', $runtimeIds)->delete());
                }
                if (self::has($connection, 'runtime_builds', 'runtime_id')) {
                    $attempt('runtime_builds', fn () => $db->table('runtime_builds')->whereIn('runtime_id', $runtimeIds)->delete());
                }
            }
        }

        if ($ownRegionIds !== []) {
            foreach (self::KEYED_BY_OWN_REGION as $table => $column) {
                if (self::has($connection, $table, $column)) {
                    $attempt($table, fn () => $db->table($table)->whereIn($column, $ownRegionIds)->delete());
                }
            }
        }

        foreach (self::DELETED_WITH_TENANT as $table => $column) {
            if (self::has($connection, $table, $column)) {
                $attempt($table, fn () => $db->table($table)->where($column, $tenantId)->delete());
            }
        }

        return $deleted;
    }

    /** Whether $table has $column on $connection (both may be missing on older schemas). */
    public static function has(string $connection, string $table, string $column): bool
    {
        $schema = Schema::connection($connection);

        return $column !== '' && $schema->hasTable($table) && $schema->hasColumn($table, $column);
    }

    /**
     * Rows of the single organization (column null) per adopted table.
     *
     * @return array<string, int>
     */
    public static function adoptableCounts(string $connection): array
    {
        $counts = [];
        foreach (self::ADOPTED_ON_CONVERSION as $table => $column) {
            if (self::has($connection, $table, $column)) {
                $counts[$table] = DB::connection($connection)->table($table)->whereNull($column)->count();
            }
        }

        return $counts;
    }

    /**
     * Gives the single organization's rows (column null) of the adopted tables to $tenantId.
     *
     * @return array<string, int> rows changed per table
     */
    public static function adopt(string $connection, string $tenantId): array
    {
        $changed = [];
        foreach (self::ADOPTED_ON_CONVERSION as $table => $column) {
            if (self::has($connection, $table, $column)) {
                $changed[$table] = DB::connection($connection)->table($table)->whereNull($column)->update([$column => $tenantId]);
            }
        }

        return $changed;
    }
}
