<?php

namespace Modules\Tenancy\Providers;

use App\Http\Middleware\AuthorizeApiRoute;
use App\Http\Middleware\NormalizeApiTags;
use App\Models\User;
use App\Services\WhiteLabel\BrandingService;
use App\Services\WhiteLabel\SignupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Tenancy\Http\Middleware\EnforcePlanLimits;
use Modules\Tenancy\Http\Middleware\IdentifyTenant;
use Modules\Tenancy\Jobs\DeliverBillingEventsJob;
use Modules\Tenancy\Jobs\PlanRenewalJob;
use Modules\Tenancy\Jobs\UsageThresholdJob;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\Billing\BillingEvents;
use Modules\Tenancy\Services\Billing\BillingSettings;
use Modules\Tenancy\Services\Billing\ChargeLineItemBuilder;
use Modules\Tenancy\Services\Billing\ChargeService;
use Modules\Tenancy\Services\Billing\CreditLedger;
use Modules\Tenancy\Services\Billing\PurchaseService;
use Modules\Tenancy\Services\Billing\UsageRating;
use Modules\Tenancy\Services\Billing\UsageThresholds;
use Modules\Tenancy\Services\Plans\PlanLimitService;
use Modules\Tenancy\Services\Plans\PlanQuotaService;
use Modules\Tenancy\Services\Plans\PlanService;
use Modules\Tenancy\Services\Plans\RegionQuotaAllocator;
use Modules\Tenancy\Services\Plans\SelfServiceOnboarding;
use Modules\Tenancy\Services\TenancyLoginService;

/**
 * White-label, multi-tenant part (docs/white-label.md) and billing (docs/billing.md): plans
 * (optional, off by default), charges, purchases and billing events for the operator's billing
 * system, self-service onboarding and per-tenant branding. Permissions are defined in
 * App\Providers\WhiteLabelServiceProvider.
 */
class PlanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([
            BillingSettings::class, BillingEvents::class, ChargeLineItemBuilder::class, UsageRating::class,
            CreditLedger::class, ChargeService::class, PurchaseService::class, UsageThresholds::class,
            RegionQuotaAllocator::class, PlanLimitService::class, PlanQuotaService::class, PlanService::class,
            SelfServiceOnboarding::class,
        ] as $service) {
            $this->app->scoped($service);
        }
    }

    public function boot(): void
    {
        if (! config('tenancy.enabled')) {
            return;
        }

        $this->registerRoutes();
        $this->registerResolvers();

        NormalizeApiTags::registerTags([
            'Plan' => 'Tenants > Plans',
            'BillingSettings' => 'Tenants > Plans',
            'TenantSubscription' => 'Tenants > Plans',
            'BillingTenant' => 'Billing',
            'Charge' => 'Billing',
            'Usage' => 'Billing',
            'Purchase' => 'Billing',
            'BillingEvent' => 'Billing',
            'OwnBilling' => 'Billing',
            'Onboarding' => 'Tenants > Onboarding',
            'TenantBranding' => 'Branding',
        ]);

        // After every provider booted: the api group already holds IdentifyTenant (pushed by
        // TenancyServiceProvider), these run behind it
        $this->app->booted(function () {
            $this->registerMiddleware();
            $this->registerScheduledJobs();
        });
    }

    protected function registerRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api')
            ->group(module_path('Tenancy', 'routes/white-label.php'));

        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api')
            ->group(module_path('Tenancy', 'routes/billing.php'));

        Route::middleware('api')
            ->prefix('api')
            ->group(module_path('Tenancy', 'routes/white-label-public.php'));
    }

    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];
        $priority = $router->middlewarePriority;

        // After the route's permission check (a user without the permission gets 403, not a plan
        // limit), else right after tenant identification
        $at = array_search(AuthorizeApiRoute::class, $priority, true);
        if ($at === false) {
            $at = array_search(IdentifyTenant::class, $priority, true);
        }

        if ($at !== false) {
            array_splice($priority, $at + 1, 0, [EnforcePlanLimits::class]);
            $router->middlewarePriority = $priority;
        }

        $router->pushMiddlewareToGroup('api', EnforcePlanLimits::class);
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

    protected function registerScheduledJobs(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $schedule->job(new PlanRenewalJob)
            ->dailyAt('01:30')
            ->name('plan-renewal')
            ->withoutOverlapping(60);

        $schedule->job(new UsageThresholdJob)
            ->hourlyAt(20)
            ->name('billing-usage-thresholds')
            ->withoutOverlapping(60);

        // Retries of billing webhook deliveries (new events are dispatched when they are recorded)
        $schedule->job(new DeliverBillingEventsJob)
            ->everyMinute()
            ->name('billing-event-delivery');

        $schedule->call(fn () => $this->app->make(BillingEvents::class)->prune())
            ->dailyAt('03:10')
            ->name('billing-event-retention')
            ->withoutOverlapping(60);
    }
}
