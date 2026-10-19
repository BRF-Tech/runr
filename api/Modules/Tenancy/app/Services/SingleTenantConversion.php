<?php

namespace Modules\Tenancy\Services;

use App\Models\RoleAssignment;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\GitService;
use App\Services\Helpers\OrganizationCeiling;
use App\Support\Auth\LoginTokens;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Billing\Database\BillingSchema;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\CentralSyncableUser;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantLocalRegion;
use Modules\Tenancy\Models\TenantLocalUser;
use Modules\Tenancy\Support\TenantCentralTables;
use RuntimeException;

/**
 * Turns a single-organization installation into the first organization (tenant) of a
 * multi-tenant one (php artisan tenancy:setup, docs/tenancy.md). The central schema keeps every
 * row it had — nothing is deleted; the organization's data is copied into the tenant's schema:
 *
 *  1. schema      the tenant's schema with every module's tenant migrations (TenantSchema)
 *  2. members     every account becomes a member with its organization role (the role assignment
 *                 of the single organization; Owner for the platform administrator without one)
 *  3. users       the members' synced copies in the tenant schema
 *  4. admins      the organization's owners and admins become platform administrators
 *  5. regions     every region is assigned to the tenant (quota = its capacity) and synced
 *  6. tables      the rows of every table the tenant schema has, in foreign-key order
 *                 (organization-wide role assignments become the memberships of step 2)
 *  7. settings    the organization's settings (default role); platform settings stay central
 *  8. central     the organization's rows of central tables get the tenant id (sandboxes, git
 *                 installations and repository links, MCP, uploaded images, status pages) and
 *                 its API tokens work in the tenant; sign-in sessions stay unbound
 *  9. billing     from today the organization's projects are metered and billed in the tenant
 *                 only: open central bills end yesterday, allocations move to the tenant
 *                 (handOverBilling(); also for a conversion an earlier release finished)
 * 10. git         storage/git → storage/git/<slug>, git-remotes/_ → git-remotes/<slug>
 * 11. attachments support ticket attachments move into the tenant's directory (Ticket module)
 *
 * Every step records its completion in the central setting "tenancy_setup", so a run that
 * stopped half-way continues where it stopped; a finished conversion is never run again (it
 * would bring back members, rows and files the organization removed since). Rows are inserted
 * with "insert or ignore" and each table is copied in one transaction.
 */
class SingleTenantConversion
{
    /** Central setting holding the conversion's state (JSON) */
    public const STATE_KEY = 'tenancy_setup';

    /** Steps in order; each runs once */
    public const STEPS = ['schema', 'members', 'users', 'admins', 'regions', 'tables', 'settings', 'central', 'billing', 'git', 'attachments'];

    /**
     * Tenant tables not copied row by row: synced copies of the central accounts and platform
     * regions (steps users / regions), settings (ORGANIZATION_SETTINGS only), the migrations log,
     * and the billing settings and event log: the webhook stays the platform's (step billing).
     */
    public const NOT_COPIED = ['migrations', 'users', 'knative_regions', 'settings', 'billing_settings', 'billing_events'];

    /** Central tables whose rows become the tenant's and are no longer metered or billed centrally (ConvertedOrganization) */
    private const CONVERTED_TABLES = ['projects', 'ai_model_services', 'bindings'];

    /** Settings of the organization; every other key is a platform setting and stays central */
    public const ORGANIZATION_SETTINGS = [AccessManager::SETTING_DEFAULT_ROLE];

    /** Organization roles whose holders become platform administrators */
    public const PROMOTED_ROLES = [PermissionCatalog::OWNER, 'admin'];

    public const STATUS_CONVERT = 'convert';

    public const STATUS_RESUME = 'resume';

    public const STATUS_DONE = 'done';

    public const STATUS_MULTI_TENANT = 'multi-tenant';

    public const STATUS_NOTHING = 'nothing';

    private const CHUNK = 500;

    /** @var \Closure(string, string): void level (info|warn|line), message */
    private \Closure $log;

    /** @var \Closure(Tenant): void creates (if missing) and migrates the tenant's database */
    private \Closure $schemaCreator;

    public function __construct(?\Closure $log = null, ?\Closure $schemaCreator = null)
    {
        $this->log = $log ?? fn (string $level, string $message) => null;
        $this->schemaCreator = $schemaCreator ?? fn (Tenant $tenant) => TenantSchema::create($tenant);
    }

    // ── State ───────────────────────────────────────────────────────────────

    public function centralConnection(): Connection
    {
        return DB::connection(config('tenancy.database.central_connection', 'central'));
    }

    /** @return array{tenant_id?: string, started_at?: string, completed_at?: string, steps?: array<string, string>, tables?: array<string, int>} */
    public function state(): array
    {
        $value = $this->centralConnection()->table('settings')->where('key', self::STATE_KEY)->value('value');
        $state = is_string($value) ? json_decode($value, true) : null;

        return is_array($state) ? $state : [];
    }

