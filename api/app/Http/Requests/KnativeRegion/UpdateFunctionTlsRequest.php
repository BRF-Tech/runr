<?php

namespace App\Http\Requests\KnativeRegion;

use App\Rules\Pem;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFunctionTlsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => 'required|string|in:custom,console',
            'certificate' => ['required_if:mode,custom', 'nullable', 'string', new Pem('CERTIFICATE')],
            'privateKey' => ['required_if:mode,custom', 'nullable', 'string', new Pem('PRIVATE KEY')],
            // Replace a Traefik TLSStore "default" in the region's Serving namespace that RunR did not create
            'replace_existing' => 'sometimes|boolean',
        ];
    }
}
