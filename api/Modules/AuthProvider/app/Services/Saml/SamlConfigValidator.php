<?php

namespace Modules\AuthProvider\Services\Saml;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\AuthProvider\Models\AuthProvider;

/**
 * Validates and normalizes the SAML fields of an auth provider (create: all IdP fields required;
 * update: only the fields sent). Certificates must parse as X.509, the SP key must be an
 * unencrypted PEM key that belongs to the SP certificate when AuthnRequest signing is on.
 */
class SamlConfigValidator
{
    /**
     * @return array<string, mixed> validated SAML attributes, certificates/keys normalized to PEM
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?AuthProvider $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        $data = Validator::make($input, [
            'saml_idp_entity_id' => [$required, 'string', 'max:500'],
            'saml_idp_sso_url' => [$required, 'url:http,https', 'max:1000'],
            'saml_idp_x509_cert' => [$required, 'string', 'max:20000'],
            'saml_email_attribute' => ['nullable', 'string', 'max:255'],
            'saml_name_attribute' => ['nullable', 'string', 'max:255'],
            'saml_sign_requests' => ['sometimes', 'boolean'],
            'saml_sp_x509_cert' => ['nullable', 'string', 'max:20000'],
            'saml_sp_private_key' => ['nullable', 'string', 'max:20000'],
        ])->validate();

        $errors = [];

        if (array_key_exists('saml_idp_x509_cert', $data)) {
            $cert = SamlCertificates::normalizeCertificate($data['saml_idp_x509_cert']);
            if ($cert) {
                $data['saml_idp_x509_cert'] = $cert;
            } else {
                $errors['saml_idp_x509_cert'] = __('api.saml.invalid_certificate');
            }
        }

        if (array_key_exists('saml_sp_x509_cert', $data) && $data['saml_sp_x509_cert'] !== null) {
            $cert = SamlCertificates::normalizeCertificate($data['saml_sp_x509_cert']);
            if ($cert) {
                $data['saml_sp_x509_cert'] = $cert;
            } else {
                $errors['saml_sp_x509_cert'] = __('api.saml.invalid_certificate');
            }
        }

        // An empty key field keeps the stored key (the API never returns it).
        if (($data['saml_sp_private_key'] ?? null) === null) {
            unset($data['saml_sp_private_key']);
        } else {
            $key = SamlCertificates::normalizePrivateKey($data['saml_sp_private_key']);
            if ($key) {
                $data['saml_sp_private_key'] = $key;
            } else {
                $errors['saml_sp_private_key'] = __('api.saml.invalid_private_key');
            }
        }

        $sign = (bool) ($data['saml_sign_requests'] ?? $existing?->saml_sign_requests ?? false);

        if ($sign && ! $errors) {
            $spCert = array_key_exists('saml_sp_x509_cert', $data) ? $data['saml_sp_x509_cert'] : $existing?->saml_sp_x509_cert;
            $spKey = $data['saml_sp_private_key'] ?? $existing?->saml_sp_private_key;

            if (! $spCert) {
                $errors['saml_sp_x509_cert'] = __('api.saml.sp_certificate_required');
            }
            if (! $spKey) {
                $errors['saml_sp_private_key'] = __('api.saml.sp_key_required');
            }
            if ($spCert && $spKey && ! SamlCertificates::keyMatchesCertificate($spCert, $spKey)) {
                $errors['saml_sp_private_key'] = __('api.saml.key_mismatch');
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
