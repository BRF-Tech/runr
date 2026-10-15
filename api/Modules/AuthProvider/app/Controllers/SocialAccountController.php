<?php

namespace Modules\AuthProvider\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AuthProvider\Models\SocialAccount;

class SocialAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = SocialAccount::where('user_id', $request->user()->id)
            ->get(['id', 'provider', 'provider_user_id', 'email', 'name', 'avatar_url', 'created_at']);

        return response()->json($accounts);
    }

    public function destroy(Request $request, SocialAccount $socialAccount): JsonResponse
    {
        if ($socialAccount->user_id !== $request->user()->id) {
            return response()->json(['message' => __('api.oauth.unauthorized')], 403);
        }

        $socialAccount->delete();

        return response()->json(['message' => __('api.oauth.account_unlinked')]);
    }
}
