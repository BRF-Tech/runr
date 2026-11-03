<?php

namespace Modules\Tenancy\Providers;

use App\Http\Middleware\NormalizeApiTags;
use App\Models\User;
use App\Services\Captcha\CaptchaGate;
use App\Services\WhiteLabel\BrandingService;
use App\Services\WhiteLabel\SignupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantInvitation;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Services\Onboarding\RegionQuotaAllocator;
use Modules\Tenancy\Services\Onboarding\SelfServiceOnboarding;
use Modules\Tenancy\Services\TenancyLoginService;

/**
 * White-label, multi-tenant part (docs/white-label.md): self-service onboarding of the tenants
 * created by signup, per-tenant branding and the tenants' own bot protection keys. Permissions are defined in
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
            'TenantCaptcha' => 'Bot protection',
        ]);
    }

    protected function registerResolvers(): void
    {
        // Branding of a tenant on the public login page (?tenant=<slug>)
        BrandingService::resolveTenantsUsing(function (string $slug) {
            $tenant = Tenant::query()->where('slug', $slug)->first(['id', 'name', 'slug']);

            return $tenant ? ['id' => (string) $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->name] : null;
        });

        // Bot protection of an invitation link: the invited organization's own keys, when it has some
        CaptchaGate::resolveTenantsUsing(function (Request $request) {
            $token = $request->route('token');
            if (! is_string($token) || ! $request->is('api/invitations/*')) {
                return null;
            }

            return ($id = TenantInvitation::findByPlainToken($token)?->getAttribute('tenant_id')) ? (string) $id : null;
        });

        // …and the member check of a sign-in naming it (tenant_users; a platform administrator who is
        // not one is not a member): anyone else's sign-in, checked with its keys all the same, fails
        // like a wrong password — its keys never let anyone else in
        CaptchaGate::resolveMembersUsing(fn (string $tenantId, User $user): bool => TenantUser::query()
            ->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->exists());

        // The verified signup signs in with the tenants it belongs to (same as a login)
        SignupService::loginDataUsing(fn ($user) => app(TenancyLoginService::class)->loginData(User::on('central')->find($user->id) ?? $user));
    }
}
