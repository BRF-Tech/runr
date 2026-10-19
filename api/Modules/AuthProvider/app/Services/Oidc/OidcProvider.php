<?php

namespace Modules\AuthProvider\Services\Oidc;

use App\Services\Observability\OutboundUrlGuard;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

/**
 * Generic OpenID Connect provider (driver `oidc`): authorization code flow with PKCE (S256),
 * `state` and `nonce`, configured from the identity provider's discovery document.
 *
 * The ID token is verified before anything in it is used: its signature with the identity
 * provider's published keys (JWKS; HS* tokens with the client secret), `iss` = the discovery
 * document's issuer, `aud` containing the client ID (`azp` = client ID when present or when
 * there are several audiences), `exp` / `iat` / `nbf` (60 s clock skew) and `nonce` = the one
 * sent with this sign-in. Claims of the userinfo endpoint (same `sub` required) complete the ID
 * token's; together they are the user's raw data (e-mail, name, the groups claim of SSO group
 * mapping).
 *
 * Calls to the identity provider go through Laravel's HTTP client. In a tenant's context they
 * may only reach public addresses and follow no redirects (OutboundUrlGuard).
 */
class OidcProvider extends AbstractProvider
{
    public const WELL_KNOWN = '/.well-known/openid-configuration';

    /** {@inheritdoc} */
    protected $scopeSeparator = ' ';

    /** {@inheritdoc} */
    protected $scopes = ['openid', 'email', 'profile'];

    private string $discoveryUrl = '';

    private ?string $configuredIssuer = null;

    private bool $publicTargetsOnly = false;

    private ?string $codeVerifier = null;

    private ?string $nonce = null;

    /** @var array<string, mixed>|null */
    private ?array $discovery = null;

    public function configure(string $discoveryUrl, ?string $issuer = null, bool $publicTargetsOnly = false): static
    {
        $this->discoveryUrl = self::discoveryUrlFor($discoveryUrl);
        $issuer = trim((string) $issuer);
        $this->configuredIssuer = $issuer !== '' ? $issuer : null;
        $this->publicTargetsOnly = $publicTargetsOnly;

        return $this;
    }

    /** PKCE verifier and nonce of this sign-in (kept server-side between redirect and callback). */
    public function withFlow(?string $codeVerifier, ?string $nonce): static
    {
        $this->codeVerifier = $codeVerifier ?: null;
        $this->nonce = $nonce ?: null;

        return $this;
    }

    /** The discovery document URL; an issuer URL gets `/.well-known/openid-configuration` appended. */
    public static function discoveryUrlFor(string $url): string
    {
        $url = trim($url);

        if ($url === '' || str_contains($url, '/.well-known/')) {
            return $url;
        }

        return rtrim($url, '/').self::WELL_KNOWN;
    }

    /** @return array<string, mixed> */
    public function discovery(): array
    {
        if ($this->discovery !== null) {
            return $this->discovery;
        }

        if ($this->discoveryUrl === '') {
            throw new OidcException('no discovery URL is configured');
        }

        /** @var array<string, mixed> $document */
        $document = Cache::remember(
            'authprovider:oidc:discovery:'.sha1($this->discoveryUrl),
            max(1, (int) config('authprovider.oidc.discovery_cache_seconds', 3600)),
            function () {
                $document = $this->getJson($this->discoveryUrl);
                foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
                    if (! is_string($document[$key] ?? null) || $document[$key] === '') {
                        throw new OidcException("the discovery document has no {$key}");
                    }
                }

                return $document;
            },
        );

        if ($this->configuredIssuer !== null && rtrim($this->configuredIssuer, '/') !== rtrim($document['issuer'], '/')) {
            throw new OidcException("the discovery document's issuer {$document['issuer']} is not the configured issuer {$this->configuredIssuer}");
        }

