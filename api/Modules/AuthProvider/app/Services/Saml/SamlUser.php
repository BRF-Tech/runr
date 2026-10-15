<?php

namespace Modules\AuthProvider\Services\Saml;

use Laravel\Socialite\AbstractUser;

/**
 * A SAML-authenticated subject shaped like a Socialite user, so the ACS goes through the exact
 * user resolution / provisioning / token path of the OAuth callback (OAuthCallbackTrait).
 * There are no OAuth tokens: the social account row keeps an empty access token.
 */
class SamlUser extends AbstractUser
{
    public string $token = '';

    public ?string $refreshToken = null;

    public ?int $expiresIn = null;
}
