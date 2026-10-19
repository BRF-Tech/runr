<?php

namespace Modules\Tenancy\Providers;

use App\Http\Middleware\NormalizeApiTags;
use App\Models\User;
use App\Services\WhiteLabel\BrandingService;
use App\Services\WhiteLabel\SignupService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\Onboarding\RegionQuotaAllocator;
use Modules\Tenancy\Services\Onboarding\SelfServiceOnboarding;
use Modules\Tenancy\Services\TenancyLoginService;

/**
 * White-label, multi-tenant part (docs/white-label.md): self-service onboarding of the tenants
 * created by signup and per-tenant branding. Permissions are defined in
 * App\Providers\WhiteLabelServiceProvider. Billing is the Billing module (docs/billing.md).
 */
class TenantWhiteLabelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RegionQuotaAllocator::class);
        $this->app->scoped(SelfServiceOnboarding::class);
    }

    public function boot(): void
    {
        if (! config('tenancy.enabled')) {
            return;
        }

        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api')
            ->group(module_path('Tenancy', 'routes/white-label.php'));

        $this->registerResolvers();

        NormalizeApiTags::registerTags([
            'Onboarding' => 'Tenants > Onboarding',
            'TenantBranding' => 'Branding',
        ]);
    }

    protected function registerResolvers(): void
    {
        // Branding of a tenant on the public login page (?tenant=<slug>)
        BrandingService::resolveTenantsUsing(function (string $slug) {
            $tenant = Tenant::query()->where('slug', $slug)->first(['id', 'name', 'slug']);

            return $tenant ? ['id' => (string) $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->name] : null;
        });

        // The verified signup signs in with the tenants it belongs to (same as a login)
        SignupService::loginDataUsing(fn ($user) => app(TenancyLoginService::class)->loginData(User::on('central')->find($user->id) ?? $user));
    }
}
