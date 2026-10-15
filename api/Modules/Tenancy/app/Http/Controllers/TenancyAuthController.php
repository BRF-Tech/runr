<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\AuthController;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Services\TenancyLoginService;

class TenancyAuthController extends AuthController
{
    public function __construct(
        protected TenancyLoginService $loginService,
    ) {}

    /** @unauthenticated */
    public function login(LoginRequest $request): JsonResponse
    {
        $parentResponse = parent::login($request);

        if ($parentResponse->getStatusCode() !== 200) {
            return $parentResponse;
        }

        $data = $parentResponse->getData(true);
        $user = $data['user'] ?? null;

        if (! $user || ! isset($user['id'])) {
            return $parentResponse;
        }

        $userModel = User::on('central')->find($user['id']);
        $tenantSlug = $request->input('tenant_slug');

        // Tenant user login: validate that the user belongs to the specified tenant
        if ($tenantSlug) {
            $tenant = Tenant::where('slug', $tenantSlug)->first();

            if (! $tenant) {
                // Revoke the just-created token
                $userModel->tokens()->where('token', hash('sha256', explode('|', $data['token'] ?? '')[1] ?? ''))->delete();

                return response()->json(['message' => __('api.tenant.not_found')], 403);
            }

            $isMember = $userModel->is_admin || TenantUser::where('tenant_id', $tenant->id)->where('user_id', $userModel->id)->exists();

            if (! $isMember) {
                $userModel->tokens()->where('token', hash('sha256', explode('|', $data['token'] ?? '')[1] ?? ''))->delete();

                return response()->json(['message' => __('api.tenant.access_denied')], 403);
            }
        }

        $tenantData = $this->loginService->loginData($userModel, $tenantSlug);
        $data = array_merge($data, $tenantData);

        return response()->json($data);
    }
}
