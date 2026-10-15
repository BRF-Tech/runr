<?php

namespace Modules\Tenancy\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Tenancy\Models\CentralSyncableUser;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantLocalUser;

class TenancySeeder extends Seeder
{
    public function run(): void
    {
        // Mark admin user as platform admin
        $admin = User::on('central')->where('username', 'admin')->first();
        if ($admin && ! $admin->is_admin) {
            $admin->is_admin = true;
            $admin->save();
        }

        // Test user
        $testUser = User::on('central')->firstOrCreate(
            ['username' => 'test'],
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => Hash::make('test123'),
            ]
        );

        // Tenants
        $demo = $this->createTenant('demo', 'Demo Tenant', 'demo');
        $test = $this->createTenant('test', 'Test Tenant', 'test');

        // Test user → developer on both tenants
        $this->assignUser($demo, $testUser, 'developer');
        $this->assignUser($test, $testUser, 'developer');

        // Sync users to tenant schemas
        $this->syncUsersToTenant($demo);
        $this->syncUsersToTenant($test);

        // Admin users (is_admin=true) don't need tenant assignment —
        // they see all tenants and operate in central mode by default.

        $this->command->info('Tenancy seeded: 2 tenants, test user assigned & synced.');
    }

    private function createTenant(string $id, string $name, string $slug): Tenant
    {
        $tenant = Tenant::firstOrCreate(
            ['id' => $id],
            ['name' => $name, 'slug' => $slug]
        );

        $this->ensureSchema($tenant);

        return $tenant;
    }

    private function ensureSchema(Tenant $tenant): void
    {
        $manager = $tenant->database()->manager();
        $dbName = $tenant->database()->getName();

        if (! $manager->databaseExists($dbName)) {
            $manager->createDatabase($tenant);
        }

        // Run tenant-only migrations (not central — tenant schema must not duplicate central tables)
        $tenant->run(function () {
            \Artisan::call('migrate', [
                '--path' => module_path('Tenancy', 'Database/TenantMigrations'),
                '--realpath' => true,
                '--force' => true,
            ]);
        });
    }

    private function assignUser(Tenant $tenant, User $user, string $role): void
    {
        if (! $tenant->users()->where('user_id', $user->id)->exists()) {
            $tenant->users()->attach($user->id, ['role' => $role]);
        }
    }

    private function syncUsersToTenant(Tenant $tenant): void
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
    }
}
