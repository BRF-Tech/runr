<?php

namespace Modules\Tenancy\Listeners;

use App\Events\UserRegistered;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Services\Plans\SelfServiceOnboarding;

class CreateTenantOnRegistration
{
    public function handle(UserRegistered $event): void
    {
        if (! config('tenancy.enabled')) {
            return;
        }

        $onboarding = app(SelfServiceOnboarding::class);

        // The workspace name from the signup form; without one, a default name when self-service
        // signups get their own tenant (Settings → Signup → tenant_auto_create)
        $tenantName = trim((string) ($event->requestData['tenant_name'] ?? ''));
        if ($tenantName === '') {
            $tenantName = (string) $onboarding->defaultTenantName($event->user);
        }

        if ($tenantName === '') {
            return;
        }

        $slug = $this->generateUniqueSlug($tenantName);

        $tenant = Tenant::create([
            'name' => $tenantName,
            'slug' => $slug,
        ]);

        $this->provision($tenant);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $event->user->id,
            'role' => 'owner',
        ]);

        // Onboarding wizard + (plans on) the default plan
        $onboarding->tenantCreated($tenant, $event->user);
    }

    /**
     * Tenant schema + tenant migrations.
     */
    protected function provision(Tenant $tenant): void
    {
        $tenant->database()->manager()->createDatabase($tenant);

        $tenant->run(function () {
            \Artisan::call('migrate', config('tenancy.migration_parameters'));
        });
    }

    protected function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 1;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