        return $this->discovery = $document;
    }

    /** {@inheritdoc} */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase($this->endpoint('authorization_endpoint'), $state);
    }

    /** {@inheritdoc} */
    protected function buildAuthUrlFromBase($url, $state)
    {
        $query = http_build_query($this->getCodeFields($state), '', '&', $this->encodingType);

        return $url.(str_contains($url, '?') ? '&' : '?').$query;
    }

    /** {@inheritdoc} */
    protected function getCodeFields($state = null)
    {
        $fields = parent::getCodeFields($state);

        if (! in_array('openid', $this->getScopes(), true)) {
            $fields['scope'] = trim('openid '.$fields['scope']);
        }
        if ($this->nonce !== null) {
            $fields['nonce'] = $this->nonce;
        }
        if ($this->codeVerifier !== null) {
            $fields['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $this->codeVerifier, true)), '+/', '-_'), '=');
            $fields['code_challenge_method'] = 'S256';
        }

        return $fields;
    }

    /** {@inheritdoc} */
    protected function getTokenUrl()
    {
        return $this->endpoint('token_endpoint');
    }

    /** {@inheritdoc} */
    public function user()
    {
        if ($this->user) {
            return $this->user;
        }

        $code = (string) $this->getCode();
        if ($code === '') {
            throw new OidcException('the callback carries no authorization code');
        }

        $tokens = $this->getAccessTokenResponse($code);

        $idToken = $tokens['id_token'] ?? null;
        if (! is_string($idToken) || $idToken === '') {
            throw new OidcException('the token response carries no id_token');
        }

        $claims = $this->validateIdToken($idToken);

        $accessToken = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : '';
        $info = $accessToken !== '' ? $this->getUserByToken($accessToken) : [];
        if ($info !== [] && (string) ($info['sub'] ?? '') !== $claims['sub']) {
            throw new OidcException('the userinfo response is about another subject');
        }

        $this->user = $this->mapUserToObject(array_merge($claims, $info));

        return $this->user->setToken($accessToken)
            ->setRefreshToken(is_string($tokens['refresh_token'] ?? null) ? $tokens['refresh_token'] : null)
            ->setExpiresIn(is_numeric($tokens['expires_in'] ?? null) ? (int) $tokens['expires_in'] : null)
            ->setApprovedScopes(array_values(array_filter(explode(' ', (string) ($tokens['scope'] ?? '')))));
    }

    /**
     * Code → tokens. The client authenticates the way the discovery document allows:
     * client_secret_post when offered, else client_secret_basic (the OpenID Connect default).
     *
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code)
    {
        $fields = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
        ];
        if ($this->codeVerifier !== null) {
            $fields['code_verifier'] = $this->codeVerifier;
        }

        $request = $this->http();
        if ($this->usesClientSecretPost()) {
            $fields['client_id'] = $this->clientId;
            $fields['client_secret'] = $this->clientSecret;
        } else {
            $request = $request->withHeaders([
                'Authorization' => 'Basic '.base64_encode(urlencode((string) $this->clientId).':'.urlencode((string) $this->clientSecret)),
            ]);
        }

        $url = $this->getTokenUrl();
        $response = $this->send(fn () => $request->asForm()->post($url, $fields), $url);
        if (! $response->successful()) {
            $error = $response->json('error');
            throw new OidcException("the token endpoint answered {$response->status()}".(is_string($error) ? " ({$error})" : ''));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new OidcException('the token endpoint did not answer JSON');
        }

        return $json;
    }

    /**
     * Verified claims of an ID token.
     *
     * @return array<string, mixed>
     */
    public function validateIdToken(string $jwt): array
    {
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            throw new OidcException('the id_token is not a signed JWT');
        }

        $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true);
        if (! is_array($header)) {
            throw new OidcException('the id_token header is not JSON');
        }
        $alg = $header['alg'] ?? null;
        if (! is_string($alg) || ! isset(JWT::$supported_algs[$alg])) {
            throw new OidcException('unsupported id_token algorithm '.json_encode($alg));
        }
        $kid = isset($header['kid']) && is_scalar($header['kid']) ? (string) $header['kid'] : null;

        $key = str_starts_with($alg, 'HS')
            ? new Key((string) $this->clientSecret, $alg)
            : $this->signingKey($kid, $alg);

        $leeway = JWT::$leeway;
        JWT::$leeway = max(0, (int) config('authprovider.oidc.leeway_seconds', 60));
        try {
            $payload = JWT::decode($jwt, $key);
        } catch (\Throwable $e) {
            throw new OidcException('id_token rejected: '.$e->getMessage(), 0, $e);
        } finally {
            JWT::$leeway = $leeway;
        }

        $claims = json_decode((string) json_encode($payload), true);
        if (! is_array($claims)) {
            throw new OidcException('the id_token carries no claims');
        }

        $issuer = $this->discovery()['issuer'];
        if (($claims['iss'] ?? null) !== $issuer) {
            throw new OidcException('id_token issuer '.json_encode($claims['iss'] ?? null)." is not {$issuer}");
        }

        $audience = array_values(array_filter((array) ($claims['aud'] ?? []), 'is_string'));
        if (! in_array($this->clientId, $audience, true)) {
            throw new OidcException('the id_token audience does not contain the client ID');
        }
        $azp = $claims['azp'] ?? null;
        if ((count($audience) > 1 || $azp !== null) && $azp !== $this->clientId) {
            throw new OidcException('the id_token authorized party is not the client ID');
        }

        if (! is_numeric($claims['exp'] ?? null)) {
            throw new OidcException('the id_token has no expiry');
        }

        if (is_int($claims['sub'] ?? null)) {
            $claims['sub'] = (string) $claims['sub'];
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new OidcException('the id_token has no subject');
        }

        if ($this->nonce !== null && ! hash_equals($this->nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new OidcException('the id_token nonce is not the one of this sign-in');
        }

        return $claims;
    }

    /** {@inheritdoc} */
    protected function getUserByToken($token)
    {
        $url = $this->discovery()['userinfo_endpoint'] ?? null;
        if (! is_string($url) || $url === '') {
            return [];
        }

        $this->guard($url);
        $response = $this->send(fn () => $this->http()->withToken($token)->get($url), $url);
        if (! $response->successful()) {
            throw new OidcException("the userinfo endpoint answered {$response->status()}");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new OidcException('the userinfo endpoint did not answer JSON');
        }

        return $json;
    }

    /** {@inheritdoc} */
    protected function mapUserToObject(array $user)
    {
        $email = is_string($user['email'] ?? null) && filter_var($user['email'], FILTER_VALIDATE_EMAIL) ? $user['email'] : null;
        $fullName = trim(($this->claim($user, 'given_name') ?? '').' '.($this->claim($user, 'family_name') ?? ''));

        return (new User)->setRaw($user)->map([
            'id' => (string) $user['sub'],
            'nickname' => $this->claim($user, 'preferred_username'),
            'name' => $this->claim($user, 'name') ?? ($fullName !== '' ? $fullName : $this->claim($user, 'preferred_username')),
            'email' => $email,
            'avatar' => $this->claim($user, 'picture'),
        ]);
    }

    private function usesClientSecretPost(): bool
    {
        $methods = $this->discovery()['token_endpoint_auth_methods_supported'] ?? null;

        return is_array($methods) && in_array('client_secret_post', $methods, true);
    }

    private function signingKey(?string $kid, string $alg): Key
    {
        // A key ID the cached set does not know: the identity provider rotated its keys — refetch once
        foreach ([false, true] as $refresh) {
            $keys = $this->jwks($alg, $refresh);
            if ($kid !== null && isset($keys[$kid])) {
                return $keys[$kid];
            }
            if ($kid === null && count($keys) === 1) {
                return reset($keys);
            }
        }

        throw new OidcException($kid !== null
            ? "the identity provider publishes no signing key with kid {$kid}"
            : 'the id_token names no signing key and the key set holds several');
    }

    /** @return array<string, Key> signing keys of the JWKS by key ID */
    private function jwks(string $alg, bool $refresh): array
    {
        $uri = $this->endpoint('jwks_uri');
        $cacheKey = 'authprovider:oidc:jwks:'.sha1($uri);
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $set = Cache::remember(
            $cacheKey,
            max(1, (int) config('authprovider.oidc.jwks_cache_seconds', 300)),
            fn () => $this->getJson($uri),
        );

        $keys = [];
        foreach ((array) ($set['keys'] ?? []) as $index => $jwk) {
            if (! is_array($jwk) || ($jwk['use'] ?? 'sig') !== 'sig') {
                continue;
            }
            try {
                $key = JWK::parseKey($jwk, $alg);
            } catch (\Throwable) {
                continue; // a key type or curve this library does not read: not the one we need
            }
            if ($key !== null) {
                $keys[isset($jwk['kid']) && is_scalar($jwk['kid']) ? (string) $jwk['kid'] : '#'.$index] = $key;
            }
        }

        return $keys;
    }

    private function endpoint(string $name): string
    {
        $url = (string) $this->discovery()[$name];
        $this->guard($url);

        return $url;
    }

    /** @return array<string, mixed> */
    private function getJson(string $url): array
    {
        $this->guard($url);
        $response = $this->send(fn () => $this->http()->get($url), $url);
        if (! $response->successful()) {
            throw new OidcException("{$url} answered {$response->status()}");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new OidcException("{$url} did not answer JSON");
        }

        return $json;
    }

    /** @param callable(): Response $call */
    private function send(callable $call, string $url): Response
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            throw new OidcException("cannot reach {$url}: ".$e->getMessage(), 0, $e);
        }
    }

    private function http(): PendingRequest
    {
        $request = Http::timeout(max(1, (int) config('authprovider.oidc.timeout', 15)))->acceptJson();

        return $this->publicTargetsOnly ? $request->withOptions(['allow_redirects' => false]) : $request;
    }

    private function guard(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['https', 'http'], true)) {
            throw new OidcException("{$url} is not an http(s) URL");
        }

        if ($this->publicTargetsOnly && ($reason = app(OutboundUrlGuard::class)->check($url)) !== null) {
            throw new OidcException("{$url}: {$reason}");
        }
    }

    /** @param array<string, mixed> $claims */
    private function claim(array $claims, string $name): ?string
    {
        $value = $claims[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
