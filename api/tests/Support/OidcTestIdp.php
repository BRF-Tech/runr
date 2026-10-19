<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Throwaway OpenID Connect identity provider for tests, behind Http::fake(): discovery document,
 * JWKS, token endpoint (checks the authorization code, the client authentication and the PKCE
 * verifier like a real one) and userinfo. ID tokens are RS256 JWTs signed with RSA keys created
 * with the openssl functions.
 *
 * A test starts the sign-in through RunR, hands the authorization URL to authorize() (which reads
 * the nonce and the PKCE challenge, as the IdP's login page would) and then calls RunR's callback
 * with CODE and the state.
 */
class OidcTestIdp
{
    public const ISSUER = 'https://idp.example.test';

    public const DISCOVERY_URL = self::ISSUER.'/.well-known/openid-configuration';

    public const CODE = 'auth-code-1';

    public const ACCESS_TOKEN = 'access-token-1';

    /** @var array<string, array{private: string, jwk: array<string, string>}> kid → key */
    private static array $keys = [];

    /** Key IDs the JWKS publishes, and the one the next ID token is signed with */
    public array $published = ['key-1'];

    public string $signingKid = 'key-1';

    /** Private key the next ID token is signed with instead of the signing kid's (a forgery) */
    public ?string $signingKey = null;

    /** Claims of the next ID token on top of iss / aud / sub / iat / exp / nonce */
    public array $claims = [];

    /** Last change to the next ID token's claims (e.g. a wrong issuer) */
    public ?\Closure $tamper = null;

    /** Claims the userinfo endpoint adds; null: the discovery document names no userinfo endpoint */
    public ?array $userinfo = [];

    /** token_endpoint_auth_methods_supported */
    public array $authMethods = ['client_secret_post', 'client_secret_basic'];

    public string $issuer = self::ISSUER;

    /** The client ID the ID token is issued to (audience) */
    public string $clientId = 'runr-console';

    public string $clientSecret = 'secret-of-the-test-client-0123456789abcdef';

    public ?string $nonce = null;

    public ?string $codeChallenge = null;

    /** @var list<Request> */
    public array $tokenRequests = [];

    public int $jwksFetches = 0;

    public function fake(): void
    {
        Http::fake([
            self::DISCOVERY_URL => fn () => Http::response($this->discovery()),
            self::ISSUER.'/jwks' => function () {
                $this->jwksFetches++;

                return Http::response(['keys' => array_map(fn (string $kid) => self::key($kid)['jwk'], $this->published)]);
            },
            self::ISSUER.'/token' => fn (Request $request) => $this->token($request),
            self::ISSUER.'/userinfo' => function (Request $request) {
                if ($request->header('Authorization') !== ['Bearer '.self::ACCESS_TOKEN]) {
                    return Http::response(['error' => 'invalid_token'], 401);
                }

                return Http::response(array_merge(['sub' => $this->subject()], (array) $this->userinfo));
            },
        ]);
    }

    /** @return array<string, mixed> */
    public function discovery(): array
    {
        return array_filter([
            'issuer' => $this->issuer,
            'authorization_endpoint' => self::ISSUER.'/authorize',
            'token_endpoint' => self::ISSUER.'/token',
            'userinfo_endpoint' => $this->userinfo === null ? null : self::ISSUER.'/userinfo',
            'jwks_uri' => self::ISSUER.'/jwks',
            'response_types_supported' => ['code'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => $this->authMethods,
        ]);
    }

    /**
     * The IdP's side of the authorization request: remembers nonce and PKCE challenge.
     *
     * @return array<string, string> the query of the authorization URL
     */
    public function authorize(string $authorizationUrl): array
    {
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $this->nonce = $query['nonce'] ?? null;
        $this->codeChallenge = $query['code_challenge'] ?? null;

        return $query;
    }

    /** @param array<string, mixed> $claims */
    public function idToken(array $claims, ?string $kid = null, ?string $privateKey = null): string
    {
        $kid ??= $this->signingKid;

        return JWT::encode($claims, $privateKey ?? $this->signingKey ?? self::key($kid)['private'], 'RS256', $kid);
    }

    /** A key pair outside the published set (for forged tokens) */
    public static function strangerKey(): string
    {
        return self::newKey('stranger')['private'];
    }

    private function token(Request $request)
    {
        $this->tokenRequests[] = $request;
        $data = $request->data();

        if (($data['code'] ?? null) !== self::CODE) {
            return Http::response(['error' => 'invalid_grant'], 400);
        }

        // Client authentication: client_secret_post or client_secret_basic
        $basic = 'Basic '.base64_encode(urlencode($this->clientId).':'.urlencode($this->clientSecret));
        $viaPost = ($data['client_id'] ?? null) === $this->clientId && ($data['client_secret'] ?? null) === $this->clientSecret;
        if (! $viaPost && $request->header('Authorization') !== [$basic]) {
            return Http::response(['error' => 'invalid_client'], 401);
        }

        // PKCE (RFC 7636, S256)
        if ($this->codeChallenge !== null) {
            $expected = rtrim(strtr(base64_encode(hash('sha256', (string) ($data['code_verifier'] ?? ''), true)), '+/', '-_'), '=');
            if (! hash_equals($this->codeChallenge, $expected)) {
                return Http::response(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed'], 400);
            }
        }

        $claims = array_merge([
            'iss' => $this->issuer,
            'aud' => $this->clientId,
            'sub' => $this->subject(),
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => $this->nonce,
        ], $this->claims);
        if ($this->tamper) {
            $claims = ($this->tamper)($claims);
        }

        return Http::response([
            'access_token' => self::ACCESS_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'refresh-token-1',
            'scope' => 'openid email profile',
            'id_token' => $this->idToken(array_filter($claims, fn ($v) => $v !== null)),
        ]);
    }

    private function subject(): string
    {
        return (string) ($this->claims['sub'] ?? 'user-123');
    }

    /** @return array{private: string, jwk: array<string, string>} */
    private static function key(string $kid): array
    {
        return self::$keys[$kid] ??= self::newKey($kid);
    }

    /** @return array{private: string, jwk: array<string, string>} */
    private static function newKey(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $rsa = openssl_pkey_get_details($key)['rsa'];

        return [
            'private' => $private,
            'jwk' => [
                'kty' => 'RSA',
                'kid' => $kid,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => JWT::urlsafeB64Encode($rsa['n']),
                'e' => JWT::urlsafeB64Encode($rsa['e']),
            ],
        ];
    }
}
