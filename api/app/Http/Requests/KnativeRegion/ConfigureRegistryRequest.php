<?php

namespace App\Http\Requests\KnativeRegion;

use App\Http\Requests\RegistrySetup\RegistrySetupRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /knative-regions/{id}/registry: the registry of an existing region, with the fields of the
 * region setup (POST /registry-setup/start).
 */
class ConfigureRegistryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return RegistrySetupRequest::registryRules();
    }
}
