<?php

namespace App\Rules;

use App\Services\Helpers\PemHelper;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * A PEM-encoded certificate or private key — plain PEM or base64-wrapped, the same
 * input shape the Helm chart accepts for ingress.tls.mode=custom (runr.pemB64). Used
 * to validate the region-level function-domain TLS setting.
 */
class Pem implements ValidationRule
{
    /**
     * @param  string  $marker  the PEM block marker this value must contain, e.g.
     *                          "CERTIFICATE" or "PRIVATE KEY"
     */
    public function __construct(private readonly string $marker) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('api.region.function_tls_pem_invalid', ['marker' => $this->marker]));

            return;
        }

        try {
            PemHelper::normalize($value, $this->marker);
        } catch (InvalidArgumentException) {
            $fail(__('api.region.function_tls_pem_invalid', ['marker' => $this->marker]));
        }
    }
}
