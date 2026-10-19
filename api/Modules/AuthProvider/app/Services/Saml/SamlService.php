<?php

namespace Modules\AuthProvider\Services\Saml;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Services\OAuthService;
use Modules\AuthProvider\Services\ProviderConfigService;
use Modules\AuthProvider\Services\TenantContext;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;

/**
 * — SAML 2.0 SP-initiated sign-in (onelogin/php-saml):
 *   AuthnRequest over HTTP-Redirect (optionally signed), Response over HTTP-POST to the ACS.
 *
 * The ACS accepts only a Response that answers an AuthnRequest issued here (InResponseTo, single
 * use, cached for authprovider.saml.request_ttl_minutes), is addressed to this ACS (Destination,
 * SubjectConfirmationData Recipient), carries this SP as Audience, is within its validity window
 * (NotBefore / NotOnOrAfter, 180 s clock drift), comes from the configured IdP (Issuer) and has
 * an Assertion signed by the configured IdP certificate. The subject then goes through the same
 * user resolution / provisioning / token path as the OAuth callback.
 */
class SamlService
{
    private const REQUEST_CACHE_PREFIX = 'authprovider:saml:request:';

    public function __construct(
        protected OAuthService $oauthService
    ) {}

    /**
     * SP endpoints to register at the IdP. In a tenant context they carry `?tenant=<slug>` so the
     * IdP's POST and metadata fetch reach the tenant's own provider row.
     *
     * @return array{entity_id: string, acs_url: string, metadata_url: string, login_url: string}
     */
    public function spUrls(AuthProvider $provider, ?string $tenantSlug = null): array
    {
        $tenantSlug ??= $this->currentTenantSlug();

        $root = rtrim((string) (config('authprovider.saml.sp_base_url') ?: config('app.url') ?: url('/')), '/');
        $base = "{$root}/api/saml/{$provider->getKey()}";
        $query = $tenantSlug ? '?'.http_build_query(['tenant' => $tenantSlug]) : '';

        return [
            'entity_id' => "{$base}/metadata{$query}",
            'acs_url' => "{$base}/acs{$query}",
            'metadata_url' => "{$base}/metadata{$query}",
            'login_url' => "{$base}/login{$query}",
        ];
    }

    public function currentTenantSlug(): ?string
    {
        return app(TenantContext::class)->currentSlug();
    }

    /** onelogin/php-saml settings for one provider. */
    public function settings(AuthProvider $provider, ?string $tenantSlug): array
    {
        $urls = $this->spUrls($provider, $tenantSlug);
        $sign = (bool) $provider->saml_sign_requests;

        return [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $urls['entity_id'],
                'assertionConsumerService' => [
                    'url' => $urls['acs_url'],
                    'binding' => Constants::BINDING_HTTP_POST,
                ],
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
                'x509cert' => $sign ? (string) $provider->saml_sp_x509_cert : '',
                'privateKey' => $sign ? (string) $provider->saml_sp_private_key : '',
            ],
            'idp' => [
                'entityId' => (string) $provider->saml_idp_entity_id,
                'singleSignOnService' => [
                    'url' => (string) $provider->saml_idp_sso_url,
                    'binding' => Constants::BINDING_HTTP_REDIRECT,
                ],
                'x509cert' => (string) $provider->saml_idp_x509_cert,
            ],
            'security' => [
                'authnRequestsSigned' => $sign,
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => false,
                'wantAssertionsEncrypted' => false,
                'wantNameId' => true,
                'wantXMLValidation' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'destinationStrictlyMatches' => false,
                'relaxDestinationValidation' => false,
                'requestedAuthnContext' => false,
                'signMetadata' => false,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ];
    }

    public function metadata(AuthProvider $provider, ?string $tenantSlug): string
    {
        $settings = new Settings($this->settings($provider, $tenantSlug), true);
        $xml = $settings->getSPMetadata(false, null, null, true);

        $errors = $settings->validateMetadata($xml);
        if (! empty($errors)) {
            throw new \RuntimeException('Invalid SP metadata: '.implode(', ', $errors));
        }

        return $xml;
    }

    /** IdP URL carrying the (optionally signed) AuthnRequest; its ID is remembered for the ACS. */
    public function loginUrl(AuthProvider $provider, ?string $tenantSlug): string
    {
        $auth = new Auth($this->settings($provider, $tenantSlug));

        $relayState = (string) (config('app.frontend_url') ?: '/');
        $url = $auth->login($relayState, [], false, false, true);

        Cache::put(
            self::REQUEST_CACHE_PREFIX.$auth->getLastRequestID(),
            ['provider' => $provider->getKey(), 'tenant' => $tenantSlug],
            now()->addMinutes((int) config('authprovider.saml.request_ttl_minutes', 10)),
        );

        return $url;
    }