    private function saveState(array $state): void
    {
        $now = now();
        $this->centralConnection()->table('settings')->updateOrInsert(
            ['key' => self::STATE_KEY],
            ['value' => json_encode($state), 'updated_at' => $now, 'created_at' => $now],
        );
    }

    /** The tenant a conversion created (finished or not). */
    public function target(): ?Tenant
    {
        $id = $this->state()['tenant_id'] ?? null;

        return $id ? Tenant::find($id) : null;
    }

    /**
     * convert: single-organization data and no tenant · resume: a conversion stopped half-way ·
     * done: converted · multi-tenant: organizations exist that no conversion created ·
     * nothing: no single-organization data (a fresh installation).
     */
    public function status(): string
    {
        $state = $this->state();
        if ($this->target()) {
            return isset($state['completed_at']) ? self::STATUS_DONE : self::STATUS_RESUME;
        }

        if (Tenant::query()->exists()) {
            return self::STATUS_MULTI_TENANT;
        }

        return $this->hasSingleTenantData() ? self::STATUS_CONVERT : self::STATUS_NOTHING;
    }

    /**
     * Data only a single-organization installation writes: projects in the central schema, or
     * organization-wide roles of accounts (a multi-tenant installation keeps roles on the
     * tenant memberships).
     */
    public function hasSingleTenantData(): bool
    {
        $central = $this->centralConnection();
        $schema = Schema::connection($central->getName());

        if ($schema->hasTable('projects') && $central->table('projects')->exists()) {
            return true;
        }

        return $schema->hasTable('rbac_role_assignments')
            && $central->table('rbac_role_assignments')
                ->where('subject_type', RoleAssignment::SUBJECT_USER)
                ->whereNull('project_id')
                ->exists();
    }

    // ── Run ─────────────────────────────────────────────────────────────────

    /**
     * Converts (or continues a conversion). Returns the tenant.
     *
     * @throws RuntimeException when the installation is not one to convert or a step fails
     */
    public function run(string $name, string $slug, bool $promote = true): Tenant
    {
        $status = $this->status();
        if (! in_array($status, [self::STATUS_CONVERT, self::STATUS_RESUME], true)) {
            throw new RuntimeException("Nothing to convert (status: {$status}).");
        }

        $tenant = $this->target() ?? $this->createTenant($name, $slug);
        $state = $this->state();
        $state['steps'] ??= [];

        foreach (self::STEPS as $step) {
            if (isset($state['steps'][$step])) {
                continue;
            }
            if ($step === 'admins' && ! $promote) {
                $state['steps'][$step] = 'skipped';
                $this->saveState($state);

                continue;
            }

            $this->say('info', "Step {$step}...");
            match ($step) {
                'schema' => ($this->schemaCreator)($tenant),
                'members' => $this->assignMembers($tenant),
                'users' => $this->syncUsers($tenant),
                'admins' => $this->promoteAdministrators(),
                'regions' => $this->assignRegions($tenant),
                'tables' => $this->copyTables($tenant),
                'settings' => $this->copySettings($tenant),
                'central' => $this->adoptCentralRows($tenant),
                'billing' => $this->handOverBilling($tenant),
                'git' => $this->moveGitStorage($tenant),
                'attachments' => $this->moveTicketAttachments($tenant),
            };

            $state = $this->state();
            $state['steps'][$step] = now()->toIso8601String();
            $this->saveState($state);
        }

        $state['completed_at'] = now()->toIso8601String();
        $this->saveState($state);

        return $tenant;
    }

    /**
     * A step a later release added (billing) runs once for a conversion that finished before it,
     * on the next tenancy:setup (the bootstrap Job of every upgrade). Returns the steps run.
     *
     * @return list<string>
     */
    public function runAddedSteps(): array
    {
        $tenant = $this->target();
        $state = $this->state();
        if (! $tenant || ! isset($state['completed_at']) || isset($state['steps']['billing'])) {
            return [];
        }

        $this->say('info', 'Step billing...');
        $this->handOverBilling($tenant);
        $state = $this->state();
        $state['steps']['billing'] = now()->toIso8601String();
        $this->saveState($state);

        return ['billing'];
    }

