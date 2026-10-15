<?php

namespace Tests\Support;

use DOMDocument;
use DOMXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Throwaway SAML identity provider for tests: an RSA key + self-signed certificate created with
 * the openssl functions, and SAML 2.0 Responses whose Assertion it signs (enveloped XML-DSig,
 * RSA-SHA256, exclusive C14N — what real IdPs send).
 */
class SamlTestIdp
{
    public const ENTITY_ID = 'https://idp.example.test/saml/metadata';

    public const SSO_URL = 'https://idp.example.test/saml/sso';

    public string $certificate;

    public string $privateKey;

    public function __construct(string $commonName = 'idp.example.test')
    {
        [$this->certificate, $this->privateKey] = self::keyPair($commonName);
    }

    /** @return array{0: string, 1: string} [certificate PEM, private key PEM] */
    public static function keyPair(string $commonName): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);

        openssl_x509_export($x509, $certificate);
        openssl_pkey_export($key, $privateKey);

        return [$certificate, $privateKey];
    }

    /** Base64 body of the certificate without PEM armor (as it appears in IdP metadata). */
    public function certificateBody(): string
    {
        return trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r", "\n"], '', $this->certificate));
    }

    /**
     * @param array{
     *   in_response_to?: ?string, destination?: string, recipient?: string, audience?: string,
     *   issuer?: string, name_id?: string, name_id_format?: string, attributes?: array<string, string|list<string>>,
     *   issued_at?: int, not_on_or_after?: int, sign?: bool, signing_key?: ?string, signing_cert?: ?string,
     * } $o
     */
    public function response(array $o): string
    {
        $now = $o['issued_at'] ?? time();
        $notAfter = $o['not_on_or_after'] ?? $now + 300;
        $issuer = $o['issuer'] ?? self::ENTITY_ID;
        $irt = $o['in_response_to'] ?? null;
        $irtAttr = $irt !== null ? ' InResponseTo="'.$this->e($irt).'"' : '';
        $format = $o['name_id_format'] ?? 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent';
        $attributes = '';
        foreach ($o['attributes'] ?? [] as $name => $value) {
            // A list = a multi-valued attribute (e.g. the user's groups)
            $values = implode('', array_map(
                fn ($v) => '<saml:AttributeValue>'.$this->e((string) $v).'</saml:AttributeValue>',
                (array) $value,
            ));
            $attributes .= '<saml:Attribute Name="'.$this->e($name).'">'.$values.'</saml:Attribute>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            .' ID="_r'.bin2hex(random_bytes(16)).'" Version="2.0" IssueInstant="'.$this->t($now).'"'
            .' Destination="'.$this->e($o['destination']).'"'.$irtAttr.'>'
            .'<saml:Issuer>'.$this->e($issuer).'</saml:Issuer>'
            .'<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            .'<saml:Assertion ID="_a'.bin2hex(random_bytes(16)).'" Version="2.0" IssueInstant="'.$this->t($now).'">'
            .'<saml:Issuer>'.$this->e($issuer).'</saml:Issuer>'
            .'<saml:Subject>'
            .'<saml:NameID Format="'.$this->e($format).'">'.$this->e($o['name_id'] ?? 'u-7f3a91').'</saml:NameID>'
            .'<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">'
            .'<saml:SubjectConfirmationData NotOnOrAfter="'.$this->t($notAfter).'" Recipient="'.$this->e($o['recipient'] ?? $o['destination']).'"'.$irtAttr.'/>'
            .'</saml:SubjectConfirmation>'
            .'</saml:Subject>'
            .'<saml:Conditions NotBefore="'.$this->t($now - 60).'" NotOnOrAfter="'.$this->t($notAfter).'">'
            .'<saml:AudienceRestriction><saml:Audience>'.$this->e($o['audience']).'</saml:Audience></saml:AudienceRestriction>'
            .'</saml:Conditions>'
            .'<saml:AuthnStatement AuthnInstant="'.$this->t($now).'" SessionIndex="_s'.bin2hex(random_bytes(8)).'">'
            .'<saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext>'
            .'</saml:AuthnStatement>'
            .($attributes !== '' ? '<saml:AttributeStatement>'.$attributes.'</saml:AttributeStatement>' : '')
            .'</saml:Assertion>'
            .'</samlp:Response>';

        if ($o['sign'] ?? true) {
            $xml = $this->signAssertion($xml, $o['signing_key'] ?? $this->privateKey, $o['signing_cert'] ?? $this->certificate);
        }

        return base64_encode($xml);
    }

    private function signAssertion(string $xml, string $key, string $cert): string
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $assertion = $xpath->query('//saml:Assertion')->item(0);
        $issuer = $xpath->query('saml:Issuer', $assertion)->item(0);

        $dsig = new XMLSecurityDSig;
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReference(
            $assertion,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false],
        );

        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $objKey->loadKey($key);

        $dsig->sign($objKey);
        $dsig->add509Cert($cert);
        $dsig->insertSignature($assertion, $issuer->nextSibling);

        return $doc->saveXML();
    }

    private function t(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