    /**
     * Validate a base64 SAMLResponse posted to the ACS and resolve the user.
     *
     * @return array{user: User, token: string}|array{error: string}
     */
    public function handleResponse(AuthProvider $provider, ?string $tenantSlug, string $samlResponse): array
    {
        try {
            $settings = new Settings($this->settings($provider, $tenantSlug));
            $response = new Response($settings, $samlResponse);
        } catch (\Throwable $e) {
            return $this->reject($provider, 'unparsable response: '.$e->getMessage());
        }

        $root = $response->document?->documentElement;
        $requestId = $root?->hasAttribute('InResponseTo') ? $root->getAttribute('InResponseTo') : null;

        // Single use: a replayed or unsolicited response finds no pending request.
        $pending = $requestId ? Cache::pull(self::REQUEST_CACHE_PREFIX.$requestId) : null;
        if (! is_array($pending)
            || ($pending['provider'] ?? null) !== $provider->getKey()
            || ($pending['tenant'] ?? null) !== $tenantSlug) {
            return $this->reject($provider, 'no pending AuthnRequest for InResponseTo='.($requestId ?? 'none'), 'api.saml.unknown_request');
        }

        $acsUrl = $this->spUrls($provider, $tenantSlug)['acs_url'];

        try {
            $valid = $this->withSelfUrl($acsUrl, fn () => $response->isValid($requestId));
        } catch (\Throwable $e) {
            return $this->reject($provider, $e->getMessage());
        }

        if (! $valid) {
            return $this->reject($provider, (string) $response->getError(false));
        }

        return $this->oauthService->resolveExternalUser(
            $provider,
            AuthProvider::DRIVER_SAML,
            $this->mapUser($provider, $response),
        );
    }

    public function mapUser(AuthProvider $provider, Response $response): SamlUser
    {
        $nameId = trim((string) $response->getNameId());
        $attributes = $response->getAttributes();
        $friendly = $response->getAttributesWithFriendlyName();

        $attr = function (string $name) use ($attributes, $friendly, $nameId): ?string {
            if (strcasecmp($name, 'NameID') === 0) {
                return $nameId !== '' ? $nameId : null;
            }
            foreach ([$attributes, $friendly] as $set) {
                foreach ($set as $key => $values) {
                    if (strcasecmp((string) $key, $name) === 0) {
                        $value = trim((string) (is_array($values) ? ($values[0] ?? '') : $values));
                        if ($value !== '') {
                            return $value;
                        }
                    }
                }
            }

            return null;
        };

        $email = $provider->saml_email_attribute
            ? $attr($provider->saml_email_attribute)
            : ($attr('email')
                ?? $attr('mail')
                ?? $attr('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress')
                ?? $attr('urn:oid:0.9.2342.19200300.100.1.3')
                ?? $attr('NameID'));

        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = null;
        }

        $name = $provider->saml_name_attribute
            ? $attr($provider->saml_name_attribute)
            : ($attr('displayName')
                ?? $attr('name')
                ?? $attr('cn')
                ?? $attr('http://schemas.microsoft.com/identity/claims/displayname')
                ?? $attr('urn:oid:2.16.840.1.113730.3.1.241')
                ?? $this->joinNames(
                    $attr('givenName') ?? $attr('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname'),
                    $attr('sn') ?? $attr('surname') ?? $attr('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname'),
                ));

        return (new SamlUser)->map([
            'id' => $this->subjectId($provider, $nameId),
            'email' => $email,
            'name' => $name,
            'avatar' => null,
        ])->setRaw([
            'name_id' => $nameId,
            'name_id_format' => $response->getNameIdFormat(),
            'attributes' => $attributes,
            // Group attributes are often sent with a FriendlyName (SSO group mapping)
            'friendly_attributes' => $friendly,
        ]);
    }

    /** Social account key: the NameID scoped to this IdP configuration (fits the 255-char column). */
    public function subjectId(AuthProvider $provider, string $nameId): string
    {
        return ProviderConfigService::scoped((string) $provider->getKey(), $nameId);
    }

    /**
     * php-saml derives "the URL this response arrived at" (Destination / Recipient checks) from
     * $_SERVER and static state. Pin it to the configured ACS URL for the duration of the check —
     * independent of Host / X-Forwarded-* headers and safe for long-running (Octane) workers.
     */
    private function withSelfUrl(string $acsUrl, callable $callback): mixed
    {
        $parts = parse_url($acsUrl);
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'localhost')
            .(isset($parts['port']) ? ':'.$parts['port'] : '').$dir;

        $keys = ['REQUEST_URI', 'QUERY_STRING', 'SCRIPT_NAME', 'PATH_INFO'];
        $backup = array_intersect_key($_SERVER, array_flip($keys));

        try {
            Utils::setBaseURL($base);
            $_SERVER['REQUEST_URI'] = $path;
            $_SERVER['SCRIPT_NAME'] = $path;
            unset($_SERVER['QUERY_STRING'], $_SERVER['PATH_INFO']);

            return $callback();
        } finally {
            Utils::setBaseURL('');
            foreach ($keys as $key) {
                if (array_key_exists($key, $backup)) {
                    $_SERVER[$key] = $backup[$key];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }

    private function joinNames(?string $given, ?string $family): ?string
    {
        $full = trim(($given ?? '').' '.($family ?? ''));

        return $full !== '' ? $full : null;
    }

    private function reject(AuthProvider $provider, string $reason, string $messageKey = 'api.saml.invalid_response'): array
    {
        Log::warning('SAML response rejected', [
            'provider' => $provider->getKey(),
            'reason' => $reason,
        ]);

        return ['error' => __($messageKey)];
    }
}
