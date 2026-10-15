<?php

namespace App\Services\WhiteLabel;

use App\Events\UserRegistered;
use App\Mail\SignupVerificationMail;
use App\Models\PendingSignup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Self-service signup (POST /api/auth/register, /api/auth/signup/verify, /api/auth/signup/resend).
 *
 * Without e-mail verification the account is created at once (201 {user, token}), exactly as
 * before. With verification on, the signup waits in pending_signups and nothing else exists yet:
 * the link in the e-mail creates the account, fires UserRegistered (multi-tenant: the tenant is
 * created then) and signs the user in.
 */
class SignupService
{
    /** Keys of the register payload handed to UserRegistered listeners (tenant, consent) */
    public const PAYLOAD_KEYS = ['tenant_name', 'accept_terms'];

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const MAX_SENDS = 10;

    /** Extra data for the sign-in response (Tenancy: tenants + active_tenant) */
    private static ?\Closure $loginDataResolver = null;

    public function __construct(private readonly SignupSettings $settings) {}

    public static function loginDataUsing(?\Closure $resolver): void
    {
        self::$loginDataResolver = $resolver;
    }

    /**
     * @param  array<string, mixed>  $validated  RegisterRequest::validated()
     * @param  array<string, mixed>  $input  the raw request input (module fields such as tenant_name)
     */
    public function register(array $validated, array $input): JsonResponse
    {
        $payload = $this->payload($input);

        if ($this->settings->emailVerification()) {
            $pending = $this->storePending($validated, $payload);

            return response()->json([
                'verification_required' => true,
                'email' => $pending->email,
                'message' => __('whitelabel.signup.verification_sent'),
            ], 202);
        }

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        $this->recordConsent($user, $payload);

        event(new UserRegistered($user, $input));

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token], 201);
    }

    /**
     * Open the e-mail link: create the account from the pending signup and sign in.
     */
    public function verify(string $token): JsonResponse
    {
        $pending = PendingSignup::query()->where('token_hash', PendingSignup::hashToken($token))->first();

        if (! $pending) {
            return response()->json(['message' => __('whitelabel.signup.verification_invalid'), 'code' => 'invalid'], 404);
        }

        if ($pending->isExpired()) {
            return response()->json(['message' => __('whitelabel.signup.verification_expired'), 'code' => 'expired', 'email' => $pending->email], 410);
        }

        // Someone took the address / username meanwhile (admin-created user, invitation, other signup)
        if (User::query()->where('email', $pending->email)->exists()) {
            $pending->delete();

            return response()->json(['message' => __('whitelabel.signup.email_taken'), 'errors' => ['email' => [__('whitelabel.signup.email_taken')]]], 422);
        }
        if (User::query()->where('username', $pending->username)->exists()) {
            $pending->delete();

            return response()->json(['message' => __('whitelabel.signup.username_taken'), 'errors' => ['username' => [__('whitelabel.signup.username_taken')]]], 422);
        }

        $payload = (array) ($pending->payload ?? []);

        $user = User::create([
            'name' => $pending->name,
            'username' => $pending->username,
            'email' => $pending->email,
            'password' => $pending->password,   // already a bcrypt hash; the "hashed" cast keeps it
        ]);
        $user->forceFill([
            'email_verified_at' => now(),
            'terms_accepted_at' => isset($payload['terms_accepted_at']) ? $payload['terms_accepted_at'] : null,
        ])->save();

        $pending->delete();

        $requestData = array_intersect_key($payload, array_flip(self::PAYLOAD_KEYS)) + ['email_verified' => true];
        event(new UserRegistered($user, $requestData));

        $user->refresh();
        $token = $user->createToken('api')->plainTextToken;

        $data = ['user' => $user, 'token' => $token];
        if (self::$loginDataResolver) {
            $data = array_merge($data, (array) (self::$loginDataResolver)($user));
        }

        return response()->json($data, 201);
    }

    /**
     * Send the link again (new token; the old link stops working). Always answers the same, so the
     * endpoint does not reveal which addresses have a pending signup.
     */
    public function resend(string $email): JsonResponse
    {
        $pending = PendingSignup::query()->where('email', strtolower(trim($email)))->first();

        $cooledDown = ! $pending?->last_sent_at || $pending->last_sent_at->lte(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS));

        if ($pending && $cooledDown && $pending->send_count < self::MAX_SENDS) {
            $token = $this->newToken();
            $pending->forceFill([
                'token_hash' => PendingSignup::hashToken($token),
                'expires_at' => now()->addHours($this->settings->verificationTtlHours()),
                'send_count' => $pending->send_count + 1,
                'last_sent_at' => now(),
            ])->save();

            $this->sendMail($pending, $token);
        }

        return response()->json(['message' => __('whitelabel.signup.verification_resent')]);
    }

    /**
     * Expired pending signups older than a week are removed (scheduled daily).
     */
    public function prune(): int
    {
        return PendingSignup::query()->where('expires_at', '<', now()->subDays(7))->delete();
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function storePending(array $validated, array $payload): PendingSignup
    {
        $email = strtolower(trim((string) $validated['email']));
        $token = $this->newToken();

        // Signing up again with the same address replaces the waiting signup (new link)
        $pending = PendingSignup::query()->where('email', $email)->first() ?? new PendingSignup(['email' => $email, 'send_count' => 0]);
        $pending->fill([
            'username' => $validated['username'],
            'name' => $validated['name'],
            'password' => Hash::make((string) $validated['password']),
            'payload' => $payload,
            'token_hash' => PendingSignup::hashToken($token),
            'expires_at' => now()->addHours($this->settings->verificationTtlHours()),
            'send_count' => (int) $pending->send_count + 1,
            'last_sent_at' => now(),
        ])->save();

        $this->sendMail($pending, $token);

        return $pending;
    }

    private function payload(array $input): array
    {
        $payload = array_intersect_key($input, array_flip(self::PAYLOAD_KEYS));
        if (! empty($input['accept_terms'])) {
            $payload['terms_accepted_at'] = now()->toIso8601String();
        }
        $payload['locale'] = app()->getLocale();

        return $payload;
    }

    private function recordConsent(User $user, array $payload): void
    {
        if (! empty($payload['terms_accepted_at'])) {
            $user->forceFill(['terms_accepted_at' => $payload['terms_accepted_at']])->save();
        }
    }

    private function sendMail(PendingSignup $pending, string $token): void
    {
        $url = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/signup/verify?token='.urlencode($token);

        try {
            Mail::to($pending->email)
                ->locale((string) (($pending->payload['locale'] ?? null) ?: app()->getLocale()))
                ->send(new SignupVerificationMail($pending->name, $url, $this->settings->verificationTtlHours()));
        } catch (\Throwable $e) {
            // The signup stays pending; the user can ask for the link again
            Log::error('Signup verification e-mail could not be sent', ['email' => $pending->email, 'error' => $e->getMessage()]);
        }
    }

    private function newToken(): string
    {
        return Str::random(64);
    }
}
