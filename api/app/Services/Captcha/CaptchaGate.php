<?php

namespace App\Services\Captcha;

use App\Models\User;
use App\Services\WhiteLabel\BrandingService;
use App\Support\Auth\FrozenAccounts;
use App\Support\Auth\LoginIdentifier;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The CAPTCHA check of an anonymous form (middleware `captcha.verify:<form>`, the sign-up form's
 * request), docs/security.md#bot-protection — the shared flow (captcha-turnstile-plan §3):
 *
 *   provider token  over the provider's length or outside [A-Za-z0-9._-] → 422 {captcha: "invalid"},
 *                   never sent out; otherwise
 *                   → siteverify (timeout, one retry)
 *                       pass           the form goes on
 *                       invalid        422 {captcha: "invalid"} — never the fallback; any HTTP 4xx
 *                                      of the provider (429 included) is a refusal too
 *                       unreachable    the fallback window opens (cache flag captcha:fallback:active,
 *                                      120 s) and 409 {captcha: "fallback", challenge} hands out a
 *                                      local proof-of-work challenge (captcha_fallback_total + 1)
 *                       misconfigured  the provider refuses the secret: like unreachable, and the
 *                                      administrators are told (CaptchaConfigAlert)
 *   a configuration that cannot ask the provider (no site key, no secret, a stored secret that
 *   cannot be decrypted) answers every form like misconfigured — with or without a token: the
 *   protection never switches itself off, and never locks everyone out
 *   captcha_local   accepted only while the fallback window is open (else 422 fallback_inactive:
 *                   a bot cannot skip the widget with cheap work while the provider is healthy),
 *                   verified by LocalChallenge, single use
 *   no answer       422 {captcha: "required"}
 *
 * Whose keys: an organization's own keys guard only its own surfaces (contract §3.5) — an
 * invitation of the organization (the Tenancy module's resolver) and a sign-in of an account that
 * is a member of it. A sign-in naming the organization is checked with its keys whoever signs in,
 * so nothing before the password check tells a member from anyone else; for an account that is
 * not its member (or none at all) a passing check is marked an outsider's, and the sign-in fails
 * like a wrong password (AuthController): an organization's keys (Cloudflare's always-pass test
 * keys, say) never let anyone else in, whatever the password. Every other request gets the
 * platform's protection, whatever organization it names.
 *
 * Every piece of state is the scope's — the platform's keys, or one organization's own keys
 * (CaptchaConfig::scope()): the fallback window (captcha:fallback:active for the platform,
 * captcha:fallback:active:tenant:<id>), the spent tokens and local answers, the reachability
 * probe, the configuration alarm, and the HMAC key of the local challenges — a challenge of one
 * scope never passes in another.
 *
 * `captcha.fallback = off` answers 503 {captcha: "unavailable"} instead of 409 (tests only); a wrong
 * configuration still alarms.
 *
 * A step that asks for more input after an accepted answer — the two-factor code after the
 * password — lets the same answer through once more for the same account and address
 * (allowFollowUp): the console sends the form again without a second challenge.
 */
class CaptchaGate
{
    /** The platform's fallback window; an organization's own keys add ":tenant:<id>" */
    public const FALLBACK_FLAG = 'captcha:fallback:active';

    /** Field names a provider token may arrive in (the widgets' own, then ours) */
    public const TOKEN_FIELDS = ['captcha_token', 'cf-turnstile-response', 'turnstile_token', 'g-recaptcha-response', 'h-captcha-response'];

    /** The forms an organization's own keys may guard: its members' sign-ins, its invitations */
    public const ORGANIZATION_FORMS = ['login', 'demo_login', 'public_forms'];

    private const TOKEN_USED_PREFIX = 'captcha:token:used:';

    private const FOLLOW_UP_PREFIX = 'captcha:follow:';

    private const REACHABLE_PREFIX = 'captcha:provider:reachable:';

    /** Request attribute: the answer this request passed with */
    private const PASSED = 'captcha.passed';

    /** Request attribute: a sign-in an organization's own keys checked, of an account that is not its member */
    private const OUTSIDER = 'captcha.outsider';

    /** The organization of an invitation link (Tenancy module) */
    private static ?\Closure $tenantResolver = null;

