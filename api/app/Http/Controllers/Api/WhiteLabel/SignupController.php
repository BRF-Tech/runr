<?php

namespace App\Http\Controllers\Api\WhiteLabel;

use App\Http\Controllers\Api\Controller;
use App\Services\WhiteLabel\SignupService;
use App\Services\WhiteLabel\SignupSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service signup — e-mail verification (public) and the admin settings.
 *
 *  POST /api/auth/signup/verify   {token}   → 201 {user, token, …} | 404 invalid | 410 expired | 422 taken
 *  POST /api/auth/signup/resend   {email}   → 200 (always the same answer)
 *  GET  /api/admin/signup-settings          signup.manage (single-tenant; multi-tenant: platform administrators)
 *  PUT  /api/admin/signup-settings          same
 */
class SignupController extends Controller
{
    /** @unauthenticated */
    public function verify(Request $request, SignupService $signup): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);

        return $signup->verify($data['token']);
    }

    /** @unauthenticated */
    public function resend(Request $request, SignupService $signup): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);

        return $signup->resend($data['email']);
    }

    public function settings(SignupSettings $settings): JsonResponse
    {
        return response()->json($settings->adminPayload());
    }

    public function updateSettings(Request $request, SignupSettings $settings): JsonResponse
    {
        $data = $request->validate(SignupSettings::rules());

        $settings->update($data);

        return response()->json(app(SignupSettings::class)->adminPayload());
    }
}