    private function createTenant(string $name, string $slug): Tenant
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $slug) !== 1) {
            throw new RuntimeException("Invalid slug '{$slug}': letters, digits, dashes and underscores only.");
        }
        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw new RuntimeException("A tenant with the slug '{$slug}' exists.");
        }

        $tenant = Tenant::create(['name' => $name, 'slug' => $slug]);
        $this->saveState(['tenant_id' => (string) $tenant->getTenantKey(), 'started_at' => now()->toIso8601String(), 'steps' => []]);
        $this->say('info', "Created tenant {$name} ({$slug}), id {$tenant->getTenantKey()}.");

        return $tenant;
    }

    // ── Members, accounts, platform administrators ──────────────────────────

    /**
     * Organization role of every account: its role assignment in the single organization; an
     * account without one gets Owner when it is a platform administrator, otherwise the default
     * role. Without any owner, the platform administrator — or the first account — is Owner.
     *
     * @return array<int|string, string> user id => role
     */
    public function memberRoles(): array
    {
        $central = $this->centralConnection();
        $assigned = Schema::connection($central->getName())->hasTable('rbac_role_assignments')
            ? $central->table('rbac_role_assignments')
                ->where('subject_type', RoleAssignment::SUBJECT_USER)
                ->whereNull('project_id')
                ->pluck('role', 'subject_id')->all()
            : [];

        $hasAdminColumn = Schema::connection($central->getName())->hasColumn('users', 'is_admin');
        $users = $central->table('users')->orderBy('id')->get($hasAdminColumn ? ['id', 'username', 'is_admin'] : ['id', 'username']);
        $default = app(AccessManager::class)->defaultRole();

        $roles = [];
        foreach ($users as $user) {
            $roles[$user->id] = $assigned[(string) $user->id]
                ?? (($user->is_admin ?? false) ? PermissionCatalog::OWNER : $default);
        }

        if ($roles && ! in_array(PermissionCatalog::OWNER, $roles, true)) {
            $admin = $users->first(fn ($u) => (bool) ($u->is_admin ?? false))
                ?? $users->firstWhere('username', config('app.admin.username'))
                ?? $users->first();
            $roles[$admin->id] = PermissionCatalog::OWNER;
        }

        return $roles;
    }

    private function assignMembers(Tenant $tenant): void
    {
        $now = now();
        $rows = [];
        foreach ($this->memberRoles() as $userId => $role) {
            $rows[] = ['tenant_id' => (string) $tenant->getTenantKey(), 'user_id' => $userId, 'role' => $role, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->centralConnection()->table('tenant_users')->insertOrIgnore($chunk);
        }

        $this->say('line', '  '.count($rows).' member(s).');
    }

    /** The members' copies in the tenant schema (what the central → tenant sync keeps up to date). */
    private function syncUsers(Tenant $tenant): void
    {
        $userIds = $this->centralConnection()->table('tenant_users')
            ->where('tenant_id', $tenant->getTenantKey())->pluck('user_id');

        TenantSchema::inTenant($tenant, function () use ($userIds) {
            foreach ($userIds->chunk(self::CHUNK) as $chunk) {
                foreach (CentralSyncableUser::whereIn('id', $chunk->all())->get() as $central) {
                    TenantLocalUser::withoutEvents(fn () => TenantLocalUser::updateOrCreate(
                        ['id' => $central->getKey()],
                        $central->only($central->getSyncedAttributeNames()),
                    ));
                }
            }
        });

        $this->say('line', '  '.$userIds->count().' account(s) synced.');
    }

    /**
     * Owners and admins of the single organization who are no platform administrators yet.
     *
     * @return list<string> usernames
     */
    public function administratorsToPromote(): array
    {
        $central = $this->centralConnection();
        if (! Schema::connection($central->getName())->hasColumn('users', 'is_admin')) {
            return [];
        }

        $ids = array_keys(array_filter($this->memberRoles(), fn (string $role) => in_array($role, self::PROMOTED_ROLES, true)));

        return $central->table('users')->whereIn('id', $ids)->where('is_admin', false)->orderBy('id')->pluck('username')->all();
    }

    /** @return list<string> usernames promoted */
    public function promoteAdministrators(): array
    {
        $usernames = $this->administratorsToPromote();
        if ($usernames) {
            $this->centralConnection()->table('users')->whereIn('username', $usernames)->update(['is_admin' => true]);
        }

        $this->say('line', $usernames ? '  Platform administrators: '.implode(', ', $usernames).'.' : '  No account to promote.');

        return $usernames;
    }

    // ── Regions ─────────────────────────────────────────────────────────────

    /**
     * Quota of the tenant on a region: the region's capacity (and at most the organization
     * ceiling) — the organization keeps what it had. 0 (unknown capacity) = no limit.
     *
     * @return array{cpu_quota_m: int, memory_quota_mb: int}
     */
    public static function quotaFor(CentralSyncableRegion $region): array
    {
        $cpu = (int) $region->total_cpu_m;
        $memory = (int) $region->total_memory_mb;
        if ($ceiling = OrganizationCeiling::cpuMillicores()) {
            $cpu = $cpu > 0 ? min($cpu, $ceiling) : $ceiling;
        }
        if ($ceiling = OrganizationCeiling::memoryMb()) {
            $memory = $memory > 0 ? min($memory, $ceiling) : $ceiling;
        }

        return ['cpu_quota_m' => max(0, $cpu), 'memory_quota_mb' => max(0, $memory)];
    }

    private function assignRegions(Tenant $tenant): void
    {
        $regions = CentralSyncableRegion::whereNull('owner_tenant_id')->get();
        $attached = $tenant->regions()->pluck('knative_regions.id')->map(fn ($id) => (string) $id)->all();

        foreach ($regions as $region) {
            if (! in_array((string) $region->getKey(), $attached, true)) {
                $tenant->regions()->attach($region->getKey(), self::quotaFor($region));
            }
        }

        // The tenant's copies (kubeconfig stays central), as the central → tenant sync writes them
        TenantSchema::inTenant($tenant, function () use ($regions) {
            foreach ($regions as $region) {
                TenantLocalRegion::withoutEvents(fn () => TenantLocalRegion::updateOrCreate(
                    ['id' => $region->getKey()],
                    $region->only($region->getSyncedAttributeNames()),
                ));
            }
        });

        $this->say('line', '  '.$regions->count().' region(s) assigned.');
    }

    // ── Tables ──────────────────────────────────────────────────────────────

    /**
     * Tables of the tenant's schema that receive central rows, in foreign-key order, with the
     * columns both sides have.
     *
     * @return array<string, array{columns: list<string>, central_only: list<string>, missing_required: list<string>}>
     */
    public function copyPlan(Tenant $tenant): array
    {
        $central = $this->centralConnection();
        $centralSchema = Schema::connection($central->getName());

        return TenantSchema::inTenant($tenant, function () use ($tenant, $centralSchema) {
            $connection = DB::connection();
            $schema = Schema::connection($connection->getName());
            $prefix = $this->schemaPrefix($tenant, $connection);

            $tables = array_values(array_diff($schema->getTableListing($prefix === '' ? null : rtrim($prefix, '.'), false), self::NOT_COPIED));
            $plan = [];
            $dependsOn = [];

            foreach ($tables as $table) {
                if (! $centralSchema->hasTable($table)) {
                    continue;
                }

                $tenantColumns = $schema->getColumns($prefix.$table);
                $centralColumns = array_column($centralSchema->getColumns($table), 'name');
                $names = array_column($tenantColumns, 'name');

                $plan[$table] = [
                    'columns' => array_values(array_intersect($centralColumns, $names)),
                    'central_only' => array_values(array_diff($centralColumns, $names)),
                    // NOT NULL without default and not sent: the insert would fail
                    'missing_required' => array_values(array_map(fn ($c) => $c['name'], array_filter(
                        $tenantColumns,
                        fn ($c) => ! $c['nullable'] && $c['default'] === null && ! $c['auto_increment'] && ! in_array($c['name'], $centralColumns, true),
                    ))),
                ];
                $dependsOn[$table] = array_values(array_unique(array_filter(
                    array_column($schema->getForeignKeys($prefix.$table), 'foreign_table'),
                    fn ($t) => $t !== $table,
                )));
            }

            // Parents first (a table whose parent is not copied has no dependency to wait for)
            $ordered = [];
            $visit = function (string $table, array $path) use (&$visit, &$ordered, $dependsOn, $plan) {
                if (isset($ordered[$table]) || isset($path[$table]) || ! isset($plan[$table])) {
                    return;
                }
                $path[$table] = true;
                foreach ($dependsOn[$table] ?? [] as $parent) {
                    $visit($parent, $path);
                }
                $ordered[$table] = $plan[$table];
            };
            foreach (array_keys($plan) as $table) {
                $visit($table, []);
            }

            return $ordered;
        });
    }

    private function copyTables(Tenant $tenant): void
    {
        $this->prepareBillingTables($tenant);
        $plan = $this->copyPlan($tenant);
        $failed = [];

        foreach ($plan as $table => $info) {
            $state = $this->state();
            if (array_key_exists($table, $state['tables'] ?? [])) {
                continue;
            }

            if ($info['missing_required'] !== []) {
                $failed[$table] = 'required column(s) without a central value: '.implode(', ', $info['missing_required']);

                continue;
            }

            try {
                $copied = $this->copyTable($tenant, $table, $info['columns']);
            } catch (\Throwable $e) {
                $failed[$table] = $e->getMessage();

                continue;
            }

            $state = $this->state();
            $state['tables'][$table] = $copied;
            $this->saveState($state);

            if ($copied > 0) {
                $this->say('line', "  {$table}: {$copied} row(s)");
            }
        }

        if ($failed) {
            foreach ($failed as $table => $reason) {
                $this->say('warn', "  {$table}: not copied — {$reason}");
            }

            throw new RuntimeException('Tables not copied: '.implode(', ', array_keys($failed)).'. Fix the cause and run the command again; it continues with them.');
        }
    }

    /**
     * The central rows of $table that are copied.
     *
     * @param  list<string>  $columns
     */
    private function sourceQuery(string $table, array $columns = ['*']): Builder
    {
        $source = $this->centralConnection()->table($table)->select($columns);
        if ($table === 'rbac_role_assignments') {
            // Organization-wide roles of accounts are the tenant memberships (step members)
            $source->where(fn ($q) => $q->where('subject_type', '!=', RoleAssignment::SUBJECT_USER)->orWhereNotNull('project_id'));
        }

        return $source;
    }

    /** @param list<string> $columns */
    private function copyTable(Tenant $tenant, string $table, array $columns): int
    {
        $key = $this->singleKey($this->centralConnection(), $table, $columns);
        $source = $this->sourceQuery($table, $columns);

        return TenantSchema::inTenant($tenant, function () use ($tenant, $table, $columns, $source, $key) {
            $connection = DB::connection();
            $target = $this->schemaPrefix($tenant, $connection).$table;
            $copied = 0;

            $connection->transaction(function () use ($connection, $target, $source, $key, $columns, &$copied) {
                $write = function ($rows) use ($connection, $target, &$copied) {
                    $rows = $rows->map(fn ($row) => (array) $row)->all();
                    if ($rows) {
                        $connection->table($target)->insertOrIgnore($rows);
                        $copied += count($rows);
                    }
                };

                if ($key !== null) {
                    $last = null;
                    do {
                        $page = (clone $source)
                            ->when($last !== null, fn ($q) => $q->where($key, '>', $last))
                            ->orderBy($key)->limit(self::CHUNK)->get();
                        $write($page);
                        $last = $page->last()?->{$key};
                    } while ($page->count() === self::CHUNK);
                } else {
                    $offset = 0;
                    do {
                        $query = clone $source;
                        foreach ($columns as $column) {
                            $query->orderBy($column);
                        }
                        $page = $query->offset($offset)->limit(self::CHUNK)->get();
                        $write($page);
                        $offset += self::CHUNK;
                    } while ($page->count() === self::CHUNK);
                }
            });

            $this->resetSequences($connection, $target);

            return $copied;
        });
    }

    /**
     * The single-column primary key of a central table when it is copied (keyset paging), else null.
     *
     * @param  list<string>  $columns
     */
    private function singleKey(Connection $central, string $table, array $columns): ?string
    {
        foreach (Schema::connection($central->getName())->getIndexes($table) as $index) {
            if ($index['primary'] && count($index['columns']) === 1 && in_array($index['columns'][0], $columns, true)) {
                return $index['columns'][0];
            }
        }

        return null;
    }

    /** PostgreSQL: serial / identity columns continue after the copied ids. */
    private function resetSequences(Connection $connection, string $table): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (Schema::connection($connection->getName())->getColumns($table) as $column) {
            if (! $column['auto_increment']) {
                continue;
            }

            $quoted = implode('.', array_map(fn ($part) => '"'.str_replace('"', '""', $part).'"', explode('.', $table)));
            $name = '"'.str_replace('"', '""', $column['name']).'"';
            $connection->statement(
                "SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX({$name}) FROM {$quoted}), 0) + 1, false)",
                [$quoted, $column['name']],
            );
        }
    }

    /** "tenant_<id>." on PostgreSQL (tables of the tenant's own schema), "" otherwise. */
    private function schemaPrefix(Tenant $tenant, Connection $connection): string
    {
        return $connection->getDriverName() === 'pgsql' ? $tenant->database()->getName().'.' : '';
    }

    // ── Settings, central rows, files ───────────────────────────────────────

    private function copySettings(Tenant $tenant): void
    {
        $rows = $this->centralConnection()->table('settings')->whereIn('key', self::ORGANIZATION_SETTINGS)->get()
            ->map(fn ($row) => (array) $row)->all();

        TenantSchema::inTenant($tenant, function () use ($tenant, $rows) {
            if ($rows) {
                DB::table($this->schemaPrefix($tenant, DB::connection()).'settings')->insertOrIgnore($rows);
            }
        });

        $this->say('line', '  '.count($rows).' organization setting(s).');
    }

    /**
     * API tokens of the single organization (not sign-in sessions) work in the tenant only, as the
     * tokens created in a tenant do — a promoted administrator's CI token keeps acting in the
     * organization instead of outside of it.
     */
    public function tokensToBind(): int
    {
        $central = $this->centralConnection();
        if (! Schema::connection($central->getName())->hasColumn('personal_access_tokens', 'tenant_id')) {
            return 0;
        }

        return $central->table('personal_access_tokens')->whereNull('tenant_id')->whereNotIn('name', LoginTokens::NAMES)->count();
    }

    private function adoptCentralRows(Tenant $tenant): void
    {
        $tenantId = (string) $tenant->getTenantKey();
        $central = $this->centralConnection();

        foreach (TenantCentralTables::adopt($central->getName(), $tenantId) as $table => $count) {
            if ($count > 0) {
                $this->say('line', "  {$table}: {$count} row(s)");
            }
        }

        if ($this->tokensToBind() > 0) {
            $count = $central->table('personal_access_tokens')->whereNull('tenant_id')->whereNotIn('name', LoginTokens::NAMES)
                ->update(['tenant_id' => $tenantId]);
            $this->say('line', "  API tokens: {$count}");
        }
    }

    // ── Billing ─────────────────────────────────────────────────────────────

    /**
     * An installation that used billing and has it off now: the tenant gets the billing tables
     * anyway (its tenant migrations run only while the module is on), so the step tables copies
     * its allocations and history into it.
     */
    private function prepareBillingTables(Tenant $tenant): void
    {
        if (! class_exists(BillingSchema::class) || ! Schema::connection($this->centralConnection()->getName())->hasTable('billing_fixed_requests')) {
            return;
        }

        TenantSchema::inTenant($tenant, function () {
            if (! Schema::hasTable('billing_fixed_requests')) {
                BillingSchema::create(DB::connection()->getSchemaBuilder(), true);
            }
        });
    }

    /**
     * From the conversion day on every project is metered and billed in exactly one place, the
     * tenant (docs/tenancy.md#billing-after-the-conversion):
     *
     *  - the converted projects, model services and bindings are recorded (state converted,
     *    ConvertedOrganization): central metering and billing skip them from today;
     *  - their open central bills end yesterday (the close job closes them; one starting today
     *    goes), their central allocations end yesterday, pending requests are cancelled there —
     *    the tenant has the copies;
     *  - allocations in effect get their central copy for the platform → tenant bill, which bills
     *    the tenant from today;
     *  - in the tenant: the copied open bills go (its own start today, with ids of their own), it
     *    bills from today (bills_from); the platform's webhook settings and event log were not
     *    copied (NOT_COPIED).
     */
    private function handOverBilling(Tenant $tenant): void
    {
        $central = $this->centralConnection();
        $centralSchema = Schema::connection($central->getName());
        $tenantId = (string) $tenant->getTenantKey();
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $ids = [];
        foreach (self::CONVERTED_TABLES as $table) {
            if ($centralSchema->hasTable($table)) {
                $ids[$table] = $central->table($table)->pluck('id')->map(fn ($id) => (string) $id)->all();
            }
        }
        $projects = $ids['projects'] ?? [];
        $state = $this->state();
        $state['converted'] = ['on' => $today, 'tenant_id' => $tenantId, 'ids' => array_values(array_merge(...array_values($ids ?: [[]])))];
        $this->saveState($state);

        if ($centralSchema->hasTable('billing_bills')) {
            foreach (array_chunk($projects, self::CHUNK) as $chunk) {
                $open = $central->table('billing_bills')->where('status', 'open')->where('kind', 'project')->whereIn('project_id', $chunk)->get(['id', 'period_start', 'period_end']);
                foreach ($open as $bill) {
                    if (substr((string) $bill->period_start, 0, 10) >= $today) {
                        $central->table('billing_bills')->where('id', $bill->id)->delete();
                    } elseif (substr((string) $bill->period_end, 0, 10) >= $today) {
                        $central->table('billing_bills')->where('id', $bill->id)->update(['period_end' => $yesterday, 'updated_at' => now()]);
                    }
                }
            }
        }

        $copies = 0;
        if ($centralSchema->hasTable('billing_fixed_requests')) {
            foreach (array_chunk($projects, self::CHUNK) as $chunk) {
                $own = $central->table('billing_fixed_requests')->whereNull('tenant_id')->whereIn('project_id', $chunk);
                foreach ((clone $own)->where('status', 'approved')->whereNull('active_until')->get() as $allocation) {
                    $central->table('billing_fixed_requests')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'source_id' => $allocation->id, 'created_at' => now(), 'updated_at' => now()]
                        + array_diff_key((array) $allocation, array_flip(['id', 'tenant_id', 'source_id', 'created_at', 'updated_at'])));
                    $copies++;
                    $central->table('billing_fixed_requests')->where('id', $allocation->id)->update([
                        'status' => 'ended',
                        'active_until' => max($yesterday, substr((string) $allocation->active_from, 0, 10)),
                        'updated_at' => now(),
                    ]);
                }
                (clone $own)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now(), 'decision_reason' => __('billing.project_moved_to_tenant'), 'updated_at' => now()]);
            }
        }

        TenantSchema::inTenant($tenant, function () use ($tenant, $today) {
            $prefix = $this->schemaPrefix($tenant, DB::connection());
            if (Schema::hasTable('billing_bills')) {
                DB::table($prefix.'billing_bills')->where('status', 'open')->delete();
            }
            if (Schema::hasTable('billing_settings')) {
                foreach (['bills_from' => $today, 'started_on' => $today] as $key => $value) {
                    DB::table($prefix.'billing_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });

        $this->say('line', '  '.count($projects).' project(s) metered and billed in the tenant from '.$today.($copies > 0 ? ", {$copies} allocation(s) billed to the tenant by the platform" : '').'.');
    }

    /**
     * Where the organization's repositories are and go: the internal repository (one working tree,
     * TenantAwareGitService) and the bare repositories behind the git remotes (ProjectRemote).
     *
     * @return array{internal: array{from: string, to: string, move: bool}, remotes: array{from: string, to: string, move: bool}}
     */
    public function gitMoves(string $slug): array
    {
        $base = storage_path('git');
        $remotes = app(ProjectRemote::class)->baseDir();

        return [
            'internal' => ['from' => $base, 'to' => $base.'/'.$slug, 'move' => is_dir($base.'/.git')],
            'remotes' => ['from' => $remotes.'/_', 'to' => $remotes.'/'.$slug, 'move' => is_dir($remotes.'/_')],
        ];
    }

    private function moveGitStorage(Tenant $tenant): void
    {
        $slug = (string) $tenant->slug;
        $moves = $this->gitMoves($slug);

        // Under the repository's lock: no commit lands while its files move (GitService)
        if ($moves['internal']['move']) {
            app(GitService::class)->exclusive(fn () => $this->moveInternalRepository($moves['internal']['from'], $moves['internal']['to']));
        }

        if ($moves['remotes']['move']) {
            $this->moveDirectoryContents($moves['remotes']['from'], $moves['remotes']['to'], []);
            @rmdir($moves['remotes']['from']);
            $this->say('line', "  git remotes → {$moves['remotes']['to']}");
        }
    }

    /**
     * storage/git is one working tree (.git + a directory per project); the tenant's lives in
     * storage/git/<slug>. Every entry moves except the other tenants' directories; .git last, so
     * a run that stopped half-way still finds the repository to continue with.
     */
    private function moveInternalRepository(string $from, string $to): void
    {
        if (! is_dir($from.'/.git')) {
            return;
        }
        if (is_dir($to.'/.git')) {
            $this->say('warn', "  {$to} already has a repository: storage/git left in place (move it by hand)");

            return;
        }

        $slugs = Tenant::query()->pluck('slug')->map(fn ($s) => (string) $s)->all();
        $this->moveDirectoryContents($from, $to, array_merge($slugs, ['.git']));

        if (! @rename($from.'/.git', $to.'/.git')) {
            throw new RuntimeException("Could not move {$from}/.git to {$to}/.git");
        }

        $this->say('line', "  git repository → {$to}");
    }

    /** @param list<string> $keep entries of $from that stay */
    private function moveDirectoryContents(string $from, string $to, array $keep): void
    {
        if (! is_dir($to) && ! @mkdir($to, 0775, true) && ! is_dir($to)) {
            throw new RuntimeException("Could not create {$to}");
        }

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $keep, true)) {
                continue;
            }
            if (file_exists($to.'/'.$entry)) {
                $this->say('warn', "  {$to}/{$entry} exists: {$from}/{$entry} left in place");

                continue;
            }
            if (! @rename($from.'/'.$entry, $to.'/'.$entry)) {
                throw new RuntimeException("Could not move {$from}/{$entry} to {$to}/{$entry}");
            }
        }
    }

    /** Ticket module: attachments into the tenant's directory (TicketAttachments::namespaceExisting). */
    private function moveTicketAttachments(Tenant $tenant): void
    {
        $class = 'Modules\\Ticket\\Support\\TicketAttachments';
        if (! config('ticket.enabled', true) || ! class_exists($class)) {
            return;
        }

        TenantSchema::inTenant($tenant, function () use ($tenant, $class) {
            $connection = DB::connection();
            if (! Schema::connection($connection->getName())->hasTable($this->schemaPrefix($tenant, $connection).'support_tickets')) {
                return;
            }

            $moved = $class::namespaceExisting();
            $this->say('line', '  ticket attachments: '.json_encode($moved));
        });
    }

    // ── Dry run ─────────────────────────────────────────────────────────────

    /**
     * What run() would do, without changing anything: the tenant's tables are listed from a
     * temporary schema migrated like the tenant's and dropped again (or from the tenant's own
     * schema when a conversion is resumed).
     *
     * @return array<string, mixed>
     */
    public function plan(string $name, string $slug): array
    {
        $status = $this->status();
        $central = $this->centralConnection();
        $target = $this->target();
        $tenantSlug = $target ? (string) $target->slug : $slug;

        $roles = $this->memberRoles();
        $usernames = $central->table('users')->pluck('username', 'id')->all();
        $members = [];
        foreach ($roles as $id => $role) {
            $members[$usernames[$id] ?? (string) $id] = $role;
        }

        $tables = [];
        if (in_array($status, [self::STATUS_CONVERT, self::STATUS_RESUME], true)) {
            $copy = $target && TenantSchema::exists($target)
                ? $this->copyPlan($target)
                : $this->withPreviewTenant(fn (Tenant $preview) => $this->copyPlan($preview));

            foreach ($copy as $table => $info) {
                $tables[$table] = [
                    'rows' => $this->sourceQuery($table)->count(),
                    'central_only' => $info['central_only'],
                    'missing_required' => $info['missing_required'],
                ];
            }
        }

        return [
            'status' => $status,
            'tenant' => $target
                ? ['id' => (string) $target->getTenantKey(), 'name' => $target->name, 'slug' => $target->slug, 'exists' => true]
                : ['name' => $name, 'slug' => $slug, 'exists' => false],
            'steps_done' => array_keys($this->state()['steps'] ?? []),
            'members' => $members,
            'promote' => $this->administratorsToPromote(),
            'regions' => CentralSyncableRegion::whereNull('owner_tenant_id')->orderBy('name')->get()
                ->mapWithKeys(fn ($r) => [$r->name => self::quotaFor($r)])->all(),
            'tables' => $tables,
            'settings' => $central->table('settings')->whereIn('key', self::ORGANIZATION_SETTINGS)->pluck('key')->all(),
            'central_rows' => array_filter(TenantCentralTables::adoptableCounts($central->getName())),
            'api_tokens' => $this->tokensToBind(),
            'git' => $this->gitMoves($tenantSlug),
            'queue_streams' => $this->queueStreams($tenantSlug),
            'platform_images' => $this->platformImageProjects(),
        ];
    }

    /**
     * CaaS projects running an image uploaded as the platform (runr-uploads/platform/…): uploaded
     * images stay platform uploads, which platform runtimes use. Such a project keeps running and
     * redeploying with it; a change of its image settings asks for an image of the organization
     * (docs/images.md).
     *
     * @param  Tenant|null  $tenant  the converted tenant (its schema), null: the central rows
     * @return array<string, string> project name => image
     */
    public function platformImageProjects(?Tenant $tenant = null): array
    {
        $needle = '/'.trim((string) config('images.repository_prefix', 'runr-uploads'), '/').'/platform/';
        $read = function (Connection $connection, string $prefix) use ($needle): array {
            if (! Schema::connection($connection->getName())->hasTable($prefix.'container_configs')) {
                return [];
            }

            return $connection->table($prefix.'container_configs as c')
                ->join($prefix.'projects as p', 'p.id', '=', 'c.project_id')
                ->where('c.image', 'like', '%'.$needle.'%')
                ->pluck('c.image', 'p.name')->map(fn ($image) => (string) $image)->all();
        };

        if ($tenant === null) {
            return $read($this->centralConnection(), '');
        }

        return TenantSchema::inTenant($tenant, fn () => $read(DB::connection(), $this->schemaPrefix($tenant, DB::connection())));
    }

    /** Config tables of project types with a trigger (trigger_type / queue_stream columns) */
    private const TRIGGER_CONFIG_TABLES = ['xaas_configs', 'faas_configs', 'container_configs', 'aaas_configs', 'agent_flow_configs'];

    /**
     * Queue-triggered projects and their stream name before → after the conversion: inside an
     * organization a stream name carries the organization's slug (TenancyServiceOverridesTrait),
     * so the consumer reads the new name once the project is redeployed, and producers write to it.
     *
     * @param  Tenant|string  $tenant  the converted tenant (its schema), or the slug of the one to create (central rows)
     * @return array<string, array{from: string, to: string}> project name => streams
     */
    public function queueStreams(Tenant|string $tenant): array
    {
        $slug = $tenant instanceof Tenant ? (string) $tenant->slug : $tenant;
        $read = function (Connection $connection, string $prefix) use ($slug): array {
            $schema = Schema::connection($connection->getName());
            $streams = [];
            foreach (self::TRIGGER_CONFIG_TABLES as $table) {
                if (! $schema->hasTable($prefix.$table) || ! $schema->hasColumn($prefix.$table, 'queue_stream')) {
                    continue;
                }
                $rows = $connection->table($prefix.$table.' as c')
                    ->join($prefix.'projects as p', 'p.id', '=', 'c.project_id')
                    ->where('c.trigger_type', 'queue')
                    ->get(['p.name', 'c.queue_stream']);
                foreach ($rows as $row) {
                    $stream = (string) ($row->queue_stream ?: 'default-stream');
                    $streams[(string) $row->name] = ['from' => $stream, 'to' => "{$stream}-{$slug}"];
                }
            }

            return $streams;
        };

        if (! $tenant instanceof Tenant) {
            return $read($this->centralConnection(), '');
        }

        return TenantSchema::inTenant($tenant, fn () => $read(DB::connection(), $this->schemaPrefix($tenant, DB::connection())));
    }

    /**
     * @template T
     *
     * @param  \Closure(Tenant): T  $callback
     * @return T
     */
    private function withPreviewTenant(\Closure $callback): mixed
    {
        // Never saved: only its schema (tenant_setup-preview-…) exists, for the time of the callback
        $preview = new Tenant;
        $preview->setAttribute('id', 'setup-preview-'.Str::lower(Str::random(12)));
        $preview->setAttribute('slug', 'setup-preview');

        try {
            ($this->schemaCreator)($preview);

            return $callback($preview);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            try {
                if (TenantSchema::exists($preview)) {
                    $preview->database()->manager()->deleteDatabase($preview);
                }
            } catch (\Throwable) {
                // nothing to drop
            }
        }
    }

    private function say(string $level, string $message): void
    {
        ($this->log)($level, $message);
    }
}