    /** Whether an account is a member of an organization (Tenancy module) */
    private static ?\Closure $memberResolver = null;

    public function __construct(
        private readonly CaptchaSettings $settings,
        private readonly CaptchaVerifier $verifier,
        private readonly CaptchaMetrics $metrics,
        private readonly CaptchaFallbackAlert $alert,
        private readonly CaptchaConfigAlert $configAlert,
    ) {}

    /** @param  (\Closure(Request): ?string)|null  $resolver  the organization of an invitation link */
    public static function resolveTenantsUsing(?\Closure $resolver): void
    {
        self::$tenantResolver = $resolver;
    }

    /** @param  (\Closure(string, User): bool)|null  $resolver  is the account a member of the organization */
    public static function resolveMembersUsing(?\Closure $resolver): void
    {
        self::$memberResolver = $resolver;
    }

    // ── The check ───────────────────────────────────────────────────────────

    /** Null when the form may go on, otherwise the answer to send instead. */
    public function check(Request $request, string $form): ?JsonResponse
    {
        $form = $this->formFor($request, $form);
        $config = $this->settings->forTenant($this->tenantIdFor($request, $form));
        if (! $config->protects($form)) {
            return null;
        }

        // A sign-in checked with an organization's own keys for an account that is not its member:
        // the check runs as for a member, the sign-in fails like a wrong password (outsider())
        if ($config->tenantId !== null && in_array($form, ['login', 'demo_login'], true) && ! $this->isMember($request, $config->tenantId)) {
            $request->attributes->set(self::OUTSIDER, true);
        }

        $subject = $this->subject($request, $form);
        $local = $request->input(LocalChallenge::FIELD);
        $local = is_string($local) && $local !== '' ? $local : null;
        $token = $this->token($request);

        // The follow-up of an accepted step (the two-factor code after the password)
        $answer = $local !== null ? 'local:'.$local : ($token !== null ? 'token:'.$token : null);
        if ($answer !== null && $this->consumeFollowUp($config, $form, $answer, $subject, $request->ip())) {
            $this->passed($request, $config, $form, $answer, $subject);

            return null;
        }

        if ($local !== null) {
            return $this->checkLocal($request, $form, $config, $local, $subject);
        }

        // A configuration the provider cannot be asked with: the fallback answers (the console
        // shows no widget without a site key, so a form without an answer gets the challenge too)
        if (($problem = $config->problem()) !== null) {
            return $this->misconfigured($form, $config, $problem);
        }

        if ($token === null) {
            return $this->refuse('required', 'captcha_token');
        }
        // Bounded before it goes out: the provider's length and the token character set, else a
        // refusal without a call (CaptchaVerifier::wellFormed)
        if (! CaptchaVerifier::wellFormed($config->provider, $token)) {
            return $this->refuse('invalid', 'captcha_token');
        }

        // Single use: a token seen before is not sent to the provider again (marked before the
        // call, so two requests with one token cannot both pass)
        $used = $this->scoped(self::TOKEN_USED_PREFIX, $config, hash('sha256', $token));
        if (! $this->cache()->add($used, 1, (int) config('captcha.token_ttl_seconds', 300))) {
            return $this->refuse('invalid', 'captcha_token');
        }

        $verdict = $this->verifier->verify($config, $token, $request->ip());

        if ($verdict === CaptchaVerifier::PASS) {
            $this->answered($config, passed: true);
            $this->passed($request, $config, $form, 'token:'.$token, $subject);

            return null;
        }

        if ($verdict === CaptchaVerifier::INVALID) {
            $this->answered($config);

            return $this->refuse('invalid', 'captcha_token');
        }

        // Never verified: the token is not spent (the shared contract remembers the accepted
        // tokens); sent again once the provider answers, it is checked then
        $this->cache()->forget($used);

        return $verdict === CaptchaVerifier::MISCONFIGURED
            ? $this->misconfigured($form, $config, CaptchaConfig::SECRET_REJECTED)
            : $this->fallback($form, $config);
    }

