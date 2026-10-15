<?php

namespace Modules\Tenancy\Http\Requests;

use App\Http\Requests\Auth\RegisterRequest;

class TenantRegisterRequest extends RegisterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'tenant_name' => 'sometimes|nullable|string|max:255',
        ]);
    }
}
