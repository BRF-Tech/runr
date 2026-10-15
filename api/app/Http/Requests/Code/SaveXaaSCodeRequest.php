<?php

namespace App\Http\Requests\Code;

use App\Rules\MaxBytes;
use Illuminate\Foundation\Http\FormRequest;

class SaveXaaSCodeRequest extends FormRequest
{
    /**
     * The code is deployed as a ConfigMap; Kubernetes caps a ConfigMap at 1 MiB (data + metadata),
     * so the code itself stays below 1,000,000 bytes.
     */
    public const MAX_CODE_BYTES = 1_000_000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', new MaxBytes(self::MAX_CODE_BYTES)],
            'message' => 'nullable|string|max:255',
        ];
    }
}