    /**
     * The same answer passes the next request of this form once more (same account, same
     * address, same scope, 5 minutes): call after a step that asks for more input.
     */
    public function allowFollowUp(Request $request): void
    {
        $passed = $request->attributes->get(self::PASSED);
        if (! is_array($passed) || ! isset($passed['form'], $passed['answer'], $passed['scope'])) {
            return;
        }

        $this->cache()->put(
            self::FOLLOW_UP_PREFIX.$this->followUpKey((string) $passed['scope'], $passed['form'], $passed['answer'], (string) ($passed['subject'] ?? '')),
            (string) $request->ip(),
            (int) config('captcha.token_ttl_seconds', 300),
        );
    }

    // ── The fallback ────────────────────────────────────────────────────────

    /** Whether the fallback window of $config's protection (default: the platform's) is open. */
    public function fallbackActive(?CaptchaConfig $config = null): bool
    {
        try {
            return (bool) $this->cache()->get(self::fallbackFlag($config));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Cache key of the fallback window: the platform's, or an organization's own keys'. */
    public static function fallbackFlag(?CaptchaConfig $config = null): string
    {
        return $config?->tenantId !== null ? self::FALLBACK_FLAG.':'.$config->scope() : self::FALLBACK_FLAG;
    }

    /**
     * A local challenge of $config's scope for $form, counted in captcha_fallback_total. $outage:
     * the provider could not be reached — more than one in 10 minutes alerts the administrators (a
     * wrong configuration has its own alarm, CaptchaConfigAlert).
     */
    public function issueChallenge(string $form, CaptchaConfig $config, bool $outage = true): array
    {
        $this->metrics->increment($form, $config->provider);
        if ($outage) {
            $this->alert->record($form, $config->provider);
        }

        return LocalChallenge::create($this->localKey($config), $this->maxNumber(), $this->localTtl(), now()->getTimestamp());
    }

    /**
     * POST /api/captcha/local/challenge {form, tenant?}: the console could not show the provider's
     * widget (an ad blocker, a script that does not load). The scope is the page's: an
     * organization's own keys for its sign-in page and its invitations (`tenant`), else the
     * platform's — the form's answer only counts in that scope. A challenge while the window is
     * open, while the configuration is wrong, or once a probe shows that the provider cannot be
     * reached from here either; 409 {captcha: "provider"} while it can (the console says the
     * widget could not load: turn off the blocker); 404 with the protection off; 422 for a form
     * the scope does not guard (an unknown form never gets one).
     */
    public function localChallengeResponse(Request $request): JsonResponse
    {
        $form = $request->input('form');
        if (! is_string($form) || ! in_array($form, CaptchaSettings::FORMS, true)) {
            return $this->refuse('unprotected', 'form');
        }

        $config = $this->settings->forTenant(in_array($form, self::ORGANIZATION_FORMS, true) ? $this->pageTenantId($request) : null);
        if (! $config->enabled()) {
            return response()->json(['message' => __('captcha.off'), 'captcha' => 'none'], 404);
        }
        // A sign-in is guarded as the demo sign-in too (a shared account's)
        if (! $config->protects($form) && ! ($form === 'login' && $config->protects('demo_login'))) {
            return $this->refuse('unprotected', 'form');
        }

        // The configuration is wrong: told (once an hour) whether or not the fallback answers
        $problem = $config->problem();
        if ($problem !== null) {
            $this->configAlert->record($config, $problem);
        }

        if (! $config->localFallback()) {
            return response()->json(['message' => __('captcha.reasons.provider'), 'captcha' => 'provider'], 409);
        }

        // An open window is not extended from here: only a failing provider (re)opens it
        $outage = $problem === null;
        if (! $this->fallbackActive($config)) {
            $probe = $outage ? $this->probe($config) : CaptchaVerifier::MISCONFIGURED;
            if ($outage && $probe === CaptchaVerifier::MISCONFIGURED) {
                $this->configAlert->record($config, CaptchaConfig::SECRET_REJECTED);
                $outage = false;
            } elseif ($probe !== CaptchaVerifier::UNREACHABLE && $probe !== CaptchaVerifier::MISCONFIGURED) {
                return response()->json(['message' => __('captcha.reasons.provider'), 'captcha' => 'provider'], 409);
            }
            $this->openFallbackWindow($config);
        }

        return response()->json(['challenge' => $this->issueChallenge($form, $config, $outage)]);
    }

    /**
     * The configuration error the settings page shows for $config (CaptchaConfigAlert::problem).
     *
     * @return array{reason: string, at: ?string}|null
     */
    public function problem(CaptchaConfig $config): ?array
    {
        return $this->configAlert->problem($config);
    }

    // ── Context ─────────────────────────────────────────────────────────────

    /**
     * The organization whose own keys check this form, null for the platform's: an invitation of
     * the organization, or a sign-in naming it (`tenant_slug`) — whoever signs in, so the check
     * itself never tells a member from anyone else. A sign-in of an account that is not its member
     * is then an outsider's (outsider()). Without the Tenancy module's member check, the platform's.
     */
    public function tenantIdFor(Request $request, string $form): ?string
    {
        if (self::$tenantResolver) {
            $id = (self::$tenantResolver)($request);
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        if (! in_array($form, ['login', 'demo_login'], true) || ! self::$memberResolver) {
            return null;
        }

        return $this->pageTenantId($request);
    }

    /**
     * The sign-in passed the bot protection of an organization's own keys, but the account it names
     * is not that organization's member (a platform administrator who is not one included), or no
     * account at all: the sign-in has to fail like a wrong password, whatever the password —
     * AuthController::login does, with the same answer and the same password-hash work.
     */
    public function outsider(Request $request): bool
    {
        return $request->attributes->get(self::OUTSIDER) === true;
    }

    private function isMember(Request $request, string $tenantId): bool
    {
        $user = LoginIdentifier::forRequest($request, $request->input('login'));

        return $user !== null && self::$memberResolver !== null && (self::$memberResolver)($tenantId, $user) === true;
    }

    /** The organization a page names (?tenant= / tenant_slug), null for none or an unknown one. */
    public function pageTenantId(Request $request): ?string
    {
        $slug = $request->input('tenant_slug') ?? $request->query('tenant') ?? $request->input('tenant');
        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        return app(BrandingService::class)->findTenant($slug)['id'] ?? null;
    }

    /** A sign-in of a shared account (auth.frozen_accounts, a public demo login) is the demo_login form. */
    public function formFor(Request $request, string $form): string
    {
        if ($form === 'login' && FrozenAccounts::emails() !== []) {
            $user = LoginIdentifier::forRequest($request, $request->input('login'));
            if ($user && FrozenAccounts::includes($user)) {
                return 'demo_login';
            }
        }

        return $form;
    }

    public function cache(): Repository
    {
        return $this->metrics->cache();
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function checkLocal(Request $request, string $form, CaptchaConfig $config, string $local, string $subject): ?JsonResponse
    {
        // A configuration that cannot ask the provider hands every form a challenge: its answers
        // count without the window
        if ($config->problem() === null && ! $this->fallbackActive($config)) {
            return $this->refuse('fallback_inactive', LocalChallenge::FIELD);
        }

        $cache = $this->cache();
        $result = LocalChallenge::verify(
            $local,
            $this->localKey($config),
            now()->getTimestamp(),
            $this->maxNumber(),
            fn (string $signature, int $ttl) => $cache->add($this->scoped(LocalChallenge::USED_KEY_PREFIX, $config, $signature), 1, $ttl),
        );

        if (! $result['ok']) {
            return $this->refuse($result['reason'], LocalChallenge::FIELD);
        }

        $this->passed($request, $config, $form, 'local:'.$local, $subject);

        return null;
    }

    /** A configuration error counts as an outage: the fallback answers, who can fix it is told. */
    private function misconfigured(string $form, CaptchaConfig $config, string $reason): JsonResponse
    {
        $this->configAlert->record($config, $reason);

        return $this->fallback($form, $config, outage: false);
    }

    private function fallback(string $form, CaptchaConfig $config, bool $outage = true): JsonResponse
    {
        if (! $config->localFallback()) {
            return response()->json(['message' => __('captcha.reasons.unavailable'), 'captcha' => 'unavailable'], 503);
        }

        $this->openFallbackWindow($config);

        return response()->json([
            'message' => __('captcha.fallback'),
            'captcha' => 'fallback',
            'challenge' => $this->issueChallenge($form, $config, $outage),
        ], 409);
    }

    private function openFallbackWindow(CaptchaConfig $config): void
    {
        $this->cache()->put(self::fallbackFlag($config), 1, (int) config('captcha.fallback_window_seconds', 120));
    }

    /**
     * The provider gave a verdict on a token: it can be reached. Only a passing token proves that it
     * takes the keys (a refusal may be a 429 page): then an earlier "secret refused" is over.
     */
    private function answered(CaptchaConfig $config, bool $passed = false): void
    {
        $this->cache()->put($this->scoped(self::REACHABLE_PREFIX, $config, $config->provider), 1, 30);
        if ($passed) {
            $this->configAlert->resolved($config);
        }
    }

    /** What siteverify makes of a probe token with $config's keys — any verdict means reachable, remembered 30 s. */
    private function probe(CaptchaConfig $config): string
    {
        if ($this->cache()->get($this->scoped(self::REACHABLE_PREFIX, $config, $config->provider))) {
            return CaptchaVerifier::INVALID;
        }

        $verdict = $this->verifier->verify($config, 'runr-captcha-probe');
        if ($verdict === CaptchaVerifier::PASS || $verdict === CaptchaVerifier::INVALID) {
            $this->answered($config, $verdict === CaptchaVerifier::PASS);
        }

        return $verdict;
    }

    /**
     * A cache key of $config's scope: the platform's as the shared contract names it
     * (captcha:local:used:<signature>, …), an organization's with "tenant:<id>:" after the prefix.
     */
    private function scoped(string $prefix, CaptchaConfig $config, string $key): string
    {
        return $config->tenantId === null ? $prefix.$key : $prefix.$config->scope().':'.$key;
    }

    /** 422 {message, captcha: <reason>, errors: {<field>: [message]}} */
    private function refuse(string $reason, string $field): JsonResponse
    {
        $message = __("captcha.reasons.{$reason}");

        return response()->json(['message' => $message, 'captcha' => $reason, 'errors' => [$field => [$message]]], 422);
    }

    private function token(Request $request): ?string
    {
        foreach (self::TOKEN_FIELDS as $field) {
            $value = $request->input($field);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** Whom a follow-up belongs to: the account a sign-in names. */
    private function subject(Request $request, string $form): string
    {
        return in_array($form, ['login', 'demo_login'], true)
            ? LoginIdentifier::throttleKey($request, $request->input('login'))
            : '';
    }

    private function passed(Request $request, CaptchaConfig $config, string $form, string $answer, string $subject): void
    {
        $request->attributes->set(self::PASSED, ['scope' => $config->scope(), 'form' => $form, 'answer' => $answer, 'subject' => $subject]);
    }

    private function consumeFollowUp(CaptchaConfig $config, string $form, string $answer, string $subject, ?string $ip): bool
    {
        $stored = $this->cache()->pull(self::FOLLOW_UP_PREFIX.$this->followUpKey($config->scope(), $form, $answer, $subject));

        return is_string($stored) && hash_equals($stored, (string) $ip);
    }

    private function followUpKey(string $scope, string $form, string $answer, string $subject): string
    {
        return hash('sha256', $scope."\n".$form."\n".$answer."\n".$subject);
    }

    /**
     * HMAC key of the local challenges of $config's scope. The platform's: CAPTCHA_LOCAL_HMAC_KEY,
     * else derived from APP_KEY — as before. An organization's own keys: HMAC-SHA256 of its scope
     * under that key, so a challenge signed for one scope fails `bad_signature` in another. The
     * signing itself (LocalChallenge, the shared test vectors) is unchanged.
     */
    private function localKey(CaptchaConfig $config): string
    {
        $key = trim((string) config('captcha.local.hmac_key', ''));
        $key = $key !== '' ? $key : hash('sha256', 'captcha-local:'.config('app.key'));

        return $config->tenantId === null ? $key : hash_hmac('sha256', 'captcha-local-scope:'.$config->scope(), $key);
    }

    private function maxNumber(): int
    {
        return max(1000, min(10_000_000, (int) config('captcha.local.maxnumber', LocalChallenge::DEFAULT_MAXNUMBER)));
    }

    private function localTtl(): int
    {
        return max(60, min(3600, (int) config('captcha.local.ttl_seconds', LocalChallenge::DEFAULT_TTL)));
    }
}
