<?php

namespace Modules\AuthProvider\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AuthProvider extends Model
{
    use HasUlids;

    public const DRIVER_SAML = 'saml';

    protected $fillable = [
        'name',
        'driver',
        'client_id',
        'client_secret',
        'scopes',
        'oidc_discovery_url',
        'oidc_issuer',
        'google_hosted_domain',
        'microsoft_tenant',
        'gitlab_instance_uri',
        'saml_idp_entity_id',
        'saml_idp_sso_url',
        'saml_idp_x509_cert',
        'saml_email_attribute',
        'saml_name_attribute',
        'saml_sign_requests',
        'saml_sp_x509_cert',
        'saml_sp_private_key',
        'is_enabled',
        'auto_register',
        'display_order',
        // SSO group mapping (docs/permissions.md)
        'groups_attribute',
        'default_role',
    ];

    /** Claim / SAML attribute names the user's groups are read from when none is configured. */
    public const DEFAULT_GROUPS_ATTRIBUTES = [
        'groups',
        'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups',
        'memberOf',
    ];

    /**
     * Secrets never leave the API: the OAuth client secret and the SP signing key are
     * write-only (AuthProviderController reports whether one is stored).
     */
    protected $hidden = [
        'client_secret',
        'saml_sp_private_key',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'saml_sp_private_key' => 'encrypted',
            'scopes' => 'array',
            'is_enabled' => 'boolean',
            'auto_register' => 'boolean',
            'saml_sign_requests' => 'boolean',
        ];
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    public function isSaml(): bool
    {
        return $this->driver === self::DRIVER_SAML;
    }

    public function hasSamlSpPrivateKey(): bool
    {
        return ! empty($this->attributes['saml_sp_private_key'] ?? null);
    }
}
