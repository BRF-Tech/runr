<?php

namespace Modules\AuthProvider\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SignInHandoffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Services\OAuthService;

class OAuthController extends Controller
{
    public function __construct(
        protected OAuthService $oauthService
    ) {}

    /** @unauthenticated */
    public function discover(Request $request): JsonResponse
    {
        $tenantSlug = $request->query('tenant');

        if ($tenantSlug) {
            $this->initTenantForDiscover($tenantSlug);
        }

        $providers = AuthProvider::enabled()
            ->ordered()
            ->get(['id', 'name', 'driver', 'display_order']);

        return response()->json($providers);
    }

    /** @unauthenticated */
    public function redirect(Request $request, string $provider): JsonResponse
    {
        $tenantSlug = $request->query('tenant');

        $url = $this->oauthService->getRedirectUrl($provider, $tenantSlug);

        return response()->json(['redirect_url' => $url]);
    }

    /** @unauthenticated */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $state = $request->query('state', '');

        $result = $this->oauthService->handleProviderCallback($provider, $state);

        // Audit log: the OAuth / OIDC sign-in (a redirect, not an audited API route)
        app(AuditLogger::class)->record('auth.sso.login', [
            'actor' => $result['user'] ?? null,
            'auth_method' => 'sso:'.$provider,
            'result' => isset($result['error']) ? AuditEvent::RESULT_FAILURE : AuditEvent::RESULT_SUCCESS,
            'target' => ['type' => 'auth_provider', 'name' => $provider],
            'metadata' => array_filter(['provider' => $provider, 'error' => $result['error'] ?? null]),
        ]);

        if (isset($result['error'])) {
            return redirect(
                config('app.frontend_url', '/').'/login?oauth_error='.urlencode($result['error'])
            );
        }

        // A one-time code, never the token itself, travels in the redirect URL
        $code = app(SignInHandoffService::class)->issue($result['user'], $result['token']);

        return redirect(
            config('app.frontend_url', '/').'/oauth/callback?'.http_build_query(['code' => $code])
        );
    }

    /**
     * POST /api/oauth/exchange {code} — the console trades the one-time code of an SSO callback
     * (OAuth / OIDC / SAML) for the API token. A code works once, for 60 seconds.
     *
     * @unauthenticated
     */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:128']);

        $handoff = app(SignInHandoffService::class)->redeem($data['code']);
        if (! $handoff) {
            return response()->json(['message' => __('api.oauth.invalid_code')], 422);
        }

        return response()->json($handoff)->header('Cache-Control', 'no-store, private');
    }

    public function link(Request $request, string $provider): JsonResponse
    {
        $url = $this->oauthService->getRedirectUrl($provider);

        return response()->json(['redirect_url' => $url]);
    }

    protected function initTenantForDiscover(string $slug): void
    {
        if (! function_exists('tenancy')) {
            return;
        }

        $tenantClass = 'Modules\\Tenancy\\Models\\Tenant';
        if (! class_exists($tenantClass)) {
            return;
        }

        $tenant = $tenantClass::where('slug', $slug)->first();
        if ($tenant) {
            tenancy()->initialize($tenant);
        }
    }
}
