<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor authentication of the signed-in user's own account. Turning it on or off changes
 * what the next sign-in asks for; it ends no sign-in session and no API token.
 */
class TwoFactorController extends Controller
{
    /** Start two-factor setup with a new TOTP secret */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $g2fa = new Google2FA;
        $secret = $g2fa->generateSecretKey();

        $user->update(['two_factor_secret' => encrypt($secret)]);

        $qrUrl = $g2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret,
        );

        return response()->json([
            'secret' => $secret,
            'qr_url' => $qrUrl,
        ]);
    }

    /** Turn on two-factor authentication with a TOTP code */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|size:6']);

        $user = $request->user();
        $g2fa = new Google2FA;
        $secret = decrypt($user->two_factor_secret);

        if (! $g2fa->verifyKey($secret, $request->input('code'))) {
            return response()->json(['message' => __('api.account.invalid_2fa_code')], 422);
        }

        $codes = collect(range(1, 8))->map(fn () => Str::random(10))->all();

        $user->update([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        ]);

        return response()->json([
            'message' => __('api.account.2fa_enabled'),
            'recovery_codes' => $codes,
        ]);
    }

    /** Turn off two-factor authentication */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => __('api.account.wrong_password')], 422);
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        return response()->json(['message' => __('api.account.2fa_disabled')]);
    }

    /** Generate new two-factor recovery codes */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        $codes = collect(range(1, 8))->map(fn () => Str::random(10))->all();

        $user->update([
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        ]);

        return response()->json(['recovery_codes' => $codes]);
    }
}
