<?php

namespace Modules\Tenancy\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Services\TenantSchema;

class CreateTenantCommand extends Command
{
    protected $signature = 'tenancy:create-tenant
        {name : Tenant name}
        {slug : Unique slug (URLs, git paths; the schema is named after the tenant id)}
        {--owner-email= : Email of the owner user}';

    protected $description = 'Create a new tenant with its own PostgreSQL schema';

    public function handle(): int
    {
        if (! config('tenancy.enabled')) {
            $this->error('TENANCY_ENABLED is not set to true.');

            return self::FAILURE;
        }

        $name = $this->argument('name');
        $slug = $this->argument('slug');

        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("Tenant with slug '{$slug}' already exists.");

            return self::FAILURE;
        }

        $this->info("Creating tenant: {$name} ({$slug})...");

        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
        ]);

        // Create schema + run every module's tenant migrations
        TenantSchema::create($tenant);

        $this->info('Schema created and migrations applied.');

        // Assign owner if specified
        if ($email = $this->option('owner-email')) {
            $user = User::on('central')->where('email', $email)->first();

            if ($user) {
                TenantUser::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                ]);
                $this->info("Owner assigned: {$email}");
            } else {
                $this->warn("User with email '{$email}' not found.");
            }
        }

        $this->info("Tenant '{$name}' created successfully! ID: {$tenant->id}");

        return self::SUCCESS;
    }
}
