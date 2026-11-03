<?php

namespace App\Providers;

use App\Http\Middleware\NormalizeApiTags;
use App\Services\Captcha\CaptchaConfigAlert;
use App\Services\Captcha\CaptchaFallbackAlert;
use App\Services\Captcha\CaptchaGate;
use App\Services\Captcha\CaptchaMetrics;
use App\Services\Captcha\CaptchaNotifier;
use App\Services\Captcha\CaptchaSettings;
use App\Services\Captcha\CaptchaVerifier;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Bot protection (docs/security.md#bot-protection): the CAPTCHA of the anonymous forms with its
 * local proof-of-work fallback. The route middleware `captcha.verify` is aliased in
 * bootstrap/app.php; the organizations' own keys (multi-tenant) are the Tenancy module's
 * (TenantWhiteLabelServiceProvider).
 */
class CaptchaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: fresh per request / queued job (Octane, Horizon keep the container alive)
        $this->app->scoped(CaptchaSettings::class);
        $this->app->scoped(CaptchaVerifier::class);
        $this->app->scoped(CaptchaMetrics::class);
        $this->app->scoped(CaptchaNotifier::class);
        $this->app->scoped(CaptchaFallbackAlert::class);
        $this->app->scoped(CaptchaConfigAlert::class);
        $this->app->scoped(CaptchaGate::class);
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/captcha.php'));

        NormalizeApiTags::registerTags(['Captcha' => 'Bot protection']);
    }
}
