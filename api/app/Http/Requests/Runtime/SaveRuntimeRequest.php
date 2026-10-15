<?php

namespace App\Http\Requests\Runtime;

use App\Rules\ConfigMapKey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Register (POST) or change (PUT) a runtime: the manifest text (YAML or JSON), the files uploaded
 * with it (Dockerfile and build context of dockerfile versions; ConfigMap-sized), the regions it
 * is offered in (null = all) and — on update — whether it is enabled. The manifest itself is
 * validated by ManifestValidator (JSON Schema + rules), which answers with JSON-pointer problems.
 */
class SaveRuntimeRequest extends FormRequest
{
    /** Uploaded files land in a ConfigMap (1 MiB) next to nothing else */
    public const MAX_FILES_BYTES = 900 * 1024;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'manifest' => [$creating ? 'required' : 'sometimes', 'string', 'max:262144'],
            'files' => ['sometimes', 'nullable', 'array', 'max:50'],
            'files.*' => ['string'],
            'regions' => ['sometimes', 'nullable', 'array'],
            'regions.*' => ['string', 'exists:knative_regions,id'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $files = $this->input('files');
            if (! is_array($files)) {
                return;
            }

            $keyRule = new ConfigMapKey;
            $total = 0;
            foreach ($files as $name => $content) {
                $keyRule->validate('files', (string) $name, function ($message) use ($validator, $name) {
                    $validator->errors()->add('files', __('api.runtimes.file_name_invalid', ['name' => $name]));
                });
                $total += strlen((string) $content);
            }

            if ($total > self::MAX_FILES_BYTES) {
                $validator->errors()->add('files', __('api.runtimes.files_too_large', ['kb' => intdiv(self::MAX_FILES_BYTES, 1024)]));
            }
        });
    }

    /** @return array<string, string>|null null = not sent */
    public function files(): ?array
    {
        if (! $this->has('files')) {
            return null;
        }

        return array_map('strval', (array) ($this->input('files') ?? []));
    }
}
