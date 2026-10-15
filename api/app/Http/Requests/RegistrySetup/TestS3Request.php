<?php

namespace App\Http\Requests\RegistrySetup;

use Illuminate\Foundation\Http\FormRequest;

class TestS3Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kubeconfig' => 'required|string',
            's3_config' => 'required|array',
            's3_config.endpoint' => 'required|string',
            's3_config.bucket' => 'required|string',
            's3_config.region' => 'required|string',
            's3_config.access_key' => 'required|string',
            's3_config.secret_key' => 'required|string',
            'namespace' => 'sometimes|string|max:63',
        ];
    }
}
