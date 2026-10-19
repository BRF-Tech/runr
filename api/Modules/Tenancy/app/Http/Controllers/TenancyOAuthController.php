<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Modules\AuthProvider\Controllers\OAuthController;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Services\TenancyLoginService;

/**
 * The SSO sign-in handoff of the multi-tenant edition: the exchange answers like the password
 * sign-in (TenancyAuthController::login) — `tenants` and `active_tenant` next to the token. A
 * sign-in started from a tenant's sign-in page starts the console in that tenant; only its
 * members and platform administrators get in (anyone else: 403, the token is revoked). The
 * session itself is not pinned to the tenant: the console can switch organizations as after a
 * password sign-in, and every request is checked against the membership (IdentifyTenant).
 */
class TenancyOAuthController extends OAuthController
{
    /** @param  array{token: string, user_id: string, tenant_id: string|null}  $handoff */
    protected function exchanged(array $handoff): JsonResponse
    {
        $answer = ['token' => $handoff['token'], 'user_id' => $handoff['user_id']];

        $user = User::on('central')->find($handoff['user_id']);

        // The platform's sign-in page: where a password sign-in there starts
        if ($handoff['tenant_id'] === null) {
            return response()->json($user ? $answer + app(TenancyLoginService::class)->loginData($user) : $answer);
        }

        // A tenant's sign-in page: its members and platform administrators only
        $tenant = Tenant::find($handoff['tenant_id']);
        if (! $user || ! $tenant || ! ((bool) $user->getAttribute('is_admin')
            || TenantUser::where('tenant_id', $tenant->getTenantKey())->where('user_id', $user->getKey())->exists())) {
            $this->revoke($handoff['token']);

            return response()->json(['message' => __($tenant ? 'api.tenant.access_denied' : 'api.tenant.not_found')], 403);
        }

        return response()->json($answer + app(TenancyLoginService::class)->loginData($user, (string) $tenant->slug));
    }

    /** The sign-in session the refused exchange would have handed over. */
    private function revoke(string $plainToken): void
    {
        /** @var class-string<PersonalAccessToken> $model */
        $model = Sanctum::$personalAccessTokenModel;
        $model::findToken($plainToken)?->delete();
    }
}
