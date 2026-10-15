<?php

namespace App\Http\Requests\Code;

use App\Rules\ConfigMapKey;
use Illuminate\Foundation\Http\FormRequest;

class SaveFaaSFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files' => 'required|array|min:1',
            // Each file becomes a key of the project's ConfigMap (flat, no directories)
            'files.*.name' => ['required', 'string', 'distinct', new ConfigMapKey],
            'files.*.content' => 'required|string',
            'message' => 'nullable|string|max:255',
        ];
    }

    /**
     * The saved set replaces the version's files (GitService::commitFiles removes the others) and
     * all of them go into one ConfigMap (1 MiB Kubernetes limit): names + contents < 1,000,000 bytes.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $files = $this->input('files');
            if (! is_array($files)) {
                return;
            }

            $bytes = 0;
            foreach ($files as $file) {
                $bytes += strlen((string) ($file['name'] ?? '')) + strlen(is_string($file['content'] ?? null) ? $file['content'] : '');
            }

            if ($bytes > SaveXaaSCodeRequest::MAX_CODE_BYTES) {
                $validator->errors()->add('files', __('api.code.too_large', [
                    'max' => number_format(SaveXaaSCodeRequest::MAX_CODE_BYTES),
                    'size' => number_format($bytes),
                ]));
            }
        });
    }
}
