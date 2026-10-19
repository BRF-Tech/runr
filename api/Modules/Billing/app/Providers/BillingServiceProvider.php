<?php

namespace Modules\Billing\Providers;

use App\Http\Middleware\NormalizeApiTags;
use App\Models\Project;
use App\Services\Bindings\BindingTenancy;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Jobs\CloseBillsJob;
use Modules\Billing\Jobs\DeliverBillingEventsJob;
use Modules\Billing\Listeners\BillingSubscriber;
use Modules\Billing\Services\Admission;
use Modules\Billing\Services\BillArchive;
use Modules\Billing\Services\BillCalculator;
use Modules\Billing\Services\BillingActivity;
use Modules\Billing\Services\BillingEvents;
use Modules\Billing\Services\BillingNotifier;
use Modules\Billing\Services\BillingSettings;
use Modules\Billing\Services\BillService;
use Modules\Billing\Services\FixedRequests;
use Modules\Billing\Services\Presenter;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\RegionOffers;
use Modules\Billing\Services\Regions;
use Modules\Billing\Services\RegionTypes;
use Modules\Billing\Services\TenantBillService;
use Modules\Billing\Services\UsageReader;
use Modules\Tenancy\Support\TenantRegionShares;
use SharedSupport\TenantMigrationRegistry;

/**
 * Billing module (docs/billing.md): prices with effective dates, region billing types, fixed
 * resource requests and approvals, per-project monthly bills, platform → tenant bills, markups and
 * region offers, signed webhook events. Off unless BILLING_ENABLED=true; the core metering runs
 * either way.
 */
class BillingServiceProvider extends ServiceProvider
{
    protected string $name = 'Billing';

    /** Services kept for one request / job (scoped: Octane workers start each request fresh). */
    private const SERVICES = [
        Regions::class, PriceBook::class, RegionTypes::class, UsageReader::class, BillCalculator::class,
        Presenter::class, BillingSettings::class, BillingEvents::class, BillingNotifier::class, BillingActivity::class,
        FixedRequests::class, BillService::class, TenantBillService::class, RegionOffers::class, Admission::class,
        BillArchive::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(module_path($this->name, 'Config/config.php'), 'billing');

        foreach (self::SERVICES as $service) {
            $this->app->scoped($service);
        }
    }

    public function boot(): void
    {
        // Allocations end with their project whether billing is on or not: a project deleted or
        // moved while billing is off is not billed when it comes back
        self::wireAllocations();

        // The tables exist before billing is switched on, in the central schema and in every tenant
        // schema (tenancy:migrate): switching billing on later needs no migration of its own. The
        // entrypoint migrates every module's central migrations anyway.
        $this->loadMigrationsFrom(module_path($this->name, 'Database/Migrations'));
        TenantMigrationRegistry::register(module_path($this->name, 'Database/TenantMigrations'));

        if (! config('billing.enabled')) {
            // The days billing is off are not billed: the scheduler records them (BillingActivity)
            $this->app->booted(fn () => $this->app->make(Schedule::class)
                ->call(fn () => BillingActivity::markOff())
                ->hourly()
                ->name('billing-off-marker')
                ->withoutOverlapping(10));

            return;
        }

        $this->loadViewsFrom(module_path($this->name, 'resources/views'), 'billing');

        Route::prefix('api')->group(module_path($this->name, 'routes/api.php'));

        self::wire();

        NormalizeApiTags::registerTags([
            'Settings' => 'Billing',
            'Price' => 'Billing',
            'Region' => 'Billing',
            'Markup' => 'Billing',
            'RegionOffer' => 'Billing',
            'Bill' => 'Billing',
            'Usage' => 'Billing',
            'Rollup' => 'Billing',
            'MyBills' => 'Billing',
            'FixedRequest' => 'Billing',
            'Event' => 'Billing',
            'PlatformBill' => 'Billing',
            'TenantBill' => 'Billing',
            'LegacyRecord' => 'Billing',
            'ArchivedBill' => 'Billing',
            'ProjectBilling' => 'Billing',
        ]);

        $this->app->booted(fn () => $this->registerSchedule());
    }

    /**
     * Hooks into the core and the Tenancy module while billing is on (also called by the tests,
     * where the module's provider does not boot): deploy guard and config limiter of fixed
     * regions, acceptance of shared regions, listeners.
     */
    public static function wire(): void
    {
        ProjectAdmission::guardDeploys(fn (Project $project, DeployIntent $intent) => app(Admission::class)->guardDeploy($project, $intent));
        ProjectAdmission::limitConfigs(fn (Project $project, ?string $regionId, array $config) => app(Admission::class)->limitConfig($project, $regionId, $config));

        if (config('tenancy.enabled') && class_exists(TenantRegionShares::class)) {
            TenantRegionShares::requireAcceptanceWhen(fn () => true);
        }

        Event::subscribe(BillingSubscriber::class);
    }

    /**
     * Allocations end with their project — deleted, or moved to another region — whether billing
     * is on or off (a no-op where billing never ran: FixedRequests::endForProject()).
     */
    public static function wireAllocations(): void
    {
        Project::deleted(function (Project $project) {
            app(FixedRequests::class)->endForProject((string) $project->id);
        });
        Project::updated(function (Project $project) {
            $previous = $project->getOriginal('knative_region_id');
            if ($project->wasChanged('knative_region_id') && is_string($previous) && $previous !== '') {
                app(FixedRequests::class)->endForProject((string) $project->id, $previous);
            }
        });
    }

    /** Undo wire() (tests). */
    public static function unwire(): void
    {
        ProjectAdmission::reset();
        if (class_exists(TenantRegionShares::class)) {
            TenantRegionShares::requireAcceptanceWhen(null);
        }
    }

    private function registerSchedule(): void
    {
        $schedule = $this->app->make(Schedule::class);

        // Months that ended are closed after the usage of their last day was metered (usage-aggregation);
        // the job holds its own lock while it runs (CloseBillsJob)
        $schedule->job(new CloseBillsJob)
            ->dailyAt('02:30')
            ->name('billing-close')
            ->withoutOverlapping(120);

        // The days billing is on (BillingActivity: days it was off are not billed)
        $schedule->call(fn () => $this->app->make(BillingActivity::class)->touch())
            ->hourly()
            ->name('billing-heartbeat')
            ->withoutOverlapping(10);

        // Retries of webhook deliveries (new events are dispatched when they are recorded)
        $schedule->job(new DeliverBillingEventsJob)
            ->everyMinute()
            ->name('billing-event-delivery')
            ->withoutOverlapping(10);

        $schedule->call(function () {
            BindingTenancy::eachContext(fn () => app(BillingEvents::class)->prune());
        })
            ->dailyAt('03:10')
            ->name('billing-event-retention')
            ->withoutOverlapping(60);
    }
}
