<?php

namespace App\Http\Controllers\Api\Captcha;

use App\Http\Controllers\Api\Controller;
use App\Services\Captcha\CaptchaGate;
use App\Services\Captcha\CaptchaMetrics;
use App\Services\Captcha\CaptchaSettings;
use App\Services\WhiteLabel\BrandingService;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Bot protection (docs/security.md#bot-protection).
 *
 *  GET  /api/captcha/config?tenant=<slug>   public — {provider, site_key, fallback, forms, misconfigured}
 *  POST /api/captcha/local/challenge        public — {form, tenant?}: a challenge of the page's
 *                                           scope; 200 {challenge} while the provider cannot be
 *                                           reached or the configuration is wrong, 409 {captcha:
 *                                           "provider"} while it can (the widget is blocked in the
 *                                           browser), 404 with the protection off, 422 for a form
 *                                           the scope does not guard
 *  GET  /api/admin/captcha                  settings.manage (multi-tenant: platform administrators);
 *                                           `problem` names a configuration error
 *  PUT  /api/admin/captcha                  same — partial update, null = install default
 *  GET  /api/admin/captcha/metrics          same as GET /api/admin/captcha — Prometheus text
 */
class CaptchaController extends Controller
{
    public function __construct(private readonly CaptchaSettings $settings) {}

    /** @unauthenticated */
    public function config(Request $request, BrandingService $branding): JsonResponse
    {
        $tenant = $branding->findTenant(is_string($request->query('tenant')) ? $request->query('tenant') : null);

        return response()->json($this->settings->publicConfig($tenant['id'] ?? null))
            ->header('Cache-Control', 'public, max-age=30');
    }

    /** @unauthenticated */
    public function challenge(Request $request, CaptchaGate $gate): JsonResponse
    {
        $request->validate([
            'form' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tenant' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        return $gate->localChallengeResponse($request)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, CaptchaGate $gate, CaptchaMetrics $metrics): JsonResponse
    {
        $this->authorizeInstall($request);

        return response()->json($this->payload($gate, $metrics));
    }

    public function update(Request $request, CaptchaGate $gate, CaptchaMetrics $metrics): JsonResponse
    {
        $this->authorizeInstall($request);
        $data = $request->validate(CaptchaSettings::platformRules());

        $this->settings->updatePlatform($data);

        return response()->json($this->payload($gate, $metrics));
    }

    public function metrics(Request $request, CaptchaGate $gate, CaptchaMetrics $metrics): Response
    {
        $this->authorizeInstall($request);

        return response($metrics->prometheus($gate->fallbackActive()), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    private function payload(CaptchaGate $gate, CaptchaMetrics $metrics): array
    {
        return $this->settings->adminPayload() + [
            'fallback_active' => $gate->fallbackActive(),
            'fallback_total' => $metrics->total(),
            'fallback_last_at' => ($at = $metrics->lastAt()) ? date(DATE_ATOM, $at) : null,
        ];
    }

    /**
     * The route requires settings.manage (config/route_permissions.php). In the multi-tenant
     * edition that permission is an organization's own: the install's protection stays with the
     * platform administrators (an organization sets its own keys under /api/tenant/captcha).
     */
    private function authorizeInstall(Request $request): void
    {
        if (config('tenancy.enabled')) {
            abort_unless(app(Rbac::class)->isPlatformAdmin($request->user()), 403, __('rbac.platform_admin_required'));
        }
    }
}
