<?php

namespace Modules\AuthProvider\Services\Socialite;

use Laravel\Socialite\Two\GoogleProvider as BaseGoogleProvider;

/**
 * Google with a hosted domain: the authorization request carries `hd`, so Google offers only
 * accounts of that Workspace domain. The parameter is a hint a user can strip from the URL —
 * the callback checks the `hd` claim of the returned user (OAuthCallbackTrait).
 */
class GoogleProvider extends BaseGoogleProvider
{
    protected ?string $hostedDomain = null;

    public function hostedDomain(?string $domain): static
    {
        $domain = trim((string) $domain);
        $this->hostedDomain = $domain !== '' ? $domain : null;

        return $this;
    }

    /** {@inheritdoc} */
    protected function getCodeFields($state = null)
    {
        $fields = parent::getCodeFields($state);

        if ($this->hostedDomain !== null) {
            $fields['hd'] = $this->hostedDomain;
        }

        return $fields;
    }
}
