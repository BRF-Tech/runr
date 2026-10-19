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
use Modules\AuthProvider\Services\OAuthFlowException;
use Modules\AuthProvider\Services\OAuthService;
use Modules\AuthProvider\Services\TenantContext;

class OAuthController extends Controller
{
    public function __construct(
        protected OAuthService $oauthService
    ) {}

    /**
     * Enabled providers of the organization for the sign-in page (`?tenant=<slug>`: the tenant's).
     *
     * @unauthenticated
     */
    public function discover(Request $request): JsonResponse
    {
        $tenantSlug = $request->query('tenant');

        // An unknown tenant has no providers (never the platform's)
        if (is_string($tenantSlug) && $tenantSlug !== '' && ! app(TenantContext::class)->enter($tenantSlug)) {
            return response()->json([]);
        }

        $providers = AuthProvider::enabled()
            ->ordered()
            ->orderBy('id')
            ->get(['id', 'name', 'driver', 'display_order']);

        return response()->json($providers);
    }

    /**
     * `{redirect_url}` of the provider's sign-in. `?provider=<id>` picks one of several providers
     * of the driver (default: the first enabled one in sign-in order), `?tenant=<slug>` the tenant
     * whose providers are meant.
     *
     * @unauthenticated
     */
    public function redirect(Request $request, string $provider): JsonResponse
    {
        try {
            $url = $this->oauthService->getRedirectUrl($provider, $this->queryString($request, 'tenant'), $this->queryString($request, 'provider'));
        } catch (OAuthFlowException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['redirect_url' => $url]);
    }

    /** @unauthenticated */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $result = $this->oauthService->handleProviderCallback(
            $provider,
            $this->queryString($request, 'state') ?? '',
            $this->queryString($request, 'error'),
        );
        $frontend = config('app.frontend_url', '/');

        // Account link: the console's account settings confirm (or show why not)
        if ($result['mode'] === OAuthService::MODE_LINK) {
            $query = isset($result['error']) ? ['sso_link_error' => $result['error']] : ['sso_link' => $result['link_code'] ?? ''];

            return redirect($frontend.'/account?'.http_build_query($query));
        }

        // Audit log: the OAuth / OIDC sign-in (a redirect, not an audited API route)
        app(AuditLogger::class)->record('auth.sso.login', [
            'actor' => $result['user'] ?? null,
            'auth_method' => 'sso:'.$provider,
            'result' => isset($result['error']) ? AuditEvent::RESULT_FAILURE : AuditEvent::RESULT_SUCCESS,
            'target' => $result['provider'] ?? ['type' => 'auth_provider', 'name' => $provider],
            'metadata' => array_filter(['provider' => $provider, 'error' => $result['error'] ?? null]),
        ]);

        if (isset($result['error'])) {
            return redirect(
                $frontend.'/login?oauth_error='.urlencode($result['error'])
            );
        }

        // A one-time code, never the token itself, travels in the redirect URL; it names the
        // tenant whose sign-in page the sign-in started from (the console starts there)
        $code = app(SignInHandoffService::class)->issue($result['user'], $result['token'], app(TenantContext::class)->currentId());

        return redirect(
            $frontend.'/oauth/callback?'.http_build_query(['code' => $code])
        );
    }

    /**
     * POST /api/oauth/exchange {code} — the console trades the one-time code of an SSO callback
     * (OAuth / OIDC / SAML) for the API token. A code works once, for 60 seconds. In a
     * multi-tenant install the answer also carries `tenants` and `active_tenant` like the
     * password sign-in: the tenant whose sign-in page the sign-in started from.
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

        return $this->exchanged($handoff)->header('Cache-Control', 'no-store, private');
    }

    /**
     * The answer to a redeemed code (the Tenancy module adds the organization the console starts in).
     *
     * @param  array{token: string, user_id: string, tenant_id: string|null}  $handoff
     */
    protected function exchanged(array $handoff): JsonResponse
    {
        return response()->json(['token' => $handoff['token'], 'user_id' => $handoff['user_id']]);
    }

    /**
     * GET /api/oauth/{driver}/link — `{redirect_url}` of the provider's sign-in that links the
     * identity to the signed-in user (`?provider=<id>` as for the sign-in). The provider sends the
     * browser back to the console's account settings, which confirm the link.
     */
    public function link(Request $request, string $provider): JsonResponse
    {
        try {
            $url = $this->oauthService->getLinkUrl($request->user(), $provider, $this->queryString($request, 'provider'));
        } catch (OAuthFlowException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['redirect_url' => $url]);
    }

    /**
     * POST /api/oauth/link/complete {code} — the signed-in user confirms the identity the
     * provider's callback staged (`?sso_link=<code>` on the account settings page): 201 linked,
     * 200 already linked to this user, 409 linked to another user, 422 unknown / expired code.
     */
    public function completeLink(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:128']);

        try {
            $result = $this->oauthService->completeLink($request->user(), $data['code']);
        } catch (OAuthFlowException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'message' => __($result['created'] ? 'api.oauth.account_linked' : 'api.oauth.already_linked'),
            'account' => SocialAccountController::presentAccount($result['account']),
        ], $result['created'] ? 201 : 200);
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
