<?php

namespace Modules\AuthProvider\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SignInHandoffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Services\OAuthService;
use Modules\AuthProvider\Services\Saml\SamlService;

/**
 * SAML 2.0 service provider endpoints. `{provider}` is the auth provider ID;
 * `?tenant=<slug>` selects the tenant whose provider row is meant (multi-tenant installs).
 */
class SamlController extends Controller
{
    public function __construct(
        protected SamlService $samlService,
        protected OAuthService $oauthService,
    ) {}

    /**
     * SP metadata XML to register at the IdP (entity ID, ACS URL, signing certificate).
     *
     * @unauthenticated
     */
    public function metadata(Request $request, string $provider): Response
    {
        [$authProvider, $tenant] = $this->resolve($request, $provider, false);
        abort_unless($authProvider, 404);

        return response($this->samlService->metadata($authProvider, $tenant), 200, [
            'Content-Type' => 'application/samlmetadata+xml; charset=UTF-8',
        ]);
    }

    /**
     * Start SP-initiated sign-in: JSON `{redirect_url}` for the console, a 302 for browsers.
     *
     * @unauthenticated
     */
    public function login(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        [$authProvider, $tenant] = $this->resolve($request, $provider, true);

        if (! $authProvider) {
            return $request->expectsJson()
                ? response()->json(['message' => __('api.oauth.provider_not_found')], 404)
                : $this->failed(__('api.oauth.provider_not_found'));
        }

        $url = $this->samlService->loginUrl($authProvider, $tenant);

        return $request->expectsJson()
            ? response()->json(['redirect_url' => $url])
            : redirect()->away($url);
    }

    /**
     * Assertion Consumer Service (HTTP-POST binding): validates the SAMLResponse, then signs the
     * user in exactly like the OAuth callback (redirect to the console with a token).
     *
     * @unauthenticated
     */
    public function acs(Request $request, string $provider): RedirectResponse
    {
        [$authProvider, $tenant] = $this->resolve($request, $provider, true);

        if (! $authProvider) {
            return $this->failed(__('api.oauth.provider_not_found'));
        }

        $samlResponse = $request->input('SAMLResponse');
        if (! is_string($samlResponse) || $samlResponse === '') {
            return $this->failed(__('api.saml.invalid_response'), $authProvider);
        }

        $result = $this->samlService->handleResponse($authProvider, $tenant, $samlResponse);

        if (isset($result['error'])) {
            return $this->failed($result['error'], $authProvider);
        }

        $this->audit(AuditEvent::RESULT_SUCCESS, $authProvider, $result['user']);

        // A one-time code, never the token itself, travels in the redirect URL (POST /api/oauth/exchange)
        $code = app(SignInHandoffService::class)->issue($result['user'], $result['token']);

        return redirect()->away(
            config('app.frontend_url', '/').'/oauth/callback?'.http_build_query(['code' => $code])
        );
    }

    /** @return array{0: ?AuthProvider, 1: ?string} */
    protected function resolve(Request $request, string $id, bool $enabledOnly): array
    {
        $tenant = $request->query('tenant');
        $tenant = is_string($tenant) && $tenant !== '' ? $tenant : null;

        if ($tenant !== null && config('tenancy.enabled')) {
            if (! $this->oauthService->initializeTenantContext($tenant)) {
                return [null, $tenant];
            }
        } elseif ($tenant !== null) {
            // Single-tenant install: a tenant parameter can never match a provider.
            return [null, $tenant];
        }

        try {
            $query = AuthProvider::where('driver', AuthProvider::DRIVER_SAML)->whereKey($id);
            if ($enabledOnly) {
                $query->where('is_enabled', true);
            }

            return [$query->first(), $tenant];
        } catch (\Throwable) {
            return [null, $tenant];
        }
    }

    protected function failed(string $message, ?AuthProvider $provider = null): RedirectResponse
    {
        if ($provider) {
            $this->audit(AuditEvent::RESULT_FAILURE, $provider, null, $message);
        }

        return redirect()->away(config('app.frontend_url', '/').'/login?oauth_error='.urlencode($message));
    }

    /** Audit log: the SAML sign-in (ACS answers a redirect, not an audited API route). */
    protected function audit(string $result, AuthProvider $provider, $user = null, ?string $error = null): void
    {
        app(AuditLogger::class)->record('auth.sso.login', [
            'actor' => $user,
            'auth_method' => 'saml',
            'result' => $result,
            'target' => $provider,
            'metadata' => array_filter(['provider' => 'saml', 'error' => $error]),
        ]);
    }
}
