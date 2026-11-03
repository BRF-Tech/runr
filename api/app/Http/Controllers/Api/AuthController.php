<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Captcha\CaptchaGate;
use App\Services\WhiteLabel\SignupService;
use App\Services\WhiteLabel\SignupSettings;
use App\Support\Auth\LoginIdentifier;
use App\Support\Auth\LoginTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    /**
     * Self-service signup (docs/white-label.md): 201 {user, token}, or with e-mail verification on
     * 202 {verification_required, email} — the account is created when the e-mail link is opened.
     *
     * @unauthenticated
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        return app(SignupService::class)->register($request->validated(), $request->all());
    }

    /**
     * Sign in with the user name or the e-mail address (`login`) and the password: 200 {user, token},
     * 202 {requires_2fa} until `two_factor_code` is given. An unknown account and a wrong password
     * get the same 401 — so does a sign-in that names an organization whose own bot protection keys
     * checked it, for an account that is not its member (CaptchaGate::outsider). With bot
     * protection on (middleware captcha.verify:login), the CAPTCHA answer of the password step also
     * passes the two-factor step that follows (CaptchaGate::allowFollowUp).
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $password = (string) $request->input('password');

        // The one account the user name or e-mail address names (LoginIdentifier)
        $user = LoginIdentifier::forRequest($request, $request->input('login'));

        if (! $user) {
            // As much work as checking a password: the answer time does not tell that the account is missing
            Hash::make($password);

            return response()->json(['message' => __('api.auth.login_failed')], 401);
        }

        // A sign-in that passed an organization's own bot protection keys for an account that is not
        // its member fails like a wrong password, after the same hash check: those keys never let
        // anyone else in, and the answer never tells who is a member (CaptchaGate::outsider)
        if (! Hash::check($password, $user->password) || app(CaptchaGate::class)->outsider($request)) {
            return response()->json(['message' => __('api.auth.login_failed')], 401);
        }

        if ($user->is_disabled) {
            return response()->json(['message' => __('api.account.account_disabled')], 403);
        }

        // 2FA check
        if ($user->two_factor_confirmed_at) {
            $code = $request->input('two_factor_code');

            if (! $code) {
                app(CaptchaGate::class)->allowFollowUp($request);

                return response()->json(['requires_2fa' => true], 202);
            }

            if (! $this->verify2FA($user, $code)) {
                app(CaptchaGate::class)->allowFollowUp($request);

                return response()->json(['message' => __('api.account.invalid_2fa_code')], 422);
            }
        }

        $accessToken = LoginTokens::issue($user);

        // Track session info on token
        $accessToken->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_name' => str_contains($request->userAgent() ?? '', 'Mobile') ? 'Mobile' : 'Desktop',
        ])->save();

        return response()->json(['user' => $user, 'token' => $accessToken->plainTextToken]);
    }

    private function verify2FA(User $user, string $code): bool
    {
        // Try OTP code first
        if (strlen($code) === 6 && ctype_digit($code)) {
            $g2fa = new Google2FA;
            $secret = decrypt($user->two_factor_secret);

            return $g2fa->verifyKey($secret, $code);
        }

        // Try recovery code
        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        if (in_array($code, $codes, true)) {
            $codes = array_values(array_diff($codes, [$code]));
            $user->update(['two_factor_recovery_codes' => encrypt(json_encode($codes))]);

            return true;
        }

        return false;
    }

    /**
     * Public signup options for the login page: enabled, invite-only, verification, allowed domains,
     * CAPTCHA provider + site key, terms / privacy links, tenant per signup (multi-tenant).
     *
     * @unauthenticated
     */
    public function registrationStatus(): JsonResponse
    {
        return response()->json(app(SignupSettings::class)->publicStatus());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('api.auth.logged_out')]);
    }

    /** The signed-in user with `frozen`, as GET /account answers it. */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->append('frozen'));
    }
}
