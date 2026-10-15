<?php

namespace Modules\Tenancy\Console;

use App\Models\KnativeRegion;
use App\Models\User;
use Illuminate\Console\Command;
use Modules\Tenancy\Models\CentralSyncableUser;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantLocalUser;
use Modules\Tenancy\Models\TenantUser;

class TenancySetupCommand extends Command
{
    protected $signature = 'tenancy:setup';

    protected $description = 'Initialize multi-tenancy: create default tenant and migrate existing data';

    public function handle(): int
    {
        if (! config('tenancy.enabled')) {
            $this->error('TENANCY_ENABLED is not set to true. Aborting.');

            return self::FAILURE;
        }

        if (Tenant::exists()) {
            $this->info('Tenants already exist. Setup is idempotent — skipping creation.');

            return $this->runPendingMigrations();
        }

        $this->info('Creating default tenant...');
        $tenant = $this->createDefaultTenant();

        $this->info('Creating tenant schema and running migrations...');
        $this->createTenantSchema($tenant);

        $this->info('Assigning all users to default tenant...');
        $this->assignUsers($tenant);

        $this->info('Syncing users to tenant schema...');
        $this->syncUsersToTenant($tenant);

        $this->info('Migrating existing data to tenant schema...');
        $this->migrateExistingData($tenant);

        $this->info('Assigning all regions to default tenant...');
        $this->assignRegions($tenant);

        $this->info('Tenancy setup complete!');

        return self::SUCCESS;
    }

    protected function createDefaultTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Default',
            'slug' => 'default',
        ]);
    }

    protected function assignUsers(Tenant $tenant): void
    {
        $users = User::on('central')->get();
        $first = true;

        foreach ($users as $user) {
            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => $first ? 'owner' : 'admin',
            ]);
            $first = false;
        }

        $this->info("  Assigned {$users->count()} user(s).");
    }

    protected function createTenantSchema(Tenant $tenant): void
    {
        $tenant->database()->manager()->createDatabase($tenant);

        $tenant->run(function () {
            \Artisan::call('migrate', [
                '--path' => database_path('migrations'),
                '--realpath' => true,
                '--force' => true,
            ]);
        });
    }

    protected function migrateExistingData(Tenant $tenant): void
    {
        $tables = [
            'projects',
            'versions',
            'deployments',
            'xaas_configs',
            'faas_configs',
            'container_configs',
            'gateways',
            'gateway_routes',
            'project_domains',
        ];

        $tenant->run(function () use ($tables) {
            foreach ($tables as $table) {
                $rows = \DB::connection('central')->table($table)->get();

                if ($rows->isEmpty()) {
                    continue;
                }

                foreach ($rows->chunk(100) as $chunk) {
                    \DB::table($table)->insert(
                        $chunk->map(fn ($row) => (array) $row)->toArray()
                    );
                }

                $this->info("  Migrated {$rows->count()} row(s) from {$table}.");
            }
        });
    }

    protected function syncUsersToTenant(Tenant $tenant): void
    {
        $userIds = $tenant->users()->pluck('users.id');

        $tenant->run(function () use ($userIds) {
            foreach ($userIds as $userId) {
                $central = CentralSyncableUser::find($userId);
                if (! $central) {
                    continue;
                }

                TenantLocalUser::withoutEvents(function () use ($central) {
                    TenantLocalUser::updateOrCreate(
                        ['id' => $central->getKey()],
                        $central->only($central->getSyncedAttributeNames())
                    );
                });
            }
        });

        $this->info("  Synced {$userIds->count()} user(s).");
    }

    protected function assignRegions(Tenant $tenant): void
    {
        $regions = KnativeRegion::all();

        foreach ($regions as $region) {
            $tenant->regions()->syncWithoutDetaching([
                $region->id => [
                    'cpu_quota_m' => $region->total_cpu_m,
                    'memory_quota_mb' => $region->total_memory_mb,
                ],
            ]);
        }

        $this->info("  Assigned {$regions->count()} region(s).");
    }

    protected function runPendingMigrations(): int
    {
        $this->info('Running pending tenant migrations...');

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($tenant) {
                \Artisan::call('migrate', [
                    '--path' => database_path('migrations'),
                    '--realpath' => true,
                    '--force' => true,
                ]);
                $this->info("  Migrated tenant: {$tenant->slug}");
            });
        }

        return self::SUCCESS;
    }
}
