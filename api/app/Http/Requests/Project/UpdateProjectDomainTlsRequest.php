<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectDomain;
use App\Rules\Pem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /projects/{project}/domains/{domain}/tls. Same field names and PEM
 * rules as the region function-domain TLS setting (UpdateFunctionTlsRequest).
 */
class UpdateProjectDomainTlsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => 'required|string|in:'.implode(',', [
                ProjectDomain::TLS_NONE, ProjectDomain::TLS_CUSTOM, ProjectDomain::TLS_CERT_MANAGER,
            ]),
            'certificate' => ['required_if:mode,custom', 'nullable', 'string', new Pem('CERTIFICATE')],
            'privateKey' => ['required_if:mode,custom', 'nullable', 'string', new Pem('PRIVATE KEY')],
        ];
    }
}
