<?php

namespace App\Providers;

use App\Services\WhiteLabel\BrandingService;
use App\Services\WhiteLabel\CaptchaVerifier;
use App\Services\WhiteLabel\PlatformSettings;
use App\Services\WhiteLabel\SignupService;
use App\Services\WhiteLabel\SignupSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Address;

/**
 * White-label: branding, self-service signup (docs/white-label.md); the multi-tenant part (tenant
 * onboarding and branding) is Modules\Tenancy\Providers\TenantWhiteLabelServiceProvider. Billing is
 * the Billing module (docs/billing.md).
 */
class WhiteLabelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: fresh per request / queued job (Octane, Horizon keep the container alive)
        $this->app->scoped(PlatformSettings::class);
        $this->app->scoped(BrandingService::class);
        $this->app->scoped(SignupSettings::class);
        $this->app->scoped(CaptchaVerifier::class);
        $this->app->scoped(SignupService::class);
    }

    /**
     * Permissions (branding.manage, signup.manage) are catalog
     * permissions (config/permissions.php) and the routes are authorized by
     * config/route_permissions.php — nothing is defined here.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/white-label.php'));

        $this->brandMailSender();

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->call(fn () => app(SignupService::class)->prune())
                ->daily()
                ->name('pending-signups-prune')
                ->withoutOverlapping();
        });
    }

    /**
     * Mails sent with the install's default sender name go out under the brand's sender name
     * (branding email_from_name, default: the product name).
     */
    protected function brandMailSender(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event) {
            $defaultName = (string) config('mail.from.name', '');
            $defaultAddr = (string) config('mail.from.address', '');

            try {
                $product = app(BrandingService::class)->forMail()['mail_from_name'] ?? null;
            } catch (\Throwable) {
                return;
            }

            if (! $product || $product === $defaultName) {
                return;
            }

            $from = $event->message->getFrom();
            if (count($from) !== 1 || $from[0]->getAddress() !== $defaultAddr || $from[0]->getName() !== $defaultName) {
                return;
            }

            $event->message->from(new Address($defaultAddr, $product));
        });
    }
}
