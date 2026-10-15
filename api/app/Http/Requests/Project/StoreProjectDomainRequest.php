<?php

namespace App\Http\Requests\Project;

use App\Rules\CustomDomain;
use App\Services\Helpers\DomainName;
use App\Services\ProjectDomainService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /projects/{project}/domains.
 */
class StoreProjectDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('domain'))) {
            $this->merge(['domain' => DomainName::normalize($this->input('domain'))]);
        }
    }

    public function rules(): array
    {
        return [
            'domain' => ['bail', 'required', 'string', 'max:253', new CustomDomain, 'unique:project_domains,domain'],
        ];
    }

    public function messages(): array
    {
        return [
            'domain.unique' => __('api.domain.taken'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $domain = $this->input('domain');
                if ($validator->errors()->has('domain') || ! is_string($domain)) {
                    return;
                }

                if (app(ProjectDomainService::class)->isReserved($domain)) {
                    $validator->errors()->add('domain', __('api.domain.reserved'));
                }
            },
        ];
    }
}
